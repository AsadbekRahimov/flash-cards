import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/i18n'
import { ERROR_TOAST_MS } from '../errorToast'
import { useToast } from '../useToast'
import { useCopyToClipboard } from '../useCopyToClipboard'

const LINK = 'https://t.me/LexiFlowBot?start=f_ABCD2345'

function setClipboard(value: unknown): void {
  Object.defineProperty(navigator, 'clipboard', { value, configurable: true })
}

/** `document.execCommand` of the test: what the browser would copy is read from the field it selected. */
function setExecCommand(impl: ((command: string) => boolean) | undefined): void {
  Object.defineProperty(document, 'execCommand', { value: impl, configurable: true, writable: true })
}

/** What the selection copy saw at the moment of `execCommand('copy')`. */
interface Seen {
  command: string
  value: string
  selected: string
  readOnly: boolean
  focused: boolean
  inDocument: boolean
}

function recordExecCommand(result: boolean | Error = true): { seen: Seen[] } {
  const seen: Seen[] = []
  setExecCommand((command) => {
    const field = document.querySelector('textarea')
    seen.push({
      command,
      value: field?.value ?? '',
      selected: field ? field.value.slice(field.selectionStart, field.selectionEnd) : '',
      readOnly: field?.readOnly ?? false,
      focused: field !== null && document.activeElement === field,
      inDocument: field !== null,
    })
    if (result instanceof Error) throw result
    return result
  })
  return { seen }
}

describe('useCopyToClipboard', () => {
  beforeEach(() => {
    useToast().hide()
    setExecCommand(undefined)
  })
  afterEach(() => {
    useToast().hide()
    setLocale('ru')
    vi.restoreAllMocks()
    document.body.innerHTML = ''
  })

  describe('with the async Clipboard API', () => {
    it('writes the text to the clipboard and shows the "Copied" toast', async () => {
      const writeText = vi.fn().mockResolvedValue(undefined)
      setClipboard({ writeText })

      const copied = await useCopyToClipboard().copy(LINK)

      expect(copied).toBe(true)
      expect(writeText).toHaveBeenCalledWith(LINK)
      expect(useToast().toast.value?.text).toBe('Скопировано')
    })

    it('speaks the language of the interface', async () => {
      setClipboard({ writeText: vi.fn().mockResolvedValue(undefined) })
      setLocale('uz')

      await useCopyToClipboard().copy('x')

      expect(useToast().toast.value?.text).toBe('Nusxalandi')
    })

    it('does not touch the page when the API works: no temporary field', async () => {
      setClipboard({ writeText: vi.fn().mockResolvedValue(undefined) })
      const { seen } = recordExecCommand()

      await useCopyToClipboard().copy(LINK)

      expect(seen).toEqual([])
      expect(document.querySelector('textarea')).toBeNull()
    })
  })

  describe('without the API (an old WebView)', () => {
    beforeEach(() => setClipboard(undefined))

    it('copies the selection of a temporary read-only field and says "Copied"', async () => {
      const { seen } = recordExecCommand()

      const copied = await useCopyToClipboard().copy(LINK)

      expect(copied).toBe(true)
      expect(seen).toEqual([{ command: 'copy', value: LINK, selected: LINK, readOnly: true, focused: true, inDocument: true }])
      expect(useToast().toast.value?.text).toBe('Скопировано')
    })

    it('removes the field and gives the focus back to what had it', async () => {
      recordExecCommand()
      const button = document.createElement('button')
      document.body.appendChild(button)
      button.focus()
      expect(document.activeElement).toBe(button)

      await useCopyToClipboard().copy(LINK)

      expect(document.querySelector('textarea')).toBeNull()
      expect(document.activeElement).toBe(button)
    })

    it('copies at once, inside the tap: nothing is awaited before the selection copy', () => {
      const { seen } = recordExecCommand()

      void useCopyToClipboard().copy(LINK) // not awaited: the copy has happened by the time `copy` returns its promise

      expect(seen).toHaveLength(1)
    })

    it('hides the field from the eyes and from screen readers, and keeps it selectable', async () => {
      let style = ''
      let hidden: string | null = null
      setExecCommand(() => {
        const field = document.querySelector('textarea')
        style = field?.getAttribute('style') ?? ''
        hidden = field?.getAttribute('aria-hidden') ?? null
        return true
      })

      await useCopyToClipboard().copy(LINK)

      expect(hidden).toBe('true')
      expect(style).toContain('position: fixed')
      expect(style).toContain('left: -9999px')
      expect(style).not.toContain('display')
      expect(style).toContain('font-size: 16px') // iOS does not zoom into a 16 px field
    })

    it('tells in a toast that it could not copy when the browser refuses, and points to the link on the screen', async () => {
      recordExecCommand(false)

      const copied = await useCopyToClipboard().copy(LINK)

      expect(copied).toBe(false)
      expect(useToast().toast.value?.text).toBe('Не удалось скопировать. Выделите ссылку и скопируйте вручную.')
      expect(document.querySelector('textarea')).toBeNull()
    })

    it('tells it also when execCommand throws, and still cleans up', async () => {
      recordExecCommand(new Error('SecurityError'))

      const copied = await useCopyToClipboard().copy(LINK)

      expect(copied).toBe(false)
      expect(useToast().toast.value?.text).toBe('Не удалось скопировать. Выделите ссылку и скопируйте вручную.')
      expect(document.querySelector('textarea')).toBeNull()
    })

    it('tells it also when there is no execCommand at all', async () => {
      setExecCommand(undefined)

      const copied = await useCopyToClipboard().copy(LINK)

      expect(copied).toBe(false)
      expect(useToast().toast.value?.text).toBe('Не удалось скопировать. Выделите ссылку и скопируйте вручную.')
    })

    it('keeps the failure on screen as long as any refusal: it has to be read', async () => {
      vi.useFakeTimers()
      recordExecCommand(false)

      await useCopyToClipboard().copy(LINK)
      vi.advanceTimersByTime(ERROR_TOAST_MS - 1)
      expect(useToast().toast.value).not.toBeNull()
      vi.advanceTimersByTime(1)
      expect(useToast().toast.value).toBeNull()
      vi.useRealTimers()
    })

    it('says the failure in Uzbek', async () => {
      setLocale('uz')
      recordExecCommand(false)

      await useCopyToClipboard().copy(LINK)

      expect(useToast().toast.value?.text).toBe("Nusxalab bo'lmadi. Havolani belgilab, qo'lda nusxalang.")
    })
  })

  describe('when the API refuses (no permission)', () => {
    it('tries the selection copy and says "Copied" if that works', async () => {
      setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('NotAllowedError')) })
      const { seen } = recordExecCommand()

      const copied = await useCopyToClipboard().copy(LINK)

      expect(copied).toBe(true)
      expect(seen.map((s) => s.value)).toEqual([LINK])
      expect(useToast().toast.value?.text).toBe('Скопировано')
    })

    it('tells that it could not copy when both ways fail', async () => {
      setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('NotAllowedError')) })
      recordExecCommand(false)

      const copied = await useCopyToClipboard().copy(LINK)

      expect(copied).toBe(false)
      expect(useToast().toast.value?.text).toBe('Не удалось скопировать. Выделите ссылку и скопируйте вручную.')
    })
  })

  it('treats a clipboard object without writeText as a missing API', async () => {
    setClipboard({})
    const { seen } = recordExecCommand()

    const copied = await useCopyToClipboard().copy(LINK)

    expect(copied).toBe(true)
    expect(seen).toHaveLength(1)
  })
})
