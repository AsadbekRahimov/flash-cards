import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { showErrorToast } from '@/composables/errorToast'
import { useToast } from '@/composables/useToast'
import { useSettingsStore } from '@/stores/settings'
import { t } from '@/i18n'
import type { SettingsPatch } from '@/api/types'

/**
 * Saves one setting and says "Saved" (in the language the change has just switched to). The refusal stays in the store's
 * `errorMessage`: a screen that has its own watcher for it (the settings) shows it, `useLocaleSwitch` shows it as a toast.
 *
 * @returns whether the change was saved
 */
export async function saveSetting(patch: SettingsPatch): Promise<boolean> {
  const saved = await useSettingsStore().update(patch)
  if (saved) useToast().show(t('common.saved'))
  return saved
}

/**
 * The interface language from any screen (language sheet, first-run intro): the list of languages is the one of the
 * settings (`/me`, labelled by the server in the language itself), loaded on demand; a change goes through the same
 * `settings.update` as the settings screen and switches the whole app at once.
 */
export function useLocaleSwitch() {
  const settings = useSettingsStore()
  const { data, status, saving } = storeToRefs(settings)

  const options = computed(() => data.value?.options.locales ?? [])
  const current = computed(() => data.value?.interface_locale ?? null)
  const currentLabel = computed(() => options.value.find((option) => option.id === current.value)?.label ?? '')

  /** Loads the settings when nothing is there yet (best effort: a failure leaves `status` at `error`, the sheet offers a retry). */
  async function ensure(): Promise<void> {
    if (data.value === null && status.value !== 'loading') await settings.load()
  }

  /** @returns whether the language was changed; a refusal is a toast and the language stays. */
  async function change(id: string): Promise<boolean> {
    if (id === current.value) return true
    const saved = await saveSetting({ interface_locale: id })
    if (!saved) {
      showErrorToast(settings.errorMessage)
      settings.clearError()
    }
    return saved
  }

  return { options, current, currentLabel, status, saving, ensure, change }
}
