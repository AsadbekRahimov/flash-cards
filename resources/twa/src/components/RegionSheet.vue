<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { showErrorToast } from '@/composables/errorToast'
import { saveSetting } from '@/composables/useLocaleSwitch'
import { useBillingStore } from '@/stores/billing'
import { useSettingsStore } from '@/stores/settings'
import { useI18n } from '@/i18n'

/**
 * "Where do you live?" (docs/35 §2.3): the region in two levels, as in the bot - "Uzbekistan" opens the 14 regions (with "Back"),
 * "Another country" and "Rather not say" answer at once. The list is the one of the settings (`options.regions`, named and ordered
 * by the server in the learner's language), the current choice has a check. A choice is saved through the settings store
 * (`region_via` = `via`: the settings screen or the card of the Rating tab), says "Saved" and closes the sheet; a refusal (another
 * region less than 30 days after the last one: "You can change the region from …") is a toast and the sheet stays open.
 *
 * `browse` — only a choice, nothing saved (docs/31 §5г: the table of any region may be opened): the same two levels without "Rather not
 * say", the check on `selected` (the region of the table shown), a tap emits `pick` with the code and the name and closes nothing - the
 * caller does. The list comes with `/me` (`useBillingStore().load()`: a `/me` read moments ago is not asked again).
 */
const props = withDefaults(defineProps<{ via?: 'settings' | 'prompt'; browse?: boolean; selected?: string | null }>(), {
  via: 'settings',
  browse: false,
  selected: null,
})
const emit = defineEmits<{ close: []; pick: [code: string, name: string] }>()

const { t } = useI18n()
const settings = useSettingsStore()
const { data, status, saving } = storeToRefs(settings)

const OTHER = 'other'

const current = computed(() => (props.browse ? props.selected : (data.value?.region_code ?? null)))
const regions = computed(() => (data.value?.options.regions ?? []).filter((region) => region.code !== OTHER))
/** The current region is one of Uzbekistan: the row "Uzbekistan" of level 1 is the chosen one. */
const inUzbekistan = computed(() => current.value !== null && current.value !== OTHER)
/** Level 2 is open: the list of the regions of Uzbekistan. Opens on it when the current region is one of them. */
const list = ref(false)

/** The choices of level 1: "Uzbekistan" (no code: it opens level 2), "Another country", "Rather not say" (not while browsing). */
const levelOne = computed(() => [
  { testid: 'region-uzbekistan', label: t('region.sheet.uzbekistan'), checked: inUzbekistan.value, code: undefined },
  { testid: 'region-other', label: t('region.sheet.other'), checked: current.value === OTHER, code: OTHER as string | null },
  ...(props.browse ? [] : [{ testid: 'region-none', label: t('region.sheet.none'), checked: current.value === null, code: null as string | null }]),
])

async function pick(code: string | null): Promise<void> {
  if (props.browse) {
    if (code === null) return
    const name = data.value?.options.regions?.find((item) => item.code === code)?.name ?? (code === OTHER ? t('region.sheet.other') : '')
    if (name !== '') emit('pick', code, name)
    return
  }
  if (saving.value) return
  if (code === current.value) {
    emit('close')
    return
  }
  if (await saveSetting({ region_code: code, region_via: props.via })) {
    emit('close')
    return
  }
  // The settings screen tells a refusal itself (its watcher clears it); anywhere else (the card of the Rating tab) the sheet does.
  if (settings.errorMessage !== '') {
    showErrorToast(settings.errorMessage)
    settings.clearError()
  }
}

onMounted(() => {
  if (data.value === null && props.browse) void useBillingStore().load()
  else if (data.value === null && status.value !== 'loading') void settings.load()
  list.value = current.value !== null && current.value !== OTHER
})
</script>

<template>
  <BottomSheet :label="browse ? t('region.sheet.view_title') : t('region.sheet.title')" data-testid="region-sheet" @close="emit('close')">
    <div class="space-y-1 pt-2 pr-10">
      <h2 v-if="browse" class="t-display-m" data-testid="region-title">{{ list ? t('region.sheet.view_pick') : t('region.sheet.view_title') }}</h2>
      <h2 v-else class="t-display-m" data-testid="region-title">{{ list ? t('region.sheet.pick') : t('region.sheet.title') }}</h2>
      <p v-if="!list && !browse" class="t-body-m text-muted">{{ t('region.sheet.lead') }}</p>
    </div>

    <LoadingState v-if="!data" variant="list" />

    <!-- One choice of three, as the selectable tiles of the kit (the chosen one framed 2 px in border/strong, an ink check at its corner);
         "Uzbekistan" opens level 2 and is the chosen one while the region is one of its. -->
    <div v-else-if="!list" class="space-y-3 pt-2 pb-1" role="radiogroup" :aria-label="t('region.sheet.title')" data-testid="region-level-1">
      <button
        v-for="choice in levelOne"
        :key="choice.testid"
        type="button"
        class="pressable relative flex min-h-14 w-full items-center gap-3 rounded-lg bg-surface px-4 py-3 text-left disabled:opacity-50"
        :class="choice.checked ? 'shadow-[inset_0_0_0_2px_var(--app-border-strong)]' : 'shadow-[inset_0_0_0_1.5px_var(--app-hairline)]'"
        role="radio"
        :aria-checked="choice.checked"
        :disabled="saving"
        :data-testid="choice.testid"
        @click="choice.code === undefined ? (list = true) : pick(choice.code)"
      >
        <span
          v-if="choice.checked"
          class="absolute -top-2 -left-2 flex size-[22px] items-center justify-center rounded-full bg-inverse text-on-inverse"
          :data-testid="choice.testid === 'region-uzbekistan' ? 'region-uzbekistan-check' : undefined"
        ><AppIcon name="check" :size="14" /></span>
        <span class="t-body-m-strong min-w-0 flex-1">{{ choice.label }}</span>
        <AppIcon v-if="choice.code === undefined" name="chevron-right" :size="18" class="shrink-0 text-muted" />
      </button>
    </div>

    <template v-else>
      <ListGroup role="radiogroup" :aria-label="t('region.sheet.pick')" data-testid="region-list">
        <button
          v-for="region in regions"
          :key="region.code"
          type="button"
          class="list-row"
          role="radio"
          :aria-checked="region.code === current"
          :disabled="saving"
          :data-testid="`region-${region.code}`"
          @click="pick(region.code)"
        >
          <span class="t-body-m min-w-0 flex-1 text-left" :class="{ 'font-semibold': region.code === current }">{{ region.name }}</span>
          <span v-if="region.code === current" class="flex size-[22px] shrink-0 items-center justify-center rounded-full bg-inverse text-on-inverse"><AppIcon name="check" :size="14" /></span>
        </button>
      </ListGroup>
      <button type="button" class="btn btn-secondary btn-block" data-testid="region-back" @click="list = false">{{ t('region.sheet.back') }}</button>
    </template>
  </BottomSheet>
</template>
