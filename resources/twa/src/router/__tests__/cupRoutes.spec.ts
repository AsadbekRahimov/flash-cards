import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'
import router from '../index'
import { resolveStartTarget } from '../startParam'

/**
 * The screens the bot's buttons open (`App\Domain\Telegram\Support\TwaScreen`, docs/36 §3б) must be routes of the TWA: a case added on the
 * server without its route would land on the catch-all (the landing). The PHP enum is read as text (vitest runs with cwd = resources/twa).
 */

const SCREEN_FILE = join(process.cwd(), '..', '..', 'app', 'Domain', 'Telegram', 'Support', 'TwaScreen.php')

/** `case CupInvite = '/cups/c/{code}';` -> ['CupInvite', '/cups/c/{code}']. */
function twaScreens(): Array<[string, string]> {
  const code = readFileSync(SCREEN_FILE, 'utf8')
  return [...code.matchAll(/^\s*case\s+(\w+)\s*=\s*'([^']*)'\s*;/gm)].map((match) => [match[1] ?? '', match[2] ?? ''])
}

/** A value for a `{name}` of a path: a number for an id, a code of 22 base64url characters, a word otherwise. */
function sample(name: string): string {
  if (name === 'id') return '5'
  if (name === 'code') return 'AbCdEfGhIjKlMnOpQrSt_-'
  return 'english-a1'
}

const b64url = (text: string) =>
  btoa(String.fromCharCode(...new TextEncoder().encode(text))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

const lastRecord = (path: string) => {
  const resolved = router.resolve(path)
  return { resolved, record: resolved.matched[resolved.matched.length - 1] }
}

describe('router: every TwaScreen of the bot is a route', () => {
  const screens = twaScreens()

  it('reads the cases of the enum', () => {
    expect(screens.length).toBeGreaterThan(10)
    expect(screens).toContainEqual(['CupInvite', '/cups/c/{code}'])
    expect(screens).toContainEqual(['CupPlay', '/cups/c/{code}/play'])
    expect(screens).toContainEqual(['CupSetup', '/cups/setup/{code}'])
  })

  it.each(twaScreens())('%s (%s) resolves to a route, not the catch-all', (_name, path) => {
    const concrete = path.replace(/\{(\w+)\}/g, (_match, name: string) => sample(name))
    const { resolved, record } = lastRecord(concrete)

    expect(record).toBeDefined()
    expect(record?.redirect).toBeUndefined()
    expect(resolved.path).toBe(concrete)
    // The same shape as the route's own path: the static parts equal, a parameter where the enum has one (its name and pattern are the route's).
    const shape = (text: string) => text.replace(/\([^)]*\)/g, '').replace(/:\w+|\{\w+\}/g, ':param')
    expect(shape(record?.path ?? '')).toBe(shape(path))
  })
})

describe('router: the friends cup (docs/33 §7.1)', () => {
  const props = (path: string) => {
    const { resolved, record } = lastRecord(path)
    const fn = record?.props.default as (route: RouteLocationNormalized) => unknown
    return { name: resolved.name, tab: resolved.meta.tab, props: fn(resolved as unknown as RouteLocationNormalized), lazy: typeof record?.components?.default === 'function' }
  }

  it('the invitation, the play link, the setup, the cup, its round and the list', () => {
    expect(props('/cups/c/AbCdEfGhIjKlMnOpQrSt_-')).toEqual({ name: 'cup-invite', tab: 'rating', props: { code: 'AbCdEfGhIjKlMnOpQrSt_-', play: false, rules: false }, lazy: true })
    expect(props('/cups/c/AbCdEfGhIjKlMnOpQrSt_-?view=rules').props).toEqual({ code: 'AbCdEfGhIjKlMnOpQrSt_-', play: false, rules: true })
    expect(props('/cups/c/AbCdEfGhIjKlMnOpQrSt_-/play')).toEqual({ name: 'cup-play', tab: 'rating', props: { code: 'AbCdEfGhIjKlMnOpQrSt_-', play: true, rules: false }, lazy: true })
    expect(props('/cups/setup/AbCdEfGhIjKlMnOpQrSt_-')).toEqual({ name: 'cup-setup', tab: 'rating', props: { code: 'AbCdEfGhIjKlMnOpQrSt_-' }, lazy: true })
    expect(props('/cups/7')).toEqual({ name: 'cup', tab: 'rating', props: { id: 7 }, lazy: true })
    // The round is immersive: no tab bar.
    expect(props('/cups/7/round')).toEqual({ name: 'cup-round', tab: undefined, props: { id: 7 }, lazy: true })
    expect(router.resolve('/cups').name).toBe('cups')
  })

  it('a cup id that is not a number is not the cup', () => {
    expect(router.resolve('/cups/abc').name).not.toBe('cup')
  })

  it('opens from the start_param of the url button in the group (`?from=` survives)', () => {
    expect(resolveStartTarget(router, b64url('/cups/c/AbCdEfGhIjKlMnOpQrSt_-?from=cup_card'))).toBe('/cups/c/AbCdEfGhIjKlMnOpQrSt_-?from=cup_card')
    expect(resolveStartTarget(router, b64url('/cups/c/AbCdEfGhIjKlMnOpQrSt_-/play'))).toBe('/cups/c/AbCdEfGhIjKlMnOpQrSt_-/play')
    expect(resolveStartTarget(router, b64url('/cups/setup/AbCdEfGhIjKlMnOpQrSt_-'))).toBe('/cups/setup/AbCdEfGhIjKlMnOpQrSt_-')
  })
})
