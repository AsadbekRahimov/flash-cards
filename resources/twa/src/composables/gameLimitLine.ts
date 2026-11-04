import { computed, type ComputedRef } from 'vue'
import { shownLimit } from '@/composables/practiceLimits'
import { useI18n } from '@/i18n'
import { useBillingStore } from '@/stores/billing'

/**
 * The line of the Free limit under the start of a run (the sheet of a stop, the screen of a boss; docs/05 §5b.2): "Free today: 1 of 1 ·
 * tickets: 2". Only where the server told the limits - nothing is made up (`null` for Pro and until G2.8). With no free runs at all
 * (`GAME_FREE_RUNS_PER_DAY=0`: a limit of 0 is not shown as "0 of 0") the tickets are still the learner's way in, so their balance stays
 * on the line.
 */
export function useGameLimitLine(): ComputedRef<string> {
  const billing = useBillingStore()
  const { t } = useI18n()

  return computed((): string => {
    const limits = billing.gameLimits
    if (limits === null || limits.runs === null) return ''
    const runs = shownLimit(limits.runs)
    if (runs === null) return limits.tickets ? t('game.stop.tickets_only', { count: limits.tickets.balance }) : ''
    const free = t('game.stop.free_today', { remaining: Math.max(0, runs.remaining), limit: runs.limit })
    return limits.tickets ? `${free} · ${t('game.stop.tickets', { count: limits.tickets.balance })}` : free
  })
}
