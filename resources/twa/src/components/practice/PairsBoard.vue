<script setup lang="ts">
/**
 * "Find the pairs" (docs/15 §3.1, docs/16 §11.3): the English words in the left column, their translations in the right one.
 *
 * - `element` — the round: the words, the translations (shuffled apart), `matched` (the pairs already put together; they are
 *   gone from the board, a reload brings them back as they were), `mismatches`.
 * - `mode` — `solo`: every match is sent at once, `answer` with `{ left_id, right_id }`; `battle`: the matches are collected
 *   (and may be changed) and sent together by "Check", ONE `answer` with `{ pairs }`.
 * - `disabled` — an answer is on its way: nothing reacts.
 * Events: `answer` (the payload of the mode).
 *
 * A match can be started from either side: a tap picks a card, a tap on the picked card lets it go, a tap on a card of the same
 * side moves the pick, a tap on a card of the other side makes the pair. It is the server that says whether a pair is right
 * (solo): the board learns it from the new `element` — a pair in `matched` was right, one more in `mismatches` was wrong — and
 * shows it: a right pair is green with a check for ~0.4 s and leaves, a wrong one is red with a cross for ~0.6 s with an error
 * haptic; the right answer of a wrong pair is NOT shown. While a match is on its way the board takes no other tap.
 *
 * Every state is a colour, a symbol AND a word (read by a screen reader inside the card, and said in the live line under the
 * columns); the cards are real buttons (Tab, Enter, Space; Escape lets the pick go), at least 56 px high, and a long
 * translation wraps instead of being cut off.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { modeTitle } from '@/composables/practiceLabels'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { PairsRoundElement, PracticeAnswerPayload, PracticeChoice } from '@/api/types'

const props = defineProps<{
  element: PairsRoundElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload] }>()

const { t, tn } = useI18n()
const { haptic, notificationHaptic } = useTelegram()

/** How long the verdict of a pair stays on the cards (docs/16 §11.3). */
const CORRECT_MS = 400
const WRONG_MS = 600

type Side = 'left' | 'right'
type Look = 'idle' | 'selected' | 'correct' | 'incorrect' | 'gone'

interface Match {
  left: string
  right: string
}

interface Card {
  id: string
  text: string
  look: Look
  /** Battle: the number of the pair the card is in. */
  number: number | null
}

const solo = computed(() => props.mode === 'solo')

/** The card waiting for its other half. */
const picked = ref<{ side: Side; id: string } | null>(null)
/** Solo: the match that is on its way. */
const sent = ref<Match | null>(null)
/** Solo: the last match sent; a wrong answer names it. */
const tried = ref<Match | null>(null)
/** The verdict of a pair on the cards. */
const flash = ref<(Match & { kind: 'correct' | 'wrong' }) | null>(null)
/** Battle: the pairs put together so far (left id -> right id) and the number each one carries. */
const chosen = ref<Record<string, string>>({})
const numbers = ref<Record<string, number>>({})
/** Battle: the answer is out; it is final. */
const submitted = ref(false)

const board = ref<HTMLElement | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
let seenPairs: string[] = props.element.matched.map(([left]) => left)
let seenMismatches = props.element.mismatches

// ---- what the board shows ----

/** Solo: the pairs that are done. In a battle nothing is done before the answer. */
const matchedPairs = computed(() => (solo.value ? props.element.matched : []))
const doneLeft = computed(() => new Set(matchedPairs.value.map(([left]) => left)))
const doneRight = computed(() => new Set(matchedPairs.value.map(([, right]) => right)))

const isDone = (side: Side, id: string): boolean => (side === 'left' ? doneLeft.value : doneRight.value).has(id)

/** Battle: the id of the card on the other side that `id` is paired with. */
function partnerOf(side: Side, id: string): string | null {
  if (side === 'left') return chosen.value[id] ?? null
  return Object.keys(chosen.value).find((left) => chosen.value[left] === id) ?? null
}

function numberOf(side: Side, id: string): number | null {
  const left = side === 'left' ? id : partnerOf('right', id)
  return left === null ? null : (numbers.value[left] ?? null)
}

function lookOf(side: Side, id: string): Look {
  const shown = flash.value
  if (shown !== null && (side === 'left' ? shown.left : shown.right) === id) return shown.kind === 'correct' ? 'correct' : 'incorrect'
  if (isDone(side, id)) return 'gone'
  if (sent.value !== null && (side === 'left' ? sent.value.left : sent.value.right) === id) return 'selected'
  if (picked.value?.side === side && picked.value.id === id) return 'selected'
  if (!solo.value && partnerOf(side, id) !== null) return 'selected'
  return 'idle'
}

function cardOf(side: Side, choice: PracticeChoice): Card {
  return { id: choice.id, text: choice.text, look: lookOf(side, choice.id), number: solo.value ? null : numberOf(side, choice.id) }
}

const columns = computed(() => [
  { side: 'left' as const, label: t('practice.pairs.words'), cards: props.element.left.map((choice) => cardOf('left', choice)) },
  { side: 'right' as const, label: t('practice.pairs.translations'), cards: props.element.right.map((choice) => cardOf('right', choice)) },
])

const textOf = (side: Side, id: string): string => (side === 'left' ? props.element.left : props.element.right).find((card) => card.id === id)?.text ?? ''

const total = computed(() => props.element.left.length)
const joined = computed(() => Object.keys(chosen.value).length)
const complete = computed(() => total.value > 0 && joined.value === total.value)

const counter = computed(() =>
  solo.value ? tn('practice.pairs.left', Math.max(0, total.value - matchedPairs.value.length)) : t('practice.pairs.joined', { done: joined.value, total: total.value }),
)

/** The live line under the columns: the verdict of the last pair, else what to do next. */
const status = computed((): { tone: 'hint' | 'success' | 'danger'; text: string } => {
  const shown = flash.value
  if (shown !== null) {
    return shown.kind === 'correct'
      ? { tone: 'success', text: t('practice.pairs.correct', { word: textOf('left', shown.left), translation: textOf('right', shown.right) }) }
      : { tone: 'danger', text: t('practice.pairs.wrong') }
  }
  if (sent.value !== null) return { tone: 'hint', text: t('practice.pairs.checking') }
  if (submitted.value) return { tone: 'hint', text: t('practice.pairs.sent') }
  const pick = picked.value
  if (pick !== null) {
    return {
      tone: 'hint',
      text: pick.side === 'left' ? t('practice.pairs.pick_translation', { text: textOf('left', pick.id) }) : t('practice.pairs.pick_word', { text: textOf('right', pick.id) }),
    }
  }
  if (!solo.value && complete.value) return { tone: 'hint', text: t('practice.pairs.ready') }
  return { tone: 'hint', text: solo.value ? t('practice.pairs.hint_solo') : t('practice.pairs.hint_battle') }
})

const TONE_CLASS = { hint: 'text-tg-subtitle', success: 'text-success-ink', danger: 'text-danger-ink' } as const

/** The word a screen reader adds to a card besides its text. */
function stateWord(card: Card): string {
  if (card.number !== null) return t('practice.pairs.state.paired', { number: card.number })
  if (card.look === 'selected') return t('practice.pairs.state.selected')
  if (card.look === 'correct') return t('practice.pairs.state.correct')
  if (card.look === 'incorrect') return t('practice.pairs.state.wrong')
  return ''
}

// ---- taps ----

function tap(side: Side, id: string): void {
  if (props.disabled || submitted.value || isDone(side, id)) return
  dropFlash()
  if (solo.value) tapSolo(side, id)
  else tapBattle(side, id)
}

function tapSolo(side: Side, id: string): void {
  // One match at a time: the next tap waits for the answer to this one.
  if (sent.value !== null) return

  const pick = picked.value
  if (pick === null || pick.side === side) {
    picked.value = pick?.side === side && pick.id === id ? null : { side, id }
    return
  }

  const match: Match = side === 'right' ? { left: pick.id, right: id } : { left: id, right: pick.id }
  picked.value = null
  sent.value = match
  tried.value = match
  emit('answer', { left_id: match.left, right_id: match.right })
}

function tapBattle(side: Side, id: string): void {
  const pick = picked.value
  if (pick?.side === side && pick.id === id) {
    picked.value = null
    return
  }

  if (pick !== null && pick.side !== side) {
    // A card that is paired already is taken over: its old pair breaks up.
    unpair(side, id)
    pair(side === 'right' ? pick.id : id, side === 'right' ? id : pick.id)
    picked.value = null
    haptic('light')
    return
  }

  // Nothing picked, or a card of the same side: a paired card is let go of and picked up again.
  if (unpair(side, id)) haptic('light')
  picked.value = { side, id }
}

/** The lowest number no pair carries yet: the pairs of a board are 1, 2, 3, 4 whatever order they were made in. */
function pair(left: string, right: string): void {
  const used = new Set(Object.values(numbers.value))
  let number = 1
  while (used.has(number)) number++
  chosen.value = { ...chosen.value, [left]: right }
  numbers.value = { ...numbers.value, [left]: number }
}

/** Breaks up the pair a card is in; false when it was in none. */
function unpair(side: Side, id: string): boolean {
  const left = side === 'left' ? id : partnerOf('right', id)
  if (left === null || !(left in chosen.value)) return false
  chosen.value = without(chosen.value, left)
  numbers.value = without(numbers.value, left)
  return true
}

function without<T>(record: Record<string, T>, key: string): Record<string, T> {
  const rest = { ...record }
  delete rest[key]
  return rest
}

function cancelPick(): void {
  if (picked.value !== null && sent.value === null) picked.value = null
}

function check(): void {
  if (solo.value || !complete.value || props.disabled || submitted.value) return
  submitted.value = true
  emit('answer', { pairs: { ...chosen.value } })
}

// ---- the answer of the server ----

function clearTimer(): void {
  if (timer !== null) clearTimeout(timer)
  timer = null
}

function show(next: Match & { kind: 'correct' | 'wrong' }, ms: number): void {
  clearTimer()
  flash.value = next
  timer = setTimeout(() => {
    timer = null
    dropFlash()
  }, ms)
}

function dropFlash(): void {
  if (flash.value === null) return
  const hadFocus = board.value?.contains(document.activeElement) ?? false
  clearTimer()
  flash.value = null
  if (hadFocus) void nextTick(keepFocus)
}

/** A card that had the focus has left the board: the next one takes it, so that a keyboard does not start from the top again. */
function keepFocus(): void {
  const gone = document.activeElement as HTMLElement | null
  if (gone === null || gone.dataset.state !== 'gone') return
  board.value?.querySelector<HTMLElement>('button[data-card]:not([data-state="gone"])')?.focus()
}

/** The element changed or the board was released: what came of the match that was sent. */
function settle(): void {
  if (!solo.value) return

  const matched = props.element.matched
  const fresh = matched.filter(([left]) => !seenPairs.includes(left))
  const worse = props.element.mismatches > seenMismatches
  seenPairs = matched.map(([left]) => left)
  seenMismatches = props.element.mismatches

  const latest = fresh[fresh.length - 1]
  if (latest !== undefined) {
    show({ left: latest[0], right: latest[1], kind: 'correct' }, CORRECT_MS)
    tried.value = null
  } else if (worse && tried.value !== null) {
    show({ ...tried.value, kind: 'wrong' }, WRONG_MS)
    notificationHaptic('error')
    tried.value = null
  }

  // The match is over — answered, or lost on the way (the board is free again with nothing new): the learner may pick again.
  if (sent.value !== null && (latest !== undefined || worse || !props.disabled)) sent.value = null
}

watch(() => `${props.element.matched.map(([left]) => left).join(',')}|${props.element.mismatches}|${props.disabled ? 1 : 0}`, settle)

// The battle gives the board back (a lost answer): the table is still there to send again.
watch(
  () => props.disabled,
  (now, before) => {
    if (before && !now) submitted.value = false
  },
)

// Another round in the same place: nothing is left over from the one before.
watch(
  () => props.element.id,
  () => {
    clearTimer()
    picked.value = null
    sent.value = null
    tried.value = null
    flash.value = null
    chosen.value = {}
    numbers.value = {}
    submitted.value = false
    seenPairs = props.element.matched.map(([left]) => left)
    seenMismatches = props.element.mismatches
  },
)

onBeforeUnmount(clearTimer)
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="pairs-board" :data-mode="mode" @keydown.esc="cancelPick">
    <div ref="board" class="flex flex-1 flex-col gap-3 px-4 pt-3 pb-4" :aria-busy="disabled ? 'true' : undefined">
      <h2 class="sr-only">{{ modeTitle('pairs') }}</h2>

      <div class="flex items-baseline justify-between gap-3 px-1 text-[15px] leading-5 text-tg-subtitle">
        <p class="font-medium" data-testid="pairs-round">{{ t('practice.pairs.round', { round: element.round, rounds: element.rounds }) }}</p>
        <p class="tabular-nums" data-testid="pairs-count">{{ counter }}</p>
      </div>

      <div class="grid grid-cols-2 gap-2.5">
        <div v-for="column in columns" :key="column.side" class="flex min-w-0 flex-col gap-2.5" role="group" :aria-label="column.label" :data-testid="`pairs-${column.side}`">
          <p class="px-1 text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase" aria-hidden="true">{{ column.label }}</p>
          <button
            v-for="card in column.cards"
            :key="card.id"
            type="button"
            class="option touch-manipulation justify-center gap-1.5 px-2.5 py-2 text-center text-base leading-5 transition-[opacity,visibility,background-color] duration-200 motion-reduce:transition-none"
            :class="{ 'invisible opacity-0': card.look === 'gone' }"
            :data-state="card.look"
            :data-card="column.side"
            :data-pair="card.number ?? undefined"
            :data-testid="`${column.side}-${card.id}`"
            :aria-pressed="card.look === 'gone' ? undefined : card.look === 'selected' ? 'true' : 'false'"
            :aria-hidden="card.look === 'gone' ? 'true' : undefined"
            :aria-disabled="submitted ? 'true' : undefined"
            :tabindex="card.look === 'gone' ? -1 : undefined"
            :disabled="card.look === 'gone'"
            @click="tap(column.side, card.id)"
          >
            <span v-if="card.number !== null" class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-accent text-[13px] font-semibold text-tg-button-text" aria-hidden="true" data-testid="pair-number">{{ card.number }}</span>
            <AppIcon v-else-if="card.look === 'correct'" name="check" :size="20" />
            <AppIcon v-else-if="card.look === 'incorrect'" name="x-mark" :size="20" />
            <span v-else-if="card.look === 'selected'" class="size-2.5 shrink-0 rounded-full bg-accent" aria-hidden="true" data-testid="pick-dot" />
            <span class="min-w-0 break-words hyphens-auto">{{ card.text }}</span>
            <span v-if="stateWord(card)" class="sr-only" data-testid="card-state">, {{ stateWord(card) }}</span>
          </button>
        </div>
      </div>

      <p
        class="flex min-h-6 items-center justify-center gap-1.5 text-center text-[15px] leading-5"
        :class="TONE_CLASS[status.tone]"
        role="status"
        aria-live="polite"
        data-testid="pairs-status"
        :data-tone="status.tone"
      >
        <AppIcon v-if="status.tone === 'success'" name="check" :size="18" />
        <AppIcon v-else-if="status.tone === 'danger'" name="x-mark" :size="18" />
        <span class="min-w-0 break-words">{{ status.text }}</span>
      </p>
    </div>

    <StickyActions v-if="mode === 'battle'">
      <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="!complete || submitted || disabled" data-testid="pairs-check" @click="check">
        {{ t('exercise.check') }}
      </button>
    </StickyActions>
  </div>
</template>
