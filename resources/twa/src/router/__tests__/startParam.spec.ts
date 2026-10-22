import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { decodeStartParam, installStartParamRedirect, isSafeTwaPath, resolveStartTarget } from '../startParam'

const b64url = (text: string) =>
  btoa(String.fromCharCode(...new TextEncoder().encode(text))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

const stub = { template: '<div />' }

function makeRouter() {
  return createRouter({
    history: createMemoryHistory('/twa/'),
    routes: [
      { path: '/', name: 'landing', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
      { path: '/exam/:sessionId(\\d+)', name: 'exam', component: stub },
      { path: '/today', name: 'today', component: stub },
      { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
  })
}

describe('decodeStartParam', () => {
  it('decodes base64url without padding', () => {
    expect(decodeStartParam(b64url('/training/15'))).toBe('/training/15')
    expect(decodeStartParam(b64url('/?from=assignment&lesson=7'))).toBe('/?from=assignment&lesson=7')
  })

  it.each([null, undefined, '', 'not base64!', '@@@', 'a'.repeat(513)])('rejects %j', (value) => {
    expect(decodeStartParam(value as string | null)).toBeNull()
  })

  it('rejects bytes that are not UTF-8', () => {
    expect(decodeStartParam('_w')).toBeNull()
  })
})

describe('isSafeTwaPath', () => {
  it.each(['/training/1', '/', '/today?from=x'])('accepts %s', (path) => {
    expect(isSafeTwaPath(path)).toBe(true)
  })

  it.each([null, '', 'training/1', '//evil.com', '/\\evil.com', 'https://evil.com', 'javascript:alert(1)', '/x//y', '/a\nb'])(
    'rejects %j',
    (path) => {
      expect(isSafeTwaPath(path)).toBe(false)
    },
  )
})

describe('resolveStartTarget', () => {
  it('returns the full path of a known route', () => {
    expect(resolveStartTarget(makeRouter(), b64url('/exam/7'))).toBe('/exam/7')
  })

  it('ignores garbage, foreign hosts and unknown routes (catch-all)', () => {
    const router = makeRouter()
    expect(resolveStartTarget(router, 'zzz')).toBeNull()
    expect(resolveStartTarget(router, b64url('//evil.com'))).toBeNull()
    expect(resolveStartTarget(router, b64url('https://evil.com/training/1'))).toBeNull()
    expect(resolveStartTarget(router, b64url('/no/such/page'))).toBeNull()
    expect(resolveStartTarget(router, b64url('/training/abc'))).toBeNull()
  })
})

describe('installStartParamRedirect', () => {
  it('redirects the first landing navigation once', async () => {
    const router = makeRouter()
    installStartParamRedirect(router, () => b64url('/training/15'))

    await router.push('/')
    expect(router.currentRoute.value.fullPath).toBe('/training/15')

    await router.push('/')
    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('does nothing without a param or with a bad one', async () => {
    const router = makeRouter()
    installStartParamRedirect(router, () => 'zzz')
    await router.push('/')
    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('does not override an explicit first route', async () => {
    const router = makeRouter()
    installStartParamRedirect(router, () => b64url('/training/15'))
    await router.push('/today')
    expect(router.currentRoute.value.fullPath).toBe('/today')
  })
})
