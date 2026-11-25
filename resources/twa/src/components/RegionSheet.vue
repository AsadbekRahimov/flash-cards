<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { showErrorToast } from '@/composables/errorToast'
import { saveSetting } from '@/composables/useLocaleSwitch'
import { useSettingsStore } from '@/stores/settings'
import { useI18n } from '@/i18n'

/**
 * "Where do you live?" (docs/35 §2.3): the region in two levels, as in the bot - "Uzbekistan" opens the 14 regions (with "Back"),
 * "Another country" and "Rather not say" answer at once. The list is the one of the settings (`options.regions`, named and ordered
 * by the server in the learner's language), the current choice has a check. A choice is saved through the settings store
 * (`region_via` = `via`: the settings screen or the card of the Rating tab), says "Saved" and closes the sheet; a refusal (another
 * region less than 30 days after the last one: "You can change the region from …") is a toast and the sheet stays open.
 */
const props = withDefaults(defineProps<{ via?: 'settings' | 'prompt' }>(), { via: 'settings' })
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const settings = useSettingsStore()
const { data, status, saving } = storeToRefs(settings)

const OTHER = 'other'

const current = computed(() => data.value?.region_code ?? null)
const regions = computed(() => (data.value?.options.regions ?? []).filter((region) => region.code !== OTHER))
/** Level 2 is open: the list of the regions of Uzbekistan. Opens on it when the current region is one of them. */
const list = ref(false)

async function pick(code: string | null): Promise<void> {
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
  if (data.value === null && status.value !== 'loading') void settings.load()
  list.value = current.value !== null && current.value !== OTHER
})
</script>

<template>
  <BottomSheet :label="t('region.sheet.title')" data-testid="region-sheet" @close="emit('close')">
    <div class="space-y-1 pt-2 pr-10">
      <h2 class="text-[22px] leading-7 font-semibold" data-testid="region-title">{{ list ? t('region.sheet.pick') : t('region.sheet.title') }}</h2>
      <p v-if="!list" class="text-[15px] leading-5 text-muted">{{ t('region.sheet.lead') }}</p>
    </div>

    <LoadingState v-if="!data" variant="list" />

    <ListGroup v-else-if="!list" data-testid="region-level-1">
      <button type="button" class="list-row" :disabled="saving" data-testid="region-uzbekistan" @click="list = true">
        <span class="min-w-0 flex-1 text-left text-base leading-[22px]">{{ t('region.sheet.uzbekistan') }}</span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-muted" />
      </button>
      <button type="button" class="list-row" role="radio" :aria-checked="current === OTHER" :disabled="saving" data-testid="region-other" @click="pick(OTHER)">
        <span class="min-w-0 flex-1 text-left text-base leading-[22px]" :class="{ 'font-semibold': current === OTHER }">{{ t('region.sheet.other') }}</span>
        <AppIcon v-if="current === OTHER" name="check" :size="20" class="shrink-0 text-accent" />
      </button>
      <button type="button" class="list-row" role="radio" :aria-checked="current === null" :disabled="saving" data-testid="region-none" @click="pick(null)">
        <span class="min-w-0 flex-1 text-left text-base leading-[22px]" :class="{ 'font-semibold': current === null }">{{ t('region.sheet.none') }}</span>
        <AppIcon v-if="current === null" name="check" :size="20" class="shrink-0 text-accent" />
      </button>
    </ListGroup>

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
          <span class="min-w-0 flex-1 text-left text-base leading-[22px]" :class="{ 'font-semibold': region.code === current }">{{ region.name }}</span>
          <AppIcon v-if="region.code === current" name="check" :size="20" class="shrink-0 text-accent" />
        </button>
      </ListGroup>
      <button type="button" class="btn btn-secondary btn-block" data-testid="region-back" @click="list = false">{{ t('region.sheet.back') }}</button>
    </template>
  </BottomSheet>
</template>
