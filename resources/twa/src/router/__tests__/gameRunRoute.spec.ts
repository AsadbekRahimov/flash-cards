import { describe, expect, it } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'
import router from '../index'

/** The props a route hands to its screen, for a location. */
function propsOf(path: string): Record<string, unknown> {
  const resolved = router.resolve(path)
  const record = resolved.matched[resolved.matched.length - 1]
  const props = record?.props.default
  if (typeof props !== 'function') throw new Error(`no props function for ${path}`)
  return (props as (route: RouteLocationNormalized) => Record<string, unknown>)(resolved as unknown as RouteLocationNormalized)
}

describe('router: a run of "Karvon" (docs/05 §5b.3)', () => {
  it('knows /game/runs/:id as game-run, lazy-loaded, with no tab (an immersive screen)', () => {
    const record = router.getRoutes().find((r) => r.name === 'game-run')

    expect(record).toBeDefined()
    expect(typeof record?.components?.default).toBe('function')
    expect(record?.meta.tab).toBeUndefined()
    expect(router.resolve({ name: 'game-run', params: { id: 9 } }).fullPath).toBe('/game/runs/9')
  })

  it('takes the id from the path, and the course and the stop from the query', () => {
    expect(propsOf('/game/runs/9')).toEqual({ id: 9, course: null, stop: null })
    expect(propsOf('/game/runs/9?course=english-a1&stop=41')).toEqual({ id: 9, course: 'english-a1', stop: 41 })
  })

  it('ignores a stop that is not a number and an empty course', () => {
    expect(propsOf('/game/runs/9?course=&stop=abc')).toEqual({ id: 9, course: null, stop: null })
    expect(propsOf('/game/runs/9?stop=0')).toEqual({ id: 9, course: null, stop: null })
  })

  it('a run is a number only', () => {
    expect(router.resolve('/game/runs/abc').name).not.toBe('game-run')
  })
})
