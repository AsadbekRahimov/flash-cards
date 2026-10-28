import { useI18n } from '@/i18n'
import { useToast } from '@/composables/useToast'

/**
 * The "Copy" button of an invite link (Friends, Invite a friend; docs/14 K4): writes the text to the clipboard and
 * answers with the "Copied" toast.
 *
 * `copy(text)` resolves `true` once copied. It resolves `false`, with no toast, when the clipboard is not available
 * (an old WebView, no permission): the link stays on the screen in a selectable field, so the learner can copy it by hand.
 *
 * @example const { copy } = useCopyToClipboard(); await copy(link)
 */
export function useCopyToClipboard() {
  const { t } = useI18n()
  const toast = useToast()

  async function copy(text: string): Promise<boolean> {
    try {
      await navigator.clipboard.writeText(text)
    } catch {
      return false
    }
    toast.show(t('common.copied'))
    return true
  }

  return { copy }
}
