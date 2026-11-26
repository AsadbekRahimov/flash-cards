import { computed } from 'vue'
import { useI18n } from '@/i18n'
import { useAllTimeRatingStore } from '@/stores/allTimeRating'
import { useLeagueStore } from '@/stores/league'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'

/**
 * The switch of the "Rating" tab: Week (the league table of the week) | Month | All time | Friends (each pane is a route of its own, docs/14 §6.7).
 * "All time" (docs/32 §12.11) is there only with the word points v2: nothing is asked to learn it - the week or the month already on
 * screen says `rules_version: 2` (or "All time" itself was answered), and an answer `enabled: false` hides it for the session.
 */
export function useRatingTabs() {
  const { t } = useI18n()
  const league = useLeagueStore()
  const month = useMonthlyRatingStore()
  const allTime = useAllTimeRatingStore()

  const allTimeShown = computed(() => {
    if (allTime.disabled) return false
    if (allTime.data?.enabled === true) return true
    return (league.data?.rules_version ?? 0) >= 2 || (month.data?.rules_version ?? 0) >= 2
  })

  return computed(() => [
    { to: { name: 'league' }, label: t('league.tab'), testid: 'seg-league' },
    { to: { name: 'league-month' }, label: t('month.tab'), testid: 'seg-month' },
    ...(allTimeShown.value ? [{ to: { name: 'league-all-time' }, label: t('alltime.tab'), testid: 'seg-all-time' }] : []),
    { to: { name: 'friends' }, label: t('friends.title'), testid: 'seg-friends' },
  ])
}
