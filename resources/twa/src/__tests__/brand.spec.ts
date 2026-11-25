import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { BRAND } from '@/brand'
import { ru } from '@/i18n/ru'
import { uz } from '@/i18n/uz'

// docs/36 §5б: the product name lives in one place per side; the i18n texts carry the word themselves, so a guard keeps
// the old name out of them — the next rename is one replace in the dictionaries plus one env value.
const OLD_BRAND = /lexiflow/i

function strings(value: unknown, path = ''): Array<[string, string]> {
  if (typeof value === 'string') return [[path, value]]
  if (typeof value === 'function') return []
  if (value !== null && typeof value === 'object') {
    return Object.entries(value).flatMap(([key, item]) => strings(item, path === '' ? key : `${path}.${key}`))
  }
  return []
}

describe('brand', () => {
  it('defaults to Muhrla when VITE_BRAND is not set', () => {
    expect(BRAND).toBe('Muhrla')
  })

  it('puts the brand into the <title> of index.html, not a fixed word', () => {
    const html = readFileSync(fileURLToPath(new URL('../../index.html', import.meta.url)), 'utf8')

    expect(html).toContain('<title>%VITE_BRAND%</title>')
    expect(html).not.toMatch(OLD_BRAND)
  })

  it.each([
    ['ru', ru],
    ['uz', uz],
  ])('has no old brand name in the %s dictionary', (_locale, dictionary) => {
    const offenders = strings(dictionary).filter(([, text]) => OLD_BRAND.test(text))

    expect(offenders).toEqual([])
  })

  it.each(['ru.ts', 'uz.ts'])('has no old brand name anywhere in the source of %s', (file) => {
    const source = readFileSync(fileURLToPath(new URL(`../i18n/${file}`, import.meta.url)), 'utf8')

    expect(source).not.toMatch(OLD_BRAND)
  })
})
