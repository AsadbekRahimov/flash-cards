import { useI18n } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useToast } from '@/composables/useToast'

/**
 * The "Copy" button of an invite link (Friends, Invite a friend; docs/14 K4): puts the text on the clipboard and
 * answers with a toast either way, so a tap is never silent.
 *
 * Two ways, in this order: the async Clipboard API, and — when it is missing (an old WebView) or refuses (no
 * permission) — the old one: a temporary read-only field, selected, `document.execCommand('copy')`. Without the async
 * API the second way runs at once, inside the tap that called `copy` (some WebViews allow it only there). When neither
 * works the toast says so and points to the link on the screen, which is selected with one tap (`select-all`).
 *
 * `copy(text)` resolves `true` once copied ("Copied" toast) and `false` after the "Could not copy" toast.
 *
 * @example const { copy } = useCopyToClipboard(); await copy(link)
 */
export function useCopyToClipboard() {
  const { t } = useI18n()
  const toast = useToast()

  async function copy(text: string): Promise<boolean> {
    let copied = false
    if (hasAsyncClipboard()) {
      try {
        await navigator.clipboard.writeText(text)
        copied = true
      } catch {
        // refused (no permission, not focused): the old way may still work
      }
    }
    if (!copied) copied = copyBySelection(text)

    if (copied) toast.show(t('common.copied'))
    else showErrorToast(t('common.copy_failed'))
    return copied
  }

  return { copy }
}

function hasAsyncClipboard(): boolean {
  return typeof navigator !== 'undefined' && typeof navigator.clipboard?.writeText === 'function'
}

/** Selects the text in a field nobody sees and asks the browser to copy the selection. */
function copyBySelection(text: string): boolean {
  if (typeof document === 'undefined' || typeof document.execCommand !== 'function') return false

  const field = document.createElement('textarea')
  field.value = text
  field.readOnly = true // a phone does not raise the keyboard for it
  field.setAttribute('aria-hidden', 'true')
  // Out of sight but selectable (not display:none); 16 px, so that iOS does not zoom into it.
  field.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0;font-size:16px'
  const active = document.activeElement
  document.body.appendChild(field)
  try {
    field.focus({ preventScroll: true })
    field.select()
    field.setSelectionRange(0, text.length)
    return document.execCommand('copy')
  } catch {
    return false
  } finally {
    field.remove()
    if (active instanceof HTMLElement) active.focus({ preventScroll: true })
  }
}
