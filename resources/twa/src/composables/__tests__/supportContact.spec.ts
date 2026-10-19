import { describe, expect, it } from 'vitest'
import { supportContactLink } from '../supportContact'

describe('supportContactLink', () => {
  it.each([null, undefined, '', '   '])('has no contact for %j', (value) => {
    expect(supportContactLink(value)).toBeNull()
  })

  it('links a Telegram handle to t.me', () => {
    expect(supportContactLink('@lexiflow_help')).toEqual({ label: '@lexiflow_help', href: 'https://t.me/lexiflow_help' })
    expect(supportContactLink('  @lexiflow_help ')).toEqual({ label: '@lexiflow_help', href: 'https://t.me/lexiflow_help' })
  })

  it('links an e-mail address with mailto:', () => {
    expect(supportContactLink('help@lexiflow.uz')).toEqual({ label: 'help@lexiflow.uz', href: 'mailto:help@lexiflow.uz' })
  })

  it('keeps an http(s) URL as is', () => {
    expect(supportContactLink('https://lexiflow.uz/support')).toEqual({
      label: 'https://lexiflow.uz/support',
      href: 'https://lexiflow.uz/support',
    })
    expect(supportContactLink('http://example.com')?.href).toBe('http://example.com')
  })

  it.each([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'ftp://example.com/x',
    '//evil.example/x',
    '+998 90 123 45 67',
    'Звоните в офис',
    '@ab',
  ])('shows %j as plain text, never as a link', (value) => {
    const link = supportContactLink(value)

    expect(link).not.toBeNull()
    expect(link?.href).toBeNull()
    expect(link?.label).toBe(value)
  })
})
