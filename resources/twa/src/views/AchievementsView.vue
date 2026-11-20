<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useAchievementsStore } from '@/stores/achievements'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { AchievementItem } from '@/api/types'

/**
 * Achievements (docs/13 §7.4, docs/14 §6.7, docs/04 A.15): what is unlocked (with the date and a share button that
 * opens Telegram's share dialog with the learner's invite link) and the progress towards what is still locked, as a
 * grid of two columns under a summary with a progress bar. Back -> Profile.
 */
const auth = useAuthStore()
const achievements = useAchievementsStore()
const { shareUrl, haptic } = useTelegram()
const { t, locale } = useI18n()

useBackButton({ name: 'profile' })

const failed = ref('')

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

const summaryPercent = computed(() => {
  const total = data.value?.total ?? 0
  return total <= 0 ? 0 : Math.min(100, Math.round(((data.value?.unlocked_count ?? 0) / total) * 100))
})

/** "25 Oct 2026" with no-break spaces: in a narrow card the line breaks before the date, not inside it. */
function unlockedDate(iso: string): string {
  return formatDate(iso, 'dayMonthShortYear', locale.value).replace(/\s+/g, '\u00a0')
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
  void load()
})
</script>

<template>
  <div class="page" data-testid="achievements-view">
    <PageHeader :title="t('achievements.title')" />

    <LoadingState v-if="!data && !message" />

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <div v-else class="space-y-3.5">
      <section class="card flex flex-col gap-2.5 px-[18px] py-4" data-testid="summary-card">
        <p class="text-[15px] leading-5 font-semibold" data-testid="summary">
          {{ t('achievements.summary', { unlocked: data.unlocked_count, total: data.total }) }}
        </p>
        <ProgressLine :value="summaryPercent" :label="t('achievements.title')" data-testid="summary-bar" />
        <p v-if="data.unlocked_count === 0" class="text-[13px] leading-[18px] text-muted" data-testid="empty-hint">
          {{ t('achievements.empty_hint') }}
        </p>
      </section>

      <!-- One list, a row per achievement: the received ones first; a quiet status icon on the right (no tiles). -->
      <ul class="list" data-testid="list">
        <li
          v-for="item in items"
          :key="item.key"
          class="flex items-start gap-3 border-b border-divider px-[18px] py-3.5 last:border-b-0"
          :data-testid="`item-${item.key}`"
          :data-unlocked="item.unlocked_at !== null"
        >
          <div class="min-w-0 flex-1">
            <h2 class="line-clamp-2 text-[15px] leading-5 font-semibold" data-testid="item-title">{{ item.title }}</h2>
            <p class="mt-0.5 line-clamp-3 text-[13px] leading-[18px] text-muted" data-testid="item-description">{{ item.description }}</p>

            <div class="mt-2">
              <template v-if="item.unlocked_at !== null">
                <p class="text-[13px] leading-[18px] text-success-ink" data-testid="unlocked-on">
                  {{ t('achievements.unlocked_on', { date: unlockedDate(item.unlocked_at) }) }}
                </p>
                <button
                  v-if="data.share_link"
                  type="button"
                  class="btn btn-tinted btn-sm mt-2"
                  data-testid="share-btn"
                  @click="share(item)"
                >
                  {{ t('achievements.share') }}
                </button>
                <p v-if="item.shared_at" class="mt-2 flex items-start gap-1 text-xs leading-4 text-muted" data-testid="shared">
                  <AppIcon name="check" :size="14" class="mt-px" />
                  <span>{{ t('achievements.shared') }}</span>
                </p>
              </template>

              <div v-else-if="item.progress" class="flex items-center gap-3">
                <ProgressLine :value="percent(item)" :label="item.title" class="flex-1" data-testid="bar" />
                <span class="shrink-0 font-mono text-xs leading-4 text-muted" data-testid="progress">
                  {{ t('achievements.progress', { current: item.progress.current, target: item.progress.target }) }}
                </span>
              </div>

              <p v-else class="text-[13px] leading-[18px] text-muted" data-testid="locked">{{ t('achievements.locked') }}</p>
            </div>
          </div>

          <span
            class="mt-0.5 shrink-0"
            :class="item.unlocked_at !== null ? 'text-warning' : 'text-muted'"
            data-testid="item-tile"
          >
            <AppIcon :name="item.unlocked_at !== null ? 'trophy' : 'lock-closed'" :solid="item.unlocked_at !== null" :size="20" />
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>
