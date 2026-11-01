/**
 * A fresh uuid v4 for the `request_id` of a request that may have to be repeated (docs/16 §6.3, §12): the server keeps
 * the first result of a `request_id` and answers a repeat with it, so a repeat after a lost reply changes nothing.
 *
 * `crypto.randomUUID` where the WebView has it (secure contexts, Safari 15.4+, Chrome 92+), else the same bits from
 * `getRandomValues`, else `Math.random` (an idempotency key needs no secrecy, only to be unique).
 */
export function newRequestId(): string {
  const source = globalThis.crypto
  if (typeof source?.randomUUID === 'function') return source.randomUUID()

  const bytes = new Uint8Array(16)
  if (typeof source?.getRandomValues === 'function') source.getRandomValues(bytes)
  else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256)

  bytes[6] = (bytes[6]! & 0x0f) | 0x40 // version 4
  bytes[8] = (bytes[8]! & 0x3f) | 0x80 // variant 10xx
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'))
  return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`
}
