import { afterEach, describe, expect, it, vi } from 'vitest'
import { prefersReducedMotion } from '../motion'

describe('prefersReducedMotion', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('follows the device setting', () => {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query === '(prefers-reduced-motion: reduce)' }))
    expect(prefersReducedMotion()).toBe(true)

    vi.stubGlobal('matchMedia', () => ({ matches: false }))
    expect(prefersReducedMotion()).toBe(false)
  })

  it('is false where there is no matchMedia, or it fails', () => {
    vi.stubGlobal('matchMedia', undefined)
    expect(prefersReducedMotion()).toBe(false)

    vi.stubGlobal('matchMedia', () => { throw new Error('nope') })
    expect(prefersReducedMotion()).toBe(false)
  })
})
