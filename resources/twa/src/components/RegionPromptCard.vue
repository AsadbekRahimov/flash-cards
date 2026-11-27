<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import RegionSheet from '@/components/RegionSheet.vue'
import { useBillingStore } from '@/stores/billing'
import { useSettingsStore } from '@/stores/settings'
import { useI18n } from '@/i18n'

/**
 * "Choose your region - the rating of your region will appear" on the Rating tab (docs/35 §2.3): for a learner without a region,
 * never as a mailing. The server decides whether it is due (`settings.region_prompt`: no region and no "Not now" in the last 30
 * days). "Choose" opens the region sheet (`region_via = prompt`); "Not now" hides it at once and for 30 days. The settings come
 * with `/me` (the billing store reads it once for every screen); nothing is shown until they are there.
 */
const { t } = useI18n()
const settings = useSettingsStore()

const sheetOpen = ref(false)
const visible = computed(() => settings.data?.region_prompt === true)

onMounted(() => {
  if (settings.data === null) void useBillingStore().load()
})
</script>

<template>
  <section v-if="visible" class="card space-y-3" data-testid="region-prompt">
    <div class="space-y-1">
      <h2 class="t-title-m">{{ t('region.prompt.title') }}</h2>
      <p class="t-body-m text-muted">{{ t('region.prompt.text') }}</p>
    </div>
    <div class="flex gap-2">
      <button type="button" class="btn btn-primary flex-1" data-testid="region-prompt-choose" @click="sheetOpen = true">{{ t('region.prompt.choose') }}</button>
      <button type="button" class="btn btn-secondary flex-1" data-testid="region-prompt-later" @click="settings.dismissRegionPrompt()">
        {{ t('region.prompt.later') }}
      </button>
    </div>
  </section>

  <RegionSheet v-if="sheetOpen" via="prompt" @close="sheetOpen = false" />
</template>
