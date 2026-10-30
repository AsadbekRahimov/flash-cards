<script setup lang="ts">
/**
 * "Build the sentence" (docs/15 §3.2, docs/16 §11.3): the meaning of a sentence in the language of the learner and the
 * English fragments to put in a line.
 *
 * - `element` — the prompt and the fragments in the order the server shuffled them (opaque ids; nothing says which order is right).
 * - `mode` — `solo` / `battle`: the same in both; "Check" sends the whole line once (in a battle that is the final answer).
 * - `disabled` — an answer is on its way: nothing reacts.
 * Events: `answer` (`{ order }`: the ids of ALL the fragments, in the order they were put), `skip` ("I don't know").
 *
 * The line and the bank. A tap on a fragment of the bank puts it at the END of the line; a tap on a fragment of the line gives it
 * back to the bank, to its own place; "Start again" empties the line; "Check" waits until every fragment is in the line. The places
 * of the bank stay where they are - a fragment that has gone leaves an empty slot - so nothing moves under a finger.
 * A fragment is never lost and never doubled: the line is a list of ids, an id is in it at most once, and the bank draws every
 * fragment of the element, keyed by id (so the same word twice, `the … the`, is two fragments).
 * The first letter of the line is drawn as a capital - drawn only: the ids go to the server, the server compares the text.
 *
 * The half-built line is kept in the memory of the page (`sentenceDraft`): ✕ and "Continue" bring it back.
 * Keyboard: the fragments are buttons; when the pressed one leaves the place it was pressed in, the focus follows the learner.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { modeTitle } from '@/composables/practiceLabels'
import { clearSentenceDraft, readSentenceDraft, writeSentenceDraft } from '@/composables/sentenceDraft'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { PracticeAnswerPayload, SentenceElement } from '@/api/types'

const props = defineProps<{
  element: SentenceElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload]; skip: [] }>()

const { selectionHaptic } = useTelegram()
const { t } = useI18n()
const progressId = useId()

const fragmentIds = computed(() => props.element.fragments.map((fragment) => fragment.id))
const textOf = computed(() => new Map(props.element.fragments.map((fragment) => [fragment.id, fragment.text])))

/** Ids of the fragments in the line, in the order they were put. */
const order = ref<string[]>(readSentenceDraft(props.element.id, fragmentIds.value))
/** The answer (or "I don't know") has been sent and nothing has come back: a second tap sends nothing. */
const sent = ref(false)

const total = computed(() => props.element.fragments.length)
const placed = computed(() => new Set(order.value))
const complete = computed(() => total.value > 0 && order.value.length === total.value)
const locked = computed(() => props.disabled === true || sent.value)

/** A text with its first letter in capitals (a code point, not a UTF-16 unit): only what the line shows. */
function capitalized(text: string): string {
  const first = Array.from(text)[0] ?? ''
  return first.toUpperCase() + text.slice(first.length)
}

const line = computed(() =>
  order.value.map((id, index) => {
    const text = textOf.value.get(id) ?? ''
    return { id, text: index === 0 ? capitalized(text) : text }
  }),
)

// ---- the keyboard: where the focus goes when the pressed fragment leaves its place ----

const root = ref<HTMLElement | null>(null)
const checkButton = ref<HTMLButtonElement | null>(null)
const bankButtons = new Map<string, HTMLButtonElement>()

function bankRef(id: string, el: Element | ComponentPublicInstance | null): void {
  if (el instanceof HTMLButtonElement) bankButtons.set(id, el)
  else bankButtons.delete(id)
}

/** The focus is on one of our controls: the learner presses with a keyboard (or a pointer that focuses buttons). */
function holdsFocus(): boolean {
  const active = document.activeElement
  return active !== null && root.value !== null && root.value !== active && root.value.contains(active)
}

async function focusOn(target: HTMLElement | null | undefined): Promise<void> {
  await nextTick()
  target?.focus()
}

/** The slot of the bank that is free and nearest to the fragment: the next ones first, then the ones before. */
function neighbourInBank(id: string): string | null {
  const ids = fragmentIds.value
  const at = ids.indexOf(id)
  const free = (candidate: string): boolean => !placed.value.has(candidate)

  return ids.slice(at + 1).find(free) ?? ids.slice(0, at).reverse().find(free) ?? null
}

// ---- the actions ----

function take(id: string): void {
  if (locked.value || placed.value.has(id) || !textOf.value.has(id)) return

  const withKeyboard = holdsFocus()
  order.value = [...order.value, id]
  selectionHaptic()

  if (withKeyboard) {
    const next = neighbourInBank(id)
    void focusOn(next === null ? checkButton.value : bankButtons.get(next))
  }
}

function putBack(id: string): void {
  if (locked.value || !placed.value.has(id)) return

  const withKeyboard = holdsFocus()
  order.value = order.value.filter((picked) => picked !== id)
  selectionHaptic()

  if (withKeyboard) void focusOn(bankButtons.get(id))
}

function reset(): void {
  if (locked.value || order.value.length === 0) return

  const withKeyboard = holdsFocus()
  order.value = []
  selectionHaptic()

  const first = fragmentIds.value[0]
  if (withKeyboard && first !== undefined) void focusOn(bankButtons.get(first))
}

function check(): void {
  if (locked.value || !complete.value) return
  sent.value = true
  emit('answer', { order: [...order.value] })
}

function skip(): void {
  if (locked.value) return
  sent.value = true
  emit('skip')
}

// ---- keeping the line ----

watch(order, (current) => writeSentenceDraft(props.element.id, current))

// Another element in the same component (the screen keys the component by the element, but a battle may not): its own line.
// The same element read again (new object, same id and fragments) is not another one - the line stays.
watch(
  () => `${props.element.id}:${fragmentIds.value.join(',')}`,
  () => {
    order.value = readSentenceDraft(props.element.id, fragmentIds.value)
    sent.value = false
  },
)

// The answer did not go through (the screen takes the lock off): the line is as it was, and can be sent again.
watch(
  () => props.disabled,
  (now, before) => {
    if (before === true && now !== true) sent.value = false
  },
)

// The answer went out and the element is over: nothing to bring back.
onBeforeUnmount(() => {
  if (sent.value) clearSentenceDraft(props.element.id)
})
</script>

<template>
  <div ref="root" class="flex flex-1 flex-col" :data-mode="mode" data-testid="sentence-builder">
    <div class="flex flex-1 flex-col gap-3 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-2 px-5 py-5 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ modeTitle('sentence') }}</p>
        <h2 class="text-2xl leading-8 font-bold break-words" data-testid="sentence-prompt">{{ element.prompt }}</h2>
      </article>

      <!-- The line: wraps onto new rows, never scrolls sideways. -->
      <div class="card flex min-h-28 flex-col gap-2 p-3" data-testid="sentence-line">
        <ul role="list" :aria-label="t('practice.sentence.line')" class="flex flex-wrap content-start gap-2" data-testid="sentence-answer">
          <li v-for="item in line" :key="item.id" class="max-w-full min-w-0">
            <button
              type="button"
              class="btn btn-secondary max-w-full min-w-12 wrap-anywhere"
              :disabled="locked"
              :aria-label="t('practice.sentence.remove', { word: item.text })"
              :data-testid="`line-${item.id}`"
              @click="putBack(item.id)"
            >
              {{ item.text }}
            </button>
          </li>
        </ul>
        <p v-if="line.length === 0" class="my-auto text-center text-[15px] leading-5 text-tg-hint" data-testid="sentence-empty">
          {{ t('practice.sentence.empty') }}
        </p>
      </div>

      <div class="flex items-center justify-between gap-2">
        <p :id="progressId" class="min-w-0 px-1 text-[15px] leading-5 text-tg-subtitle" role="status" aria-live="polite" data-testid="sentence-progress">
          {{ t('practice.sentence.progress', { done: order.length, total }) }}
        </p>
        <button type="button" class="btn btn-plain shrink-0" :disabled="locked || order.length === 0" data-testid="reset-btn" @click="reset">
          <AppIcon name="arrow-path" :size="18" />
          {{ t('practice.sentence.reset') }}
        </button>
      </div>

      <!-- The bank: every fragment has its slot for good, a fragment in the line leaves the slot empty. -->
      <div role="group" :aria-label="t('practice.sentence.bank')" class="flex flex-wrap gap-2" data-testid="sentence-bank">
        <div
          v-for="fragment in element.fragments"
          :key="fragment.id"
          class="max-w-full min-w-0 rounded-xl"
          :class="{ 'bg-track': placed.has(fragment.id) }"
        >
          <button
            :ref="(el) => bankRef(fragment.id, el)"
            type="button"
            class="btn btn-tinted max-w-full min-w-12 wrap-anywhere"
            :class="{ invisible: placed.has(fragment.id) }"
            :disabled="locked || placed.has(fragment.id)"
            :aria-label="t('practice.sentence.add', { word: fragment.text })"
            :data-placed="placed.has(fragment.id)"
            :data-testid="`fragment-${fragment.id}`"
            @click="take(fragment.id)"
          >
            {{ fragment.text }}
          </button>
        </div>
      </div>
    </div>

    <StickyActions>
      <button
        ref="checkButton"
        type="button"
        class="btn btn-primary btn-lg btn-block"
        :disabled="locked || !complete"
        :aria-describedby="progressId"
        data-testid="check-btn"
        @click="check"
      >
        {{ t('exercise.check') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" :disabled="locked" data-testid="skip-btn" @click="skip">
        {{ t('exercise.skip') }}
      </button>
    </StickyActions>
  </div>
</template>
