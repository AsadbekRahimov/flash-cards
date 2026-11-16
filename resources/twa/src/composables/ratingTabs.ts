import { computed } from 'vue'
import { useI18n } from '@/i18n'

/** The switch of the "Rating" tab: League | Month | Friends (each pane is a route of its own, docs/14 §6.7). */
export function useRatingTabs() {
  const { t } = useI18n()
  return computed(() => [
    { to: { name: 'league' }, label: t('league.title'), testid: 'seg-league' },
    { to: { name: 'league-month' }, label: t('month.tab'), testid: 'seg-month' },
    { to: { name: 'friends' }, label: t('friends.title'), testid: 'seg-friends' },
  ])
}
