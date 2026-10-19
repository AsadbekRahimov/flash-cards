export interface SupportContactLink {
  /** Text to show, exactly as configured. */
  label: string
  /** Safe link target, or null when the contact is plain text (then it is shown without a link). */
  href: string | null
}

const EMAIL = /^[^\s@<>"']+@[^\s@<>"']+\.[^\s@<>"']+$/
const TELEGRAM_HANDLE = /^@([A-Za-z][A-Za-z0-9_]{3,31})$/
const HTTP_URL = /^https?:\/\/\S+$/i

/**
 * Turns the owner-configured `paywall.contact` (BILLING_SUPPORT_CONTACT) into something clickable,
 * with the same rules as the public site (SiteLinks::supportContact): `@handle` → t.me, e-mail →
 * mailto:, http(s) URL → as is; anything else (including `javascript:`/`data:` strings) stays
 * plain text. The result is bound with `:href`/`{{ }}`, never `v-html`.
 */
export function supportContactLink(contact: string | null | undefined): SupportContactLink | null {
  const raw = (contact ?? '').trim()
  if (raw === '') return null

  const handle = TELEGRAM_HANDLE.exec(raw)
  if (handle) return { label: raw, href: `https://t.me/${handle[1]}` }

  if (EMAIL.test(raw)) return { label: raw, href: `mailto:${raw}` }

  if (HTTP_URL.test(raw) && isHttpUrl(raw)) return { label: raw, href: raw }

  return { label: raw, href: null }
}

function isHttpUrl(value: string): boolean {
  try {
    const url = new URL(value)
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}
