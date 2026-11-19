import WebApp from '@twa-dev/sdk'
import { api } from '@/api'
import { readStartParam } from '@/router/startParam'

/**
 * Timing marks of one open of the Mini App (docs/04 A.21; audit 2026-11-18, «Как увидеть выигрыш»): how long the learner waits for
 * the primary button of «Сегодня», how long they need to understand it, when the first answer is accepted and the first word sounds.
 *
 * - `startTimingMarks()` runs first thing in `main.ts`: it gives the open a random `open_id` (a uuid of this open, never of the device),
 *   reads the platform (`Telegram.WebApp.platform`, normalised to a closed list), cold / warm and the entry, and listens for the page
 *   being hidden. Before it nothing is recorded: `markTiming()` is a no-op (the specs of the screens never start it).
 * - `markTiming(name)` keeps the offset of the first call of each mark, in whole milliseconds from the start of the open: the navigation
 *   start of the page (`performance.now()` is relative to `performance.timeOrigin`), so the network and the loading of the bundle count.
 * - The share of opens that report comes from `POST /auth` (`client_timing.sample_percent`, `setTimingSampleRate()`); an open is in the
 *   sample when crc32(`client_timing:<open_id>`) % 100 < the share - the server applies the same rule (`ClientTimingRecorder`). No share
 *   (an older server) or 0: nothing is ever sent.
 * - ONE send per open: right after the first answer is acknowledged, or when the page is hidden (`visibilitychange` → hidden,
 *   `pagehide`) with whatever marks exist by then. `fetch` with `keepalive` so that it survives the closing of the app (`sendBeacon` cannot
 *   carry the token). Nothing waits for it, a failure is swallowed, there is no retry.
 */

/** The salt of the per-open sample; the server hashes the same string (`ClientTimingRecorder::SAMPLE_SALT`). */
export const SAMPLE_SALT = 'client_timing'

export const TIMING_MARKS = ['auth_ready', 'today_received', 'button_ready', 'first_tap', 'first_answer_ack', 'first_audio'] as const
export type TimingMark = (typeof TIMING_MARKS)[number]

/** `Telegram.WebApp.platform` values sent as they are; anything else goes as `other`. */
export const TIMING_PLATFORMS = ['android', 'ios', 'tdesktop', 'macos', 'web', 'weba', 'webk', 'unknown'] as const

export type TimingEntry = 'home' | 'deep_link' | 'other'

/** The ceiling of an offset the server accepts (ten minutes): a later mark is not kept. */
export const MAX_OFFSET_MS = 600_000

/** Set in this tab's session storage by the first open: a later open of the same webview is a warm one. */
export const OPENED_KEY = 'lexiflow:timing-opened'

export interface ClientTimingPayload {
  open_id: string
  platform: string
  cold: boolean
  entry: TimingEntry
  auth_ready?: number
  today_received?: number
  button_ready?: number
  first_tap?: number
  first_answer_ack?: number
  first_audio?: number
}

export interface TimingOptions {
  /** Milliseconds since the start of the open (default `performance.now()`). */
  now?: () => number
  openId?: string
  platform?: string
  cold?: boolean
  entry?: TimingEntry
  /** The request (default `api.telemetry.clientTiming`). */
  send?: (payload: ClientTimingPayload) => Promise<unknown>
}

interface OpenState {
  openId: string
  platform: string
  cold: boolean
  entry: TimingEntry
  marks: Partial<Record<TimingMark, number>>
  /** The share of `/auth`; null until it is known. */
  rate: number | null
  done: boolean
  now: () => number
  send: (payload: ClientTimingPayload) => Promise<unknown>
  stop: () => void
}

let open: OpenState | null = null

/** Starts the marks of this open; a second call does nothing. Never throws. */
export function startTimingMarks(options: TimingOptions = {}): void {
  if (open !== null) return
  try {
    const onHidden = (): void => {
      if (typeof document === 'undefined' || document.visibilityState === 'hidden') flushTiming()
    }
    const onPageHide = (): void => flushTiming()
    open = {
      openId: (options.openId ?? newOpenId()).toLowerCase(),
      platform: normalizePlatform(options.platform ?? telegramPlatform()),
      cold: options.cold ?? isColdOpen(),
      entry: options.entry ?? entryOfLaunch(),
      marks: {},
      rate: null,
      done: false,
      now: options.now ?? (() => performance.now()),
      send: options.send ?? ((payload) => api.telemetry.clientTiming(payload)),
      stop: () => {
        document.removeEventListener('visibilitychange', onHidden)
        window.removeEventListener('pagehide', onPageHide)
      },
    }
    document.addEventListener('visibilitychange', onHidden)
    window.addEventListener('pagehide', onPageHide)
  } catch {
    open = null
  }
}

/** Keeps the offset of `name` the first time it is called in this open; `first_answer_ack` sends the marks. Never throws. */
export function markTiming(name: TimingMark): void {
  const current = open
  if (current === null || current.done || current.marks[name] !== undefined) return
  try {
    const offset = Math.round(current.now())
    if (!Number.isFinite(offset) || offset < 0 || offset > MAX_OFFSET_MS) return
    current.marks[name] = offset
    if (name === 'first_answer_ack') flushTiming()
  } catch {
    // a broken clock: no mark
  }
}

/** The share of opens that report (0..100), from `POST /auth`; `undefined` / `null` (an older server) means: never send. */
export function setTimingSampleRate(percent: number | null | undefined): void {
  if (open === null) return
  open.rate = typeof percent === 'number' && Number.isFinite(percent) ? Math.max(0, Math.min(100, Math.floor(percent))) : null
}

/** Whether `openId` is in the sample of `percent` (the server's rule). */
export function inTimingSample(openId: string, percent: number): boolean {
  return percent > 0 && crc32(`${SAMPLE_SALT}:${openId.toLowerCase()}`) % 100 < percent
}

/**
 * Sends the marks of this open once, if it is in the sample. Without the share (no `/auth` answer yet) or without any mark nothing is
 * sent and a later moment may still send; an open outside the sample is done for good.
 */
export function flushTiming(): void {
  const current = open
  if (current === null || current.done) return
  try {
    if (current.rate === null) return
    if (!inTimingSample(current.openId, current.rate)) {
      current.done = true
      return
    }
    if (Object.keys(current.marks).length === 0) return
    current.done = true
    const payload: ClientTimingPayload = {
      open_id: current.openId,
      platform: current.platform,
      cold: current.cold,
      entry: current.entry,
      ...current.marks,
    }
    void current.send(payload).catch(() => {
      // analytics only: the learner never sees it
    })
  } catch {
    // nothing to send, nothing to break
  }
}

/** Tests: forget the open and its listeners. */
export function resetTimingMarks(): void {
  try {
    open?.stop()
  } catch {
    // ignore
  }
  open = null
}

/** Tests: the marks of the open so far. */
export function timingMarks(): Partial<Record<TimingMark, number>> {
  return { ...(open?.marks ?? {}) }
}

export function normalizePlatform(platform: string | null | undefined): string {
  if (!platform) return 'unknown'
  return (TIMING_PLATFORMS as readonly string[]).includes(platform) ? platform : 'other'
}

function telegramPlatform(): string | null {
  try {
    return typeof window !== 'undefined' && window.Telegram?.WebApp ? (WebApp.platform ?? null) : null
  } catch {
    return null
  }
}

/** Cold: the first open in this webview's session (no marker yet) that is not a reload or a history step. */
function isColdOpen(): boolean {
  let seen = false
  try {
    seen = window.sessionStorage.getItem(OPENED_KEY) !== null
    window.sessionStorage.setItem(OPENED_KEY, '1')
  } catch {
    // no storage: judged by the navigation alone
  }
  let repeat = false
  try {
    const nav = performance.getEntriesByType?.('navigation')?.[0] as PerformanceNavigationTiming | undefined
    repeat = nav?.type === 'reload' || nav?.type === 'back_forward'
  } catch {
    // no navigation timing
  }
  return !seen && !repeat
}

/**
 * How the app was entered: `deep_link` - a link that names a screen (`start_param` of a group-chat link) or a reminder / announcement
 * button (`?from=…`); `home` - the bare app (the landing or «Сегодня»); `other` - any other screen opened directly.
 */
function entryOfLaunch(): TimingEntry {
  try {
    if (readStartParam() !== null || new URLSearchParams(window.location.search).has('from')) return 'deep_link'
    const path = window.location.pathname.replace(/^\/twa(?=\/|$)/, '').replace(/\/+$/, '')
    return path === '' || path === '/today' ? 'home' : 'other'
  } catch {
    return 'other'
  }
}

/** A random v4 uuid: `crypto.randomUUID` where there is one, else built from `getRandomValues`. */
function newOpenId(): string {
  const c = typeof crypto !== 'undefined' ? crypto : undefined
  if (c?.randomUUID) return c.randomUUID()
  const bytes = new Uint8Array(16)
  if (c?.getRandomValues) c.getRandomValues(bytes)
  else for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256)
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

let crcTable: Uint32Array | null = null

/** CRC-32 (IEEE, as PHP's `crc32()`) of the UTF-8 bytes of `text`, unsigned. */
export function crc32(text: string): number {
  if (crcTable === null) {
    crcTable = new Uint32Array(256)
    for (let n = 0; n < 256; n++) {
      let c = n
      for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
      crcTable[n] = c >>> 0
    }
  }
  let crc = 0xffffffff
  for (const byte of new TextEncoder().encode(text)) crc = crcTable[(crc ^ byte) & 0xff] ^ (crc >>> 8)
  return (crc ^ 0xffffffff) >>> 0
}
