<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { VERDICT_LOOK } from '@/components/battle/verdict'
import { shownAnswer, shownCorrect } from '@/components/battle/revealText'
import type { ShownAnswer } from '@/components/battle/revealText'
import { remainingMs, secondsLeft } from '@/composables/battleTime'
import { useNow } from '@/composables/useNow'
import { useI18n } from '@/i18n'
import type { BattleRound, BattleSideResult } from '@/api/types'

/**
 * The end of a round (docs/16 §11.2, docs/15 §3.5): what the server shows once both players have answered or the time
 * is out — until then neither the right answer nor the opponent's answer is on the device.
 *
 * - The right answer, in the words of the mode: the sentence, the phrase with the blank filled, what was dictated, the
 *   word pairs.
 * - A card for each player: the verdict (a symbol, a colour and a word), what they gave, the points of the round.
 * - The explanation and the translation, when the task has them.
 * - "Next round in 3…" (on the last round "Result in 3…"): the server moves the match on by itself, there is no button.
 *
 * `round` is a round that is resolved (`reveal` is set); `last` — it is the last round of the match; `offsetMs` and
 * `totalSeconds` — the clock of the server and the length of this pause, for the countdown and its bar.
 */
const props = defineProps<{
  round: BattleRound
  opponentName: string
  last: boolean
  offsetMs: number
  totalSeconds: number
}>()

const { t, tn } = useI18n()
const now = useNow()

const reveal = computed(() => props.round.reveal)
const correct = computed(() => (reveal.value === null ? null : shownCorrect(props.round.element, reveal.value.correct)))

interface Side {
  key: 'me' | 'opponent'
  name: string
  result: BattleSideResult
  /** What the player gave, in words. */
  shown: ShownAnswer
}

const sides = computed((): Side[] => {
  const current = reveal.value
  if (current === null) return []
  const side = (key: Side['key'], name: string, result: BattleSideResult): Side => ({
    key,
    name,
    result,
    shown: shownAnswer(props.round.element, result.answer, current.correct),
  })
  return [side('me', t('battle.you'), current.me), side('opponent', props.opponentName, current.opponent)]
})

const left = computed(() => remainingMs(props.round.phase_ends_at, now.value, props.offsetMs))
const seconds = computed(() => secondsLeft(left.value))
const share = computed(() => (props.totalSeconds > 0 ? Math.min(1, Math.max(0, left.value / (props.totalSeconds * 1000))) : 0))
const next = computed((): string => {
  if (props.round.phase_ends_at === null || seconds.value <= 0) return props.last ? t('battle.reveal.last_now') : t('battle.reveal.next_now')
  return props.last ? t('battle.reveal.last', { seconds: seconds.value }) : t('battle.reveal.next', { seconds: seconds.value })
})
</script>

<template>
  <div v-if="reveal" class="flex flex-1 flex-col gap-3 px-4 pt-3 pb-6" data-testid="battle-reveal">
    <article class="card flex flex-col items-center gap-2 px-5 py-5 text-center">
      <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('battle.reveal.correct') }}</p>
      <p v-if="correct?.text" class="text-2xl leading-8 font-bold break-words" data-testid="reveal-correct">{{ correct.text }}</p>
      <ul v-if="correct && correct.pairs.length > 0" class="flex w-full flex-col gap-1.5" role="list" data-testid="reveal-pairs">
        <li v-for="pair in correct.pairs" :key="pair.left" class="flex items-center justify-center gap-2 text-lg leading-6 font-semibold break-words">
          <span>{{ pair.left }}</span>
          <AppIcon name="arrows-right-left" :size="16" class="shrink-0 text-tg-hint" />
          <span class="sr-only"> — </span>
          <span>{{ pair.right }}</span>
        </li>
      </ul>
    </article>

    <article v-for="side in sides" :key="side.key" class="card flex flex-col gap-2" :data-testid="`side-${side.key}`" :data-verdict="side.result.verdict">
      <div class="flex items-center gap-2">
        <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-medium" data-testid="side-name">{{ side.name }}</span>
        <span class="chip shrink-0" :class="VERDICT_LOOK[side.result.verdict].chip" data-testid="side-verdict">
          <AppIcon :name="VERDICT_LOOK[side.result.verdict].icon" :size="14" />
          {{ t(VERDICT_LOOK[side.result.verdict].label) }}
        </span>
        <span class="min-w-[3.25rem] text-right text-[17px] leading-[22px] font-bold tabular-nums" aria-hidden="true" data-testid="side-points">+{{ side.result.points }}</span>
        <span class="sr-only">{{ tn('battle.points', side.result.points) }}</span>
      </div>
      <p v-if="side.shown.text" class="text-base leading-[22px] break-words" :class="{ 'text-tg-subtitle': side.result.verdict !== 'correct' }" data-testid="side-answer">{{ side.shown.text }}</p>
      <p v-else-if="side.shown.pairs" class="text-base leading-[22px] text-tg-subtitle" data-testid="side-answer">
        {{ t('battle.reveal.pairs_right', { right: side.shown.pairs.right, total: side.shown.pairs.total }) }}
      </p>
      <p v-else class="text-base leading-[22px] text-tg-hint" data-testid="side-answer">{{ t('battle.reveal.no_answer') }}</p>
    </article>

    <section v-if="reveal.translation || reveal.explanation" class="card flex flex-col gap-3" data-testid="reveal-notes">
      <div v-if="reveal.translation" data-testid="reveal-translation">
        <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.translation') }}</p>
        <p class="mt-0.5 text-base leading-[22px] italic">{{ reveal.translation }}</p>
      </div>
      <div v-if="reveal.explanation" data-testid="reveal-explanation">
        <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.explanation') }}</p>
        <p class="mt-0.5 text-base leading-[22px]">{{ reveal.explanation }}</p>
      </div>
    </section>

    <div class="mt-auto flex flex-col gap-2 pt-2" data-testid="reveal-next">
      <div class="progress" aria-hidden="true">
        <div class="progress-fill" :style="{ width: `${Math.round(share * 100)}%` }" />
      </div>
      <p class="flex items-center justify-center gap-1.5 text-[15px] leading-5 font-medium text-tg-subtitle">
        <AppIcon name="clock" :size="16" />
        <span data-testid="reveal-next-text">{{ next }}</span>
      </p>
    </div>
  </div>
</template>
