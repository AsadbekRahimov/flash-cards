import { describe, expect, it } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'
import router from '../index'
import { resolveStartTarget } from '../startParam'

/** The props a route hands to its screen, for a location. */
function propsOf(path: string): Record<string, unknown> {
  const resolved = router.resolve(path)
  const record = resolved.matched[resolved.matched.length - 1]
  const props = record?.props.default
  if (typeof props !== 'function') throw new Error(`no props function for ${path}`)
  return (props as (route: RouteLocationNormalized) => Record<string, unknown>)(resolved as unknown as RouteLocationNormalized)
}

describe('router: practice and battle (docs/16 §11.1)', () => {
  it('knows the four routes, each lazy-loaded', () => {
    for (const name of ['practice-hub', 'practice-session', 'battle-new', 'battle']) {
      const record = router.getRoutes().find((r) => r.name === name)
      expect(record, name).toBeDefined()
      expect(typeof record?.components?.default, name).toBe('function')
    }
  })

  it('the hub: /practice/:slug with the lesson in the query', () => {
    expect(router.resolve({ name: 'practice-hub', params: { slug: 'english-a1' }, query: { lesson: '41' } }).fullPath).toBe('/practice/english-a1?lesson=41')
    expect(router.resolve('/practice/english-a1?lesson=41').name).toBe('practice-hub')
    expect(propsOf('/practice/english-a1?lesson=41')).toEqual({ slug: 'english-a1', lessonId: 41 })
  })

  it('the hub of a whole course has no lesson; a lesson that is not a number is none', () => {
    expect(propsOf('/practice/english-a1')).toEqual({ slug: 'english-a1', lessonId: null })
    expect(propsOf('/practice/english-a1?lesson=abc')).toEqual({ slug: 'english-a1', lessonId: null })
    expect(propsOf('/practice/english-a1?lesson=0')).toEqual({ slug: 'english-a1', lessonId: null })
    expect(propsOf('/practice/english-a1?lesson=-3')).toEqual({ slug: 'english-a1', lessonId: null })
    expect(propsOf('/practice/english-a1?lesson=4.5')).toEqual({ slug: 'english-a1', lessonId: null })
  })

  it('a session: /practice/sessions/:id, a number only', () => {
    expect(router.resolve('/practice/sessions/77').name).toBe('practice-session')
    expect(propsOf('/practice/sessions/77')).toEqual({ id: 77 })
    // two segments after /practice that are not a session fit no route: the catch-all sends them to the landing
    expect(router.resolve('/practice/sessions/abc').name).toBeUndefined()
  })

  it('a course called "sessions" still has its hub', () => {
    expect(router.resolve('/practice/sessions').name).toBe('practice-hub')
  })

  it('the setup of a battle takes the friend, the course and the lesson from the query', () => {
    expect(router.resolve('/battles/new?friendship=11&course=english-a1&lesson=41').name).toBe('battle-new')
    expect(propsOf('/battles/new?friendship=11&course=english-a1&lesson=41')).toEqual({ friendshipId: 11, course: 'english-a1', lessonId: 41 })
    expect(propsOf('/battles/new')).toEqual({ friendshipId: null, course: null, lessonId: null })
  })

  it('a battle: /battles/:id (the address of the invitation message of the bot)', () => {
    expect(router.resolve('/battles/15').name).toBe('battle')
    expect(propsOf('/battles/15')).toEqual({ id: 15 })
    expect(router.resolve('/battles/new').name).toBe('battle-new')
  })

  it('a link from a group chat (start param) can lead to a battle, a hub and a session', () => {
    // docs/16 §8.7: `startapp` carries the path as base64url; the router resolves it like any other
    const param = (path: string): string => btoa(path).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

    expect(resolveStartTarget(router, param('/battles/15'))).toBe('/battles/15')
    expect(resolveStartTarget(router, param('/practice/english-a1?lesson=41'))).toBe('/practice/english-a1?lesson=41')
    expect(resolveStartTarget(router, param('/practice/sessions/77'))).toBe('/practice/sessions/77')
  })
})
