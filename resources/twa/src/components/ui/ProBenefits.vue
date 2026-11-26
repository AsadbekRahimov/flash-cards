<script setup lang="ts">
import { computed } from 'vue'
import { useBillingStore } from '@/stores/billing'
import { useSettingsStore } from '@/stores/settings'
import { useI18n } from '@/i18n'
import { resolveBenefits, type BenefitSlot } from '@/composables/proBenefits'
import type { StringKey } from '@/i18n/ru'
import AppIcon from './AppIcon.vue'

/**
 * What Pro gives (the Subscription screen, the paywall), as on the Pro mock-up (docs/design/2026-11-25-redesign Pro): a green tick in a
 * soft circle, the name (16 px bold) and under it what it means (14 px, secondary colour); no lines between the items.
 *
 * - `short` — the three that matter most (new words, the streak freeze, the practice; the exercises stand in when there is no line
 *   about practice, the emoji of Pro after them), for the paywall sheet. Without it — the whole list, for the Subscription screen; its
 *   last line is the emoji next to the name (docs/31 §5а), left out when the server sends an empty set of emoji.
 * - `compact` — tighter rows.
 *
 * Texts are `benefits.row.*` in the dictionaries; `data-testid="pro-benefits"` on the list, `benefit` on each item, `data-benefit`
 * holds the key of the benefit (`benefits.new_words`, ...). The note on the new words quotes the Free limit of `/me` ("no limit instead
 * of 20 a day") while it is known and the learner is on Free.
 *
 * The line about practice is the server's word: `practice.pro_promise` of `/me` (`ProPromises` — one rule, which the landing and the Stars
 * invoice follow too). `both` — "Practice and battles"; `solo` — "Practice" (the battles are switched off, or Free has no limit on them);
 * `none` — no such line: practice is not open to this learner (`PRACTICE_ACCESS=off`, a learner outside the pilot), or Pro has nothing to
 * lift there. The component works out nothing from the flags of the block, and until `/me` has come the word is `none`.
 *
 * @example <ProBenefits short />
 */
const props = defineProps<{ compact?: boolean; short?: boolean }>()

/** The slots of the list, in order (`resolveBenefits` decides which of `practice` / `game` is there for this learner). */
const BENEFITS: readonly BenefitSlot[] = [
  'benefits.new_words',
  'practice',
  'game',
  'benefits.exercises',
  'benefits.decks',
  'benefits.freezes',
  'benefits.mock_exams',
  'billing.benefit.pro_emoji',
]

/** The short list of the paywall: the first three that are there. */
const SHORT: readonly BenefitSlot[] = ['benefits.new_words', 'benefits.freezes', 'practice', 'benefits.exercises', 'billing.benefit.pro_emoji']
const SHORT_COUNT = 3

/** The name and the note of each benefit (the new words have a note of their own, see `note`). */
const ROWS: Partial<Record<StringKey, { name: StringKey; note: StringKey }>> = {
  'benefits.new_words': { name: 'benefits.row.new_words', note: 'benefits.row.new_words.note' },
  'benefits.practice': { name: 'benefits.row.practice', note: 'benefits.row.no_limit' },
  'benefits.practice_solo': { name: 'benefits.row.practice_solo', note: 'benefits.row.no_limit' },
  'benefits.game': { name: 'benefits.row.game', note: 'benefits.row.no_limit' },
  'benefits.exercises': { name: 'benefits.row.exercises', note: 'benefits.row.exercises.note' },
  'benefits.decks': { name: 'benefits.row.decks', note: 'benefits.row.decks.note' },
  'benefits.freezes': { name: 'benefits.row.freezes', note: 'benefits.row.freezes.note' },
  'benefits.mock_exams': { name: 'benefits.row.mock_exams', note: 'benefits.row.mock_exams.note' },
  'billing.benefit.pro_emoji': { name: 'billing.benefit.pro_emoji', note: 'billing.benefit.pro_emoji.note' },
}

const billing = useBillingStore()
const settings = useSettingsStore()
const { t } = useI18n()

/** The emoji of Pro is sold unless the server sends an empty set (`options.pro_emojis` of the settings `/me` carries). */
const emojiOffered = computed(() => settings.data?.options.pro_emojis?.length !== 0)

/** Free with a known limit: "no limit instead of 20 a day". */
const freeLimit = computed((): number | null =>
  billing.entitlement?.plan === 'free' ? (billing.entitlement.new_words_per_day ?? null) : null,
)

interface BenefitRow {
  key: StringKey
  name: string
  note: string
}

const rows = computed((): BenefitRow[] => {
  const keys = resolveBenefits(props.short ? SHORT : BENEFITS, billing.practiceAccess.pro_promise, billing.gameAccess.pro_promise).filter(
    (key) => key !== 'billing.benefit.pro_emoji' || emojiOffered.value,
  )

  return (props.short ? keys.slice(0, SHORT_COUNT) : keys).flatMap((key) => {
    const row = ROWS[key]
    if (!row) return []
    const note =
      key === 'benefits.new_words' && freeLimit.value !== null
        ? t('benefits.row.new_words.note_free', { limit: freeLimit.value })
        : t(row.note)
    return [{ key, name: t(row.name), note }]
  })
})
</script>

<template>
  <ul class="flex flex-col" :class="compact ? 'gap-3' : 'gap-3.5'" data-testid="pro-benefits">
    <li v-for="row in rows" :key="row.key" class="flex items-start gap-3" :data-benefit="row.key" data-testid="benefit">
      <span class="flex size-[26px] shrink-0 items-center justify-center rounded-full bg-success-soft text-success-ink" aria-hidden="true">
        <AppIcon name="check" :size="16" />
      </span>
      <span class="flex min-w-0 flex-col gap-0.5">
        <span class="text-base leading-[22px] font-bold" data-testid="benefit-name">{{ row.name }}</span>
        <span class="text-sm leading-[19px] text-muted" data-testid="benefit-note">{{ row.note }}</span>
      </span>
    </li>
  </ul>
</template>
