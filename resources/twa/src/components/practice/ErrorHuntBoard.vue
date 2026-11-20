<script setup lang="ts">
/**
 * "Error hunt" (docs/16 §5.7, §11.3): an English sentence with one wrong word, answered in TWO steps, each its own request.
 *
 * - Step `locate`: the words of the sentence are buttons in a wrapping row (each at least 44 px high, 6 px apart). A tap SELECTS a word
 *   (`aria-pressed`, marked by colour, a thicker frame and a symbol in its corner); "This is the mistake" sends it (`{ token_id }`). The words
 *   are small on a phone, so a tap alone never sends: a miss of a finger would be an answer. The selection changes no size: the symbol stands
 *   over the corner, out of the flow, so the rows of words do not move under the finger.
 * - Step `fix`: the server has the first answer and tells where the learner looked and where the mistake is (`element.located`): a word
 *   found is green with ✓ and "Right, the mistake is here"; a word missed is red with ✕ ("your pick"), the real one has an orange frame
 *   ("mistake"), and the line says "The mistake is in another word". Colour is never alone: a symbol and words go with it. Under the
 *   sentence the corrections (`element.options`, sent only now); a tap on one IS the answer (`{ option_id }`), as in "Pick the
 *   collocation". The second step comes after a wrong first one too. When the step comes (and when the screen opens at it) the corrections are
 *   scrolled into view above the sticky buttons: on a short phone they are below the fold.
 * - "I don't know" (`skip`) is there on both steps.
 *
 * The board follows `element.step` of the projection: the session screen keeps the same component between the steps (its key does not
 * change), so the step is read from the element, never remembered here - a reload in the middle brings the `fix` step back as it was.
 *
 * - `mode` - the interface of every mode component; the mode is solo only. ONE answer per STEP (docs/16 §11.4): when a request got no
 *   reply (the screen lets go of `disabled` while the step is still the same) the server may have counted it, so the pick stays and is
 *   locked, and the same answer is sent again ("This is the mistake" again, or "Retry" for a correction) - the store keeps its request id.
 * - `disabled` - an answer is on its way: nothing reacts.
 * Events: `answer` (`{ token_id }` / `{ option_id }`), `skip`.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { shownOptions } from '@/composables/collocationPhrase'
import { prefersReducedMotion } from '@/composables/motion'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { ErrorHuntElement, PracticeAnswerPayload, PracticeChoice } from '@/api/types'

const props = defineProps<{
  element: ErrorHuntElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload]; skip: [] }>()

const { selectionHaptic } = useTelegram()
const { t } = useI18n()

/** Step `locate`: the word selected (not sent until "This is the mistake"). */
const selected = ref<string | null>(null)
/** Step `fix`: the correction tapped (the tap is the answer). */
const picked = ref<string | null>(null)
/** What went out last, and at which step: an answer or "I don't know". */
const lastSent = ref<{ step: ErrorHuntElement['step']; kind: 'answer' | 'skip' } | null>(null)
/** Solo: the request of this step got no reply. The pick stays; the one thing left to do is to send it again. */
const retrying = ref(false)

const step = computed(() => props.element.step)
/** What was sent at the step on screen (null: nothing yet). */
const sentNow = computed((): 'answer' | 'skip' | null => (lastSent.value !== null && lastSent.value.step === step.value ? lastSent.value.kind : null))
const busy = computed((): boolean => props.disabled === true)

const canPick = computed((): boolean => !busy.value && sentNow.value === null)
const canConfirm = computed(
  (): boolean => !busy.value && selected.value !== null && (sentNow.value === null || (retrying.value && sentNow.value === 'answer')),
)
const canSkip = computed((): boolean => !busy.value && (sentNow.value === null || (retrying.value && sentNow.value === 'skip')))

// A new step (the reply of `locate`, or the screen brought up to date by the server) or a new element: nothing of the old one is kept.
watch(
  () => `${props.element.id}:${props.element.step}`,
  () => {
    selected.value = null
    picked.value = null
    lastSent.value = null
    retrying.value = false
  },
)

// The request ended and the step is still this one: in a solo session what was sent got no reply. It may have been counted, so it is
// not given back for a change: it is offered to be sent again. (A step that moved on is the watcher above.)
watch(
  () => props.disabled,
  (now, before) => {
    if (before === true && now !== true && props.mode === 'solo' && sentNow.value !== null) retrying.value = true
  },
)

// ---- step `locate` ----

function select(token: PracticeChoice): void {
  if (!canPick.value || step.value !== 'locate') return
  selectionHaptic()
  selected.value = selected.value === token.id ? null : token.id
}

function confirm(): void {
  if (!canConfirm.value || selected.value === null) return
  lastSent.value = { step: 'locate', kind: 'answer' }
  emit('answer', { token_id: selected.value })
}

// ---- step `fix` ----

const located = computed(() => (step.value === 'fix' ? props.element.located : null))

type TokenLook = 'idle' | 'selected' | 'found' | 'yours' | 'error'

interface ShownToken extends PracticeChoice {
  look: TokenLook
}

const tokens = computed((): ShownToken[] =>
  props.element.tokens.map((token): ShownToken => {
    const where = located.value
    if (where === null) return { ...token, look: token.id === selected.value ? 'selected' : 'idle' }
    if (token.id === where.error_token_id) return { ...token, look: where.ok ? 'found' : 'error' }
    if (token.id === where.token_id) return { ...token, look: 'yours' }
    return { ...token, look: 'idle' }
  }),
)

const TOKEN_CLASSES: Record<TokenLook, string> = {
  // The frames of `.option` (style.css): an inset line, so that the 1.5 px frame of a state does not move the word.
  idle: 'bg-surface shadow-[inset_0_0_0_1px_var(--app-hairline)]',
  selected: 'bg-accent-soft text-accent-ink shadow-[inset_0_0_0_1.5px_var(--app-primary)]',
  found: 'bg-success-soft text-success-ink shadow-[inset_0_0_0_1.5px_var(--app-success)]',
  yours: 'bg-danger-soft text-danger-ink shadow-[inset_0_0_0_1.5px_var(--app-danger)]',
  error: 'bg-warning-soft text-warning-ink shadow-[inset_0_0_0_1.5px_var(--app-warning)]',
}

/** The words a screen reader says besides the colour and the symbol. */
function tokenState(look: TokenLook): string {
  if (look === 'selected') return t('practice.error_hunt.token.selected')
  if (look === 'yours') return t('practice.error_hunt.token.yours')
  if (look === 'error' || look === 'found') return t('practice.error_hunt.token.error')
  return ''
}

const options = computed(() => shownOptions(props.element.options ?? [], picked.value, null, null))

/** The block of the corrections. */
const optionsEl = ref<HTMLElement | null>(null)

/**
 * Brings the corrections into view. On a short phone they come below the fold, under the sticky buttons: the block keeps a margin of the
 * height of those buttons (`--app-actions-h`, `scroll-margin-bottom`), so its last option ends above them. Smooth unless motion is reduced.
 */
function revealOptions(): void {
  void nextTick(() => {
    if (step.value !== 'fix') return
    optionsEl.value?.scrollIntoView?.({ block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth' })
  })
}

watch(step, (now) => {
  if (now === 'fix') revealOptions()
})
onMounted(() => {
  if (step.value === 'fix') revealOptions()
})

function pick(option: PracticeChoice): void {
  if (!canPick.value || step.value !== 'fix') return
  picked.value = option.id
  lastSent.value = { step: 'fix', kind: 'answer' }
  emit('answer', { option_id: option.id })
}

/** "Retry" of a correction: the same option, nothing else. */
function resend(): void {
  if (busy.value || !retrying.value || picked.value === null) return
  emit('answer', { option_id: picked.value })
}

function skip(): void {
  if (!canSkip.value) return
  lastSent.value = { step: step.value, kind: 'skip' }
  emit('skip')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="error-hunt-board" :data-mode="mode" :data-step="step">
    <div class="flex flex-1 flex-col gap-4 px-5 pt-4 pb-4 [@media(max-height:560px)]:gap-3 [@media(max-height:560px)]:pt-2">
      <article class="flex flex-col gap-3 px-1 py-5 [@media(max-height:560px)]:py-3">
        <p class="text-center text-[13px] leading-[18px] text-muted" data-testid="error-hunt-instruction">
          {{ step === 'locate' ? t('practice.error_hunt.instruction_locate') : t('practice.error_hunt.instruction_fix') }}
        </p>

        <!-- The sentence, word by word: buttons at the first step, the marks of the search at the second. -->
        <ul class="flex flex-wrap justify-center gap-1.5" role="list" :aria-label="t('practice.error_hunt.words')" lang="en" data-testid="error-hunt-tokens">
          <li v-for="token in tokens" :key="token.id" class="max-w-full">
            <button
              v-if="step === 'locate'"
              type="button"
              class="relative inline-flex min-h-11 max-w-full min-w-11 items-center justify-center gap-1 rounded-control px-3 py-1.5 text-[17px] leading-[22px] [overflow-wrap:anywhere] transition-colors duration-150 active:scale-[0.97]"
              :class="[TOKEN_CLASSES[token.look], { 'opacity-60': !canPick && token.look === 'idle' }]"
              :aria-pressed="token.look === 'selected' ? 'true' : 'false'"
              :disabled="!canPick"
              :data-look="token.look"
              :data-testid="`token-${token.id}`"
              @click="select(token)"
            >
              <span>{{ token.text }}</span>
              <!-- The symbol of the selection stands over the corner, out of the flow: selecting a word changes no width. -->
              <span
                v-show="token.look === 'selected'"
                class="pointer-events-none absolute -top-1 -right-1 flex size-[18px] items-center justify-center rounded-full bg-primary text-on-primary"
                aria-hidden="true"
                data-testid="token-mark"
              >
                <AppIcon name="check" :size="12" />
              </span>
            </button>
            <span
              v-else
              class="inline-flex min-h-11 max-w-full min-w-11 items-center justify-center gap-1 rounded-control px-3 py-1.5 text-[17px] leading-[22px] [overflow-wrap:anywhere]"
              :class="TOKEN_CLASSES[token.look]"
              :data-look="token.look"
              :data-testid="`token-${token.id}`"
            >
              <AppIcon v-if="token.look === 'found'" name="check" :size="18" />
              <AppIcon v-else-if="token.look === 'yours'" name="x-mark" :size="18" />
              <AppIcon v-else-if="token.look === 'error'" name="exclamation-circle" :size="18" />
              <span>{{ token.text }}</span>
              <span v-if="tokenState(token.look)" class="sr-only">({{ tokenState(token.look) }})</span>
            </span>
          </li>
        </ul>

        <!-- What the search found: words and a symbol besides the colours of the words. -->
        <p
          v-if="located"
          class="flex items-center justify-center gap-1.5 text-center text-[15px] leading-5 font-semibold"
          :class="located.ok ? 'text-success-ink' : 'text-danger-ink'"
          role="status"
          :data-ok="located.ok ? 'true' : 'false'"
          data-testid="error-hunt-located"
        >
          <AppIcon :name="located.ok ? 'check-circle' : 'x-circle'" :size="18" />
          {{ located.ok ? t('practice.error_hunt.located_ok') : t('practice.error_hunt.located_wrong') }}
        </p>
      </article>

      <!-- The request got no reply: the pick cannot be changed, and it is sent again. -->
      <InlineAlert v-if="retrying" tone="warning" :message="t('practice.session.resend_note')" data-testid="error-hunt-resend-note" />

      <div
        v-if="step === 'fix'"
        ref="optionsEl"
        class="flex flex-col gap-2.5"
        style="scroll-margin-bottom: calc(var(--app-actions-h, 0px) + 12px)"
        role="group" :aria-label="t('practice.error_hunt.instruction_fix')" data-testid="error-hunt-options">
        <button
          v-for="(option, index) in options"
          :key="option.id"
          type="button"
          class="option"
          :class="{ 'opacity-60': !canPick && option.state === 'idle' }"
          :data-state="option.state"
          :data-testid="`option-${index}`"
          :disabled="!canPick"
          :aria-pressed="option.caption === 'your' ? 'true' : undefined"
          @click="pick(option)"
        >
          <span class="min-w-0 flex-1 break-words">
            <span class="block" lang="en">{{ option.text }}</span>
            <span v-if="option.caption" class="mt-0.5 block text-[13px] leading-[18px] font-normal first-letter:uppercase" :data-testid="`option-caption-${index}`">
              {{ t('practice.reveal.your_option') }}
            </span>
          </span>
          <AppIcon v-if="option.state === 'selected'" name="check-circle" class="text-accent-ink" :size="22" />
        </button>
      </div>
    </div>

    <StickyActions surface>
      <div class="flex items-stretch gap-2">
        <button type="button" class="btn btn-plain shrink-0 text-muted" :disabled="!canSkip" data-testid="skip-btn" @click="skip">
          {{ t('exercise.skip') }}
        </button>
        <button
          v-if="step === 'locate'"
          type="button"
          class="btn btn-primary btn-lg min-w-0 flex-1"
          :disabled="!canConfirm"
          data-testid="confirm-btn"
          @click="confirm"
        >
          {{ t('practice.error_hunt.confirm') }}
        </button>
        <button
          v-else-if="retrying && sentNow === 'answer'"
          type="button"
          class="btn btn-primary btn-lg min-w-0 flex-1"
          :disabled="busy"
          :aria-busy="busy ? 'true' : undefined"
          data-testid="resend-btn"
          @click="resend"
        >
          {{ t('common.retry') }}
        </button>
      </div>
    </StickyActions>
  </div>
</template>
