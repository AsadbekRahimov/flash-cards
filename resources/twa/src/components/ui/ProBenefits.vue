<script setup lang="ts">
import { computed } from 'vue'
import { useBillingStore } from '@/stores/billing'
import { useSettingsStore } from '@/stores/settings'
import { useI18n } from '@/i18n'
import { resolveBenefits, type BenefitSlot } from '@/composables/proBenefits'
import type { StringKey } from '@/i18n/ru'
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'

/**
 * What Pro gives (the Subscription screen, the paywall), as the list of the kit Muhrla (10-podpiska-pro): one flat card (surface, hairline,
 * radius lg), a row per benefit — the 40 x 40 plate of sunken paper with the icon of the benefit, the name (Body/M Strong) and under it
 * what it means (13 px, secondary colour), a green check on the right (the colour is never alone: the check says it).
 *
 * - `short` — the three that matter most (new words, the streak freeze, the practice; the exercises stand in when there is no line
 *   about practice, the emoji of Pro after them), for the paywall sheet. Without it — the whole list, for the Subscription screen; its
 *   last line is the emoji next to the name (docs/31 §5а), left out when the server sends an empty set of emoji.
 * - `compact` — tighter rows (the paywall sheet).
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

/** The name, the note and the icon of each benefit (the new words have a note of their own, see `note`). */
const ROWS: Partial<Record<StringKey, { name: StringKey; note: StringKey; icon: IconName }>> = {
  'benefits.new_words': { name: 'benefits.row.new_words', note: 'benefits.row.new_words.note', icon: 'infinity' },
  'benefits.practice': { name: 'benefits.row.practice', note: 'benefits.row.no_limit', icon: 'swords' },
  'benefits.practice_solo': { name: 'benefits.row.practice_solo', note: 'benefits.row.no_limit', icon: 'target' },
  'benefits.game': { name: 'benefits.row.game', note: 'benefits.row.no_limit', icon: 'map' },
  'benefits.exercises': { name: 'benefits.row.exercises', note: 'benefits.row.exercises.note', icon: 'puzzle-piece' },
  'benefits.decks': { name: 'benefits.row.decks', note: 'benefits.row.decks.note', icon: 'rectangle-stack' },
  'benefits.freezes': { name: 'benefits.row.freezes', note: 'benefits.row.freezes.note', icon: 'snowflake' },
  'benefits.mock_exams': { name: 'benefits.row.mock_exams', note: 'benefits.row.mock_exams.note', icon: 'document-text' },
  'billing.benefit.pro_emoji': { name: 'billing.benefit.pro_emoji', note: 'billing.benefit.pro_emoji.note', icon: 'sparkles' },
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
  icon: IconName
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
    return [{ key, name: t(row.name), note, icon: row.icon }]
  })
})
</script>

<template>
  <ul class="list" data-testid="pro-benefits">
    <li
      v-for="row in rows"
      :key="row.key"
      class="list-row list-row-icon"
      :class="compact ? 'min-h-[52px] py-2' : undefined"
      :data-benefit="row.key"
      data-testid="benefit"
    >
      <span class="icon-tile" aria-hidden="true" data-testid="benefit-icon"><AppIcon :name="row.icon" :size="20" /></span>
      <span class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="t-body-m-strong" data-testid="benefit-name">{{ row.name }}</span>
        <span class="text-[13px] leading-[18px] text-muted" data-testid="benefit-note">{{ row.note }}</span>
      </span>
      <AppIcon name="check" :size="22" class="shrink-0 text-success" data-testid="benefit-check" />
    </li>
  </ul>
</template>
