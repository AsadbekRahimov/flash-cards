import { describe, expect, it } from 'vitest'
import router from '../index'

/** The screens that belong to a tab of the bottom navigation (docs/14 §5.1). */
const TABS = {
  today: ['today'],
  courses: ['courses', 'course', 'decks', 'deck'],
  rating: ['league', 'friends'],
  profile: ['profile', 'achievements', 'referrals', 'billing', 'settings'],
} as const

const NO_TAB = ['landing', 'training', 'finished', 'exam', 'exam-result']

const record = (name: string) => router.getRoutes().find((r) => r.name === name)

describe('router: meta.tab', () => {
  it.each(Object.entries(TABS).flatMap(([tab, names]) => names.map((name) => [name, tab] as const)))(
    '%s belongs to the %s tab',
    (name, tab) => {
      expect(record(name)?.meta.tab).toBe(tab)
    },
  )

  it.each(NO_TAB)('%s has no tab (no tab bar)', (name) => {
    expect(record(name)).toBeDefined()
    expect(record(name)?.meta.tab).toBeUndefined()
  })

  it('has /profile, lazy-loaded', () => {
    const profile = record('profile')

    expect(profile?.path).toBe('/profile')
    expect(typeof profile?.components?.default).toBe('function')
  })

  it('resolves the tab of an inner route from its location', () => {
    expect(router.resolve({ name: 'course', params: { slug: 'ielts-core' } }).meta.tab).toBe('courses')
    expect(router.resolve('/friends').meta.tab).toBe('rating')
    expect(router.resolve('/training/7').meta.tab).toBeUndefined()
  })

  it('every route that names a tab is one of the four', () => {
    const allowed = new Set(['today', 'courses', 'rating', 'profile'])
    for (const r of router.getRoutes()) {
      if (r.meta.tab !== undefined) expect(allowed.has(r.meta.tab), String(r.name)).toBe(true)
    }
  })
})
