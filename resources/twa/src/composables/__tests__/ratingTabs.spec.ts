import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useRatingTabs } from '../ratingTabs'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { useAllTimeRatingStore } from '@/stores/allTimeRating'
import { useLeagueStore } from '@/stores/league'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'
import type { AllTimeResponse, LeagueResponse, MonthlyRatingResponse } from '@/api/types'

/*
 * The segments of the Rating tab (docs/32 §12.11): "All time" only with the word points v2, learned from what is on screen already -
 * nothing is asked for it - and gone after `enabled: false`.
 */

vi.mock('@/api', () => ({ api: { league: { get: vi.fn(), month: vi.fn(), allTime: vi.fn() } } }))

const testids = (): Array<string | undefined> => useRatingTabs().value.map((item) => item.testid)

describe('useRatingTabs', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    setLocale('ru')
  })

  it('no "All time" while nothing on screen is of the word points v2', () => {
    expect(testids()).toEqual(['seg-league', 'seg-month', 'seg-friends'])

    useLeagueStore().data = { enabled: true } as LeagueResponse
    useMonthlyRatingStore().data = { enabled: true } as MonthlyRatingResponse
    expect(testids()).toEqual(['seg-league', 'seg-month', 'seg-friends'])
  })

  it('"All time" after Month once the week or the month says rules_version 2; nothing is asked for it', () => {
    const tabs = useRatingTabs()
    useLeagueStore().data = { enabled: true, rules_version: 2 } as LeagueResponse
    expect(tabs.value.map((item) => item.testid)).toEqual(['seg-league', 'seg-month', 'seg-all-time', 'seg-friends'])
    expect(tabs.value[2]?.label).toBe('За всё время')
    expect(tabs.value[2]?.to).toEqual({ name: 'league-all-time' })

    setActivePinia(createPinia())
    useMonthlyRatingStore().data = { enabled: true, rules_version: 2 } as MonthlyRatingResponse
    expect(testids()).toContain('seg-all-time')

    expect(api.league.allTime).not.toHaveBeenCalled()
  })

  it('gone for the session once "All time" answered `enabled: false`, whatever the week says', () => {
    useLeagueStore().data = { enabled: true, rules_version: 2 } as LeagueResponse
    useAllTimeRatingStore().data = { enabled: false, period: 'all_time' } as AllTimeResponse

    expect(testids()).toEqual(['seg-league', 'seg-month', 'seg-friends'])
  })

  it('in Uzbek', () => {
    setLocale('uz')
    useLeagueStore().data = { enabled: true, rules_version: 2 } as LeagueResponse
    expect(useRatingTabs().value[2]?.label).toBe('Barcha vaqt')
  })
})
