import { describe, expect, it } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'
import router from '../index'
import { resolveStartTarget } from '../startParam'

const b64url = (text: string) =>
  btoa(String.fromCharCode(...new TextEncoder().encode(text))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

describe('router: the caravan of a class (docs/05 §5b.7)', () => {
  it('knows /game/class/:id as game-class on the Courses tab, lazy-loaded, with the id as a number', () => {
    const resolved = router.resolve('/game/class/5')
    const record = resolved.matched[resolved.matched.length - 1]
    const props = record?.props.default

    expect(resolved.name).toBe('game-class')
    expect(resolved.meta.tab).toBe('courses')
    expect(typeof record?.components?.default).toBe('function')
    expect((props as (route: RouteLocationNormalized) => unknown)(resolved as unknown as RouteLocationNormalized)).toEqual({ id: 5 })
    expect(router.resolve({ name: 'game-class', params: { id: 5 } }).fullPath).toBe('/game/class/5')
  })

  it('does not take an id that is not a number', () => {
    expect(router.resolve('/game/class/abc').name).not.toBe('game-class')
  })

  it('opens from the start_param of the link the bot sends to the group chat', () => {
    expect(resolveStartTarget(router, b64url('/game/class/5'))).toBe('/game/class/5')
  })
})
