<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import RegionSheet from '@/components/RegionSheet.vue'
import { formatDate } from '@/composables/format'
import { useSettingsStore } from '@/stores/settings'
import { useI18n } from '@/i18n'
import type { BoardRegion } from '@/api/types'

/**
 * What a table of a region says above itself (docs/35 §2.3–§2.4, docs/32 §12.11):
 *
 * - `notSet` — the learner has no region (422 `region_not_set`, or `me.region` null over the table of "my region"): the card
 *   "Choose your region to take part" with the region sheet that SAVES the choice (the one of the Rating tab, `region_via = prompt`;
 *   another region than the one dropped less than 30 days ago is refused - the date is said when the server sent it);
 * - `region` with `shown: false` — fewer than `min_participants`: "N of M participants so far", the way to the invitations, no rows;
 * - `foreign` — the table of a region that is not the learner's (read only): "This is the table of X. You compete in Y" (`mine`, null:
 *   "you have not chosen your region").
 *
 * The numbers are the server's.
 */
const props = defineProps<{
  notSet: boolean
  region: BoardRegion | null
  foreign: boolean
  mine: string | null
}>()

const { t, tn, locale } = useI18n()
const settings = useSettingsStore()

const sheetOpen = ref(false)

const below = computed(() => !props.notSet && props.region !== null && !props.region.shown)

/** "You can change the region from 24 Dec.": a region dropped less than 30 days ago cannot be replaced yet. */
const nextChange = computed(() => {
  const at = settings.data?.region_change_available_at
  if (!at || Date.parse(at) <= Date.now()) return ''
  return t('settings.region.next_change', { date: formatDate(at, 'dayMonthShortYear', locale.value) })
})

const foreignLine = computed(() => {
  const region = props.region
  if (props.notSet || !props.foreign || region === null) return ''
  return props.mine ? t('board.read_only_region', { name: region.name, mine: props.mine }) : t('board.read_only_region_none', { name: region.name })
})
</script>

<template>
  <section v-if="notSet" class="card space-y-3" data-testid="region-not-set">
    <div class="space-y-1">
      <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('board.region_card.title') }}</h2>
      <p class="text-[15px] leading-5 text-muted">{{ t('settings.region.hint') }}</p>
      <p v-if="nextChange" class="text-[13px] leading-[18px] text-muted" data-testid="region-next-change">{{ nextChange }}</p>
    </div>
    <button type="button" class="btn btn-primary btn-block" data-testid="region-choose" @click="sheetOpen = true">{{ t('region.prompt.choose') }}</button>
  </section>

  <p v-if="foreignLine" class="card text-[15px] leading-[22px] text-muted" data-testid="read-only-region">{{ foreignLine }}</p>

  <section v-if="below && region" class="card space-y-3" data-testid="region-below">
    <div class="space-y-1">
      <h2 class="text-[17px] leading-[22px] font-semibold" data-testid="region-below-title">
        {{ tn('board.below.title', region.min_participants, { name: region.name, participants: region.participants }) }}
      </h2>
      <p class="text-[15px] leading-5 text-muted">{{ t('board.below.text', { min: region.min_participants }) }}</p>
    </div>
    <RouterLink :to="{ name: 'referrals' }" class="btn btn-secondary btn-block" data-testid="region-invite">{{ t('board.below.invite') }}</RouterLink>
  </section>

  <RegionSheet v-if="sheetOpen" via="prompt" @close="sheetOpen = false" />
</template>
