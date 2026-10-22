import WebApp from '@twa-dev/sdk'
import type { Router } from 'vue-router'

/** Telegram allows up to 512 chars of `[A-Za-z0-9_-]` in a Mini App `startapp` parameter. */
const MAX_START_PARAM = 512
const MAX_PATH = 400

/**
 * Backend (`MiniAppLinks`) encodes the TWA path of a group-chat link — where a `web_app` button is
 * not allowed — as base64url without padding (`/training/15` -> `L3RyYWluaW5nLzE1`).
 * Returns the decoded path or null for anything that is not valid base64url / UTF-8.
 */
export function decodeStartParam(param: string | null | undefined): string | null {
  if (!param || param.length > MAX_START_PARAM || !/^[A-Za-z0-9_-]+$/.test(param)) return null
  try {
    const base64 = param.replace(/-/g, '+').replace(/_/g, '/')
    const binary = atob(base64 + '='.repeat((4 - (base64.length % 4)) % 4))
    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0))
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  } catch {
    return null
  }
}

/** Only an in-app absolute path: no scheme, no protocol-relative `//host`, no backslashes or control chars. */
export function isSafeTwaPath(path: string | null): path is string {
  if (path === null || path.length > MAX_PATH) return false
  if (!path.startsWith('/') || path.startsWith('//')) return false
  // eslint-disable-next-line no-control-regex
  if (path.includes('\\') || path.includes('//') || /[\u0000-\u001f\u007f]/.test(path)) return false
  return !/^\/*[a-z][a-z0-9+.-]*:/i.test(path)
}

/** The router target for a start param, or null when it is garbage or leads to the catch-all redirect. */
export function resolveStartTarget(router: Router, param: string | null | undefined): string | null {
  const path = decodeStartParam(param)
  if (!isSafeTwaPath(path)) return null

  const resolved = router.resolve(path)
  if (resolved.matched.length === 0 || resolved.matched.some((record) => record.redirect !== undefined)) return null
  // The landing view is where we are anyway, unless the link carries a query (`/?from=assignment&lesson=7`):
  // it has to survive, LandingView forwards it to Today.
  if (resolved.name === 'landing' && Object.keys(resolved.query).length === 0) return null

  return resolved.fullPath
}

/** `start_param` of the launch: Telegram SDK first, then `tgWebAppStartParam` of the URL (query or hash). */
export function readStartParam(): string | null {
  if (typeof window === 'undefined') return null

  const fromSdk = window.Telegram?.WebApp ? WebApp.initDataUnsafe?.start_param : undefined
  if (typeof fromSdk === 'string' && fromSdk !== '') return fromSdk

  const fromSearch = new URLSearchParams(window.location.search).get('tgWebAppStartParam')
  if (fromSearch) return fromSearch

  const hash = window.location.hash.replace(/^#/, '')
  return new URLSearchParams(hash.includes('?') ? hash.slice(hash.indexOf('?') + 1) : hash).get('tgWebAppStartParam')
}

/**
 * Follows the start param once: on the first navigation, if the app was opened at the landing route.
 * Later navigations to `/` are left alone, so the user is not bounced back.
 */
export function installStartParamRedirect(router: Router, read: () => string | null = readStartParam): void {
  let consumed = false

  router.beforeEach((to) => {
    if (consumed) return true
    consumed = true

    if (to.name !== 'landing') return true

    const target = resolveStartTarget(router, read())

    if (target === null) return true

    // Resolve to keep the query separate from the path (a `?` inside `path` would be encoded).
    const { path, query, hash } = router.resolve(target)

    return { path, query, hash, replace: true }
  })
}
