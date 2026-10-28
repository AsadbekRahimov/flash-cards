import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/i18n'
import { useToast } from '../useToast'
import { useCopyToClipboard } from '../useCopyToClipboard'

function setClipboard(value: unknown): void {
  Object.defineProperty(navigator, 'clipboard', { value, configurable: true })
}

describe('useCopyToClipboard', () => {
  beforeEach(() => useToast().hide())
  afterEach(() => {
    useToast().hide()
    setLocale('ru')
    vi.restoreAllMocks()
  })

  it('writes the text to the clipboard and shows the "Copied" toast', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    setClipboard({ writeText })

    const copied = await useCopyToClipboard().copy('https://t.me/LexiFlowBot?start=f_ABCD2345')

    expect(copied).toBe(true)
    expect(writeText).toHaveBeenCalledWith('https://t.me/LexiFlowBot?start=f_ABCD2345')
    expect(useToast().toast.value?.text).toBe('Скопировано')
  })

  it('speaks the language of the interface', async () => {
    setClipboard({ writeText: vi.fn().mockResolvedValue(undefined) })
    setLocale('uz')

    await useCopyToClipboard().copy('x')

    expect(useToast().toast.value?.text).toBe('Nusxalandi')
  })

  it('reports a refused write without a toast', async () => {
    setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('NotAllowedError')) })

    const copied = await useCopyToClipboard().copy('x')

    expect(copied).toBe(false)
    expect(useToast().toast.value).toBeNull()
  })

  it('reports a missing clipboard API without a toast', async () => {
    setClipboard(undefined)

    const copied = await useCopyToClipboard().copy('x')

    expect(copied).toBe(false)
    expect(useToast().toast.value).toBeNull()
  })
})
