import type { RequestOptions } from './client'

/**
 * A best-effort read (docs/40 §2.6, F5 "Нет сети"): the screen does without it, so its loss to the network does not raise "Нет соединения"
 * (see `RequestOptions.background`). The reads that are sometimes the load of a screen and sometimes such an extra take `options`; the
 * caller passes this one when the read is the extra. A module of its own, so a test that mocks `@/api` keeps it.
 */
export const BACKGROUND: RequestOptions = { background: true }
