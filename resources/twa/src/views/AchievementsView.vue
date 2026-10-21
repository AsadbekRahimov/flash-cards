<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAchievementsStore } from '@/stores/achievements'
import { useTelegram } from '@/composables/useTelegram'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { AchievementItem } from '@/api/types'

/**
 * Achievements (docs/13 §7.4, docs/04 A.15): what is unlocked (with the date and a share button that opens
 * Telegram's share dialog with the learner's invite link) and the progress towards what is still locked.
 */
const router = useRouter()
const auth = useAuthStore()
const achievements = useAchievementsStore()
const { showBackButton, shareUrl, haptic } = useTelegram()
const { t, locale } = useI18n()

const failed = ref('')
let hideBack: (() => void) | null = null

const data = computed(() => achievements.data)
const message = computed(() => failed.value || achievements.errorMessage)

/** Unlocked first (newest first), then the locked ones in catalog order. */
const items = computed<AchievementItem[]>(() => {
  const all = data.value?.items ?? []
  const unlocked = all
    .filter((item) => item.unlocked_at !== null)
    .sort((a, b) => String(b.unlocked_at).localeCompare(String(a.unlocked_at)))
  return [...unlocked, ...all.filter((item) => item.unlocked_at === null)]
})

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso))
}

function percent(item: AchievementItem): number {
  const p = item.progress
  return p === null || p.target <= 0 ? 0 : Math.min(100, Math.round((p.current / p.target) * 100))
}

function share(item: AchievementItem): void {
  const link = data.value?.share_link
  if (!link || item.share_text === null) return
  haptic('light')
  shareUrl(link, item.share_text)
  void achievements.markShared(item.key)
}

async function load(): Promise<void> {
  failed.value = ''
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  await achievements.load()
}

onMounted(() => {
  hideBack = showBackButton(() => void router.push({ name: 'today' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <h1 class="text-xl font-bold mb-1">🏅 {{ t('achievements.title') }}</h1>

    <div v-if="!data && !message" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="!data" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ message }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <div v-else class="space-y-3">
      <p class="text-sm text-tg-hint" data-testid="summary">
        {{ t('achievements.summary', { unlocked: data.unlocked_count, total: data.total }) }}
      </p>
      <p v-if="data.unlocked_count === 0" class="text-sm text-tg-hint" data-testid="empty-hint">
        {{ t('achievements.empty_hint') }}
      </p>

      <ul class="space-y-3" data-testid="list">
        <li
          v-for="item in items"
          :key="item.key"
          class="card space-y-2"
          :class="{ 'opacity-70': item.unlocked_at === null }"
          :data-testid="`item-${item.key}`"
          :data-unlocked="item.unlocked_at !== null"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="font-semibold">{{ item.unlocked_at !== null ? '🏅' : '🔒' }} {{ item.title }}</div>
              <div class="text-sm text-tg-hint">{{ item.description }}</div>
            </div>
          </div>

          <template v-if="item.unlocked_at !== null">
            <div class="text-xs text-tg-hint" data-testid="unlocked-on">
              {{ t('achievements.unlocked_on', { date: formatDate(item.unlocked_at) }) }}
            </div>
            <button
              v-if="data.share_link"
              type="button"
              class="btn btn-secondary w-full"
              data-testid="share-btn"
              @click="share(item)"
            >
              {{ t('achievements.share') }}
            </button>
            <div v-if="item.shared_at" class="text-xs text-tg-hint" data-testid="shared">✓ {{ t('achievements.shared') }}</div>
          </template>

          <template v-else-if="item.progress">
            <div class="h-2 rounded-full bg-black/10 overflow-hidden" role="progressbar" :aria-valuenow="percent(item)" aria-valuemin="0" aria-valuemax="100">
              <div class="h-full bg-tg-button" :style="{ width: percent(item) + '%' }" data-testid="bar" />
            </div>
            <div class="text-xs text-tg-hint" data-testid="progress">
              {{ t('achievements.progress', { current: item.progress.current, target: item.progress.target }) }}
            </div>
          </template>
          <div v-else class="text-xs text-tg-hint" data-testid="locked">{{ t('achievements.locked') }}</div>
        </li>
      </ul>
    </div>
  </section>
</template>
