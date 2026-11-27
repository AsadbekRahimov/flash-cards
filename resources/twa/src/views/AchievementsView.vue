<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AchievementArt from '@/components/achievements/AchievementArt.vue'
import AchievementBadge from '@/components/ui/AchievementBadge.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import { formatDate } from '@/composables/format'
import { ACHIEVEMENT_ART_BY_KEY } from '@/composables/illustrations'
import { prefersReducedMotion } from '@/composables/motion'
import { useBackButton } from '@/composables/useBackButton'
import { useShare } from '@/composables/useShare'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useAchievementsStore } from '@/stores/achievements'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import { useStoryI18n } from '@/i18n/story'
import type { AchievementItem } from '@/api/types'

/**
 * Achievements (docs/13 §7.4, docs/14 §6.7, docs/04 A.15), drawn by the kit Muhrla (11-dostizheniya): the hero on the centre axis — the
 * chosen achievement as a 112 px stamp (the newest received one until another is tapped; the first one ahead while none is received),
 * its title, what it is for, the date it was received or its progress, and "Share"; then "Received" (newest first, "N of M") and "Ahead"
 * (the ones with progress first, then the locked ones) as grids of AchievementBadges. An achievement the kit has no picture for gets the
 * even fallback stamp (`AchievementArt`). A tap on a badge shows it in the hero and brings the hero into view.
 * "Share" sends the card of the achievement through the bot (`useShare`, docs/36 §1в); an older client or a refusal opens Telegram's share
 * dialog with the learner's invite link and the text of the server, as before. "To a story" beside it (a client of Mini Apps 7.8+ only,
 * docs/40 §2.4) opens F3 for the chosen achievement. Back -> Profile.
 */
const auth = useAuthStore()
const achievements = useAchievementsStore()
const router = useRouter()
const { haptic, canShareToStory } = useTelegram()
const { pending: sharing, share: shareThroughBot } = useShare()
const { t, locale } = useI18n()
const { t: st } = useStoryI18n()

/** The story editor of Telegram is there (7.8+): "To a story" is offered; below it only "Share". */
const storyAvailable = canShareToStory()

function toStory(item: AchievementItem): void {
  haptic('light')
  void router.push({ name: 'share-story', query: { kind: 'achievement', subject: item.key } })
}

useBackButton({ name: 'profile' })

const failed = ref('')

const data = computed(() => achievements.data)
const message = computed(() => failed.value || achievements.errorMessage)

function percent(item: AchievementItem): number {
  const p = item.progress
  return p === null || p.target <= 0 ? 0 : Math.min(100, Math.round((p.current / p.target) * 100))
}

/** Received, newest first. */
const received = computed<AchievementItem[]>(() =>
  (data.value?.items ?? [])
    .filter((item) => item.unlocked_at !== null)
    .sort((a, b) => String(b.unlocked_at).localeCompare(String(a.unlocked_at))),
)

/** Ahead: the ones with progress first (the nearest first), then the locked ones in catalog order. */
const ahead = computed<AchievementItem[]>(() => {
  const locked = (data.value?.items ?? []).filter((item) => item.unlocked_at === null)
  const started = locked.filter((item) => (item.progress?.current ?? 0) > 0).sort((a, b) => percent(b) - percent(a))
  return [...started, ...locked.filter((item) => (item.progress?.current ?? 0) <= 0)]
})

const groups = computed(() =>
  [
    {
      key: 'received' as const,
      title: t('achievements.received'),
      count: t('achievements.summary', { unlocked: data.value?.unlocked_count ?? 0, total: data.value?.total ?? 0 }),
      items: received.value,
    },
    { key: 'ahead' as const, title: t('achievements.ahead'), count: String(ahead.value.length), items: ahead.value },
  ].filter((group) => group.items.length > 0),
)

/** The achievement in the hero: the one tapped, else the newest received, else the first ahead; none for an empty catalog. */
const chosenKey = ref<string | null>(null)
const selected = computed<AchievementItem | null>(
  () => (data.value?.items ?? []).find((item) => item.key === chosenKey.value) ?? received.value[0] ?? ahead.value[0] ?? null,
)

const hero = ref<HTMLElement | null>(null)

function choose(item: AchievementItem): void {
  chosenKey.value = item.key
  hero.value?.scrollIntoView?.({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' })
}

function badgeState(item: AchievementItem): 'unlocked' | 'progress' | 'locked' {
  if (item.unlocked_at !== null) return 'unlocked'
  return (item.progress?.current ?? 0) > 0 ? 'progress' : 'locked'
}

/** Under a badge: the day it was received, or "40 of 100"; nothing under a locked one (the hero says what it is for). */
function badgeMeta(item: AchievementItem): string | undefined {
  if (item.unlocked_at !== null) return formatDate(item.unlocked_at, 'dayMonth', locale.value)
  if (item.progress && item.progress.current > 0) return t('achievements.progress', { current: item.progress.current, target: item.progress.target })
  return undefined
}

/** "25 Oct 2026" with no-break spaces: in a narrow line the line breaks before the date, not inside it. */
function unlockedDate(iso: string): string {
  return formatDate(iso, 'dayMonthShortYear', locale.value).replace(/\s+/g, '\u00a0')
}

/** The key whose share is on its way: its button spins, the others wait (one share at a time). */
const sharingKey = ref<string | null>(null)

async function share(item: AchievementItem): Promise<void> {
  const link = data.value?.share_link
  if (!link || item.share_text === null || sharing.value) return
  haptic('light')
  sharingKey.value = item.key
  try {
    await shareThroughBot('achievement', item.key, { link, text: item.share_text }, () => void achievements.markShared(item.key))
  } finally {
    sharingKey.value = null
  }
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

    <div v-if="!data && !message" role="status" aria-busy="true" data-testid="spinner">
      <span class="sr-only">{{ t('common.loading') }}</span>
      <!-- The skeleton of the screen: the big stamp, its title and line, then a row of stamps. -->
      <div class="flex flex-col items-center gap-3 pb-7" aria-hidden="true">
        <span class="skeleton size-28 rounded-full" />
        <span class="skeleton h-7 w-48" />
        <span class="skeleton h-4 w-56" />
      </div>
      <div class="grid grid-cols-[repeat(auto-fill,104px)] justify-between gap-y-5" aria-hidden="true">
        <span v-for="n in 3" :key="n" class="flex flex-col items-center gap-2">
          <span class="skeleton size-[88px] rounded-full" />
          <span class="skeleton h-3.5 w-20" />
        </span>
      </div>
    </div>

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <div v-else class="space-y-7">
      <!-- The hero of the kit (11-dostizheniya): the chosen achievement — the newest received one first — as a 112 px stamp on the centre
           axis, its title, what it is for, when it was received or how far it is, and the share of it. -->
      <section ref="hero" class="flex scroll-mt-4 flex-col items-center gap-2 text-center" aria-live="polite" data-testid="hero">
        <template v-if="selected">
          <AchievementArt :key="selected.key" :item-key="selected.key" :locked="badgeState(selected) === 'locked'" :size="112" class="mascot-enter" />
          <h2 class="t-display-m mt-2 line-clamp-2 break-words" data-testid="hero-title">{{ selected.title }}</h2>
          <p class="t-body-m line-clamp-3 max-w-80 text-muted" data-testid="hero-description">{{ selected.description }}</p>

          <template v-if="selected.unlocked_at !== null">
            <p class="text-[13px] leading-[18px] text-success-ink" data-testid="unlocked-on">
              {{ t('achievements.unlocked_on', { date: unlockedDate(selected.unlocked_at) }) }}
            </p>
            <div class="mt-2 flex flex-wrap justify-center gap-2">
              <button
                v-if="data.share_link"
                type="button"
                class="btn btn-secondary"
                :class="storyAvailable ? 'min-w-36' : 'min-w-48'"
                :disabled="sharing"
                :aria-busy="sharingKey === selected.key ? 'true' : undefined"
                data-testid="share-btn"
                @click="share(selected)"
              >
                <span
                  v-if="sharingKey === selected.key"
                  class="size-4 shrink-0 animate-spin rounded-full border-2 border-track border-t-accent motion-reduce:animate-none"
                  aria-hidden="true"
                  data-testid="share-busy"
                />
                <AppIcon v-else name="share" :size="18" />
                <span>{{ t('achievements.share') }}</span>
              </button>
              <button
                v-if="storyAvailable"
                type="button"
                class="btn btn-secondary min-w-36"
                :disabled="sharing"
                data-testid="story-btn"
                @click="toStory(selected)"
              >
                <AppIcon name="sparkles" :size="18" />
                <span>{{ st('story.to_story') }}</span>
              </button>
            </div>
            <p v-if="selected.shared_at" class="flex items-center gap-1 text-xs leading-4 text-muted" data-testid="shared">
              <AppIcon name="check" :size="14" />
              <span>{{ t('achievements.shared') }}</span>
            </p>
          </template>

          <div v-else-if="selected.progress" class="mt-1 flex w-full max-w-60 items-center gap-3">
            <ProgressLine :value="percent(selected)" :label="selected.title" tone="muhr" class="flex-1" data-testid="bar" />
            <span class="shrink-0 font-mono text-xs leading-4 text-muted" data-testid="progress">
              {{ t('achievements.progress', { current: selected.progress.current, target: selected.progress.target }) }}
            </span>
          </div>

          <p v-else class="text-[13px] leading-[18px] text-muted" data-testid="locked">{{ t('achievements.locked') }}</p>
        </template>
        <MascotArt v-else pose="hello" :height="112" />

        <p v-if="data.unlocked_count === 0" class="t-body-m max-w-80 text-muted" data-testid="empty-hint">
          {{ t('achievements.empty_hint') }}
        </p>
      </section>

      <!-- "Received" and "Ahead": grids of the kit, three 104 px columns (two on a narrow screen); a tap shows the stamp in the hero. -->
      <section
        v-for="group in groups"
        :key="group.key"
        :aria-label="group.title"
        :data-testid="`group-${group.key}`"
      >
        <div class="mb-3 flex items-baseline justify-between gap-3">
          <h2 class="t-title-m">{{ group.title }}</h2>
          <span class="shrink-0 text-[13px] leading-[18px] text-muted tabular-nums" :data-testid="group.key === 'received' ? 'summary' : 'ahead-count'">
            {{ group.count }}
          </span>
        </div>
        <ul class="grid grid-cols-[repeat(auto-fill,104px)] justify-between gap-y-5" :data-testid="group.key === 'received' ? 'list' : 'list-ahead'">
          <li v-for="item in group.items" :key="item.key">
            <button
              type="button"
              class="pressable relative flex flex-col items-center rounded-md pb-2"
              :aria-pressed="selected?.key === item.key"
              :data-testid="`item-${item.key}`"
              :data-unlocked="item.unlocked_at !== null"
              @click="choose(item)"
            >
              <AchievementBadge
                :art="ACHIEVEMENT_ART_BY_KEY[item.key] ?? null"
                :state="badgeState(item)"
                :progress="percent(item)"
                :title="item.title"
                :meta="badgeMeta(item)"
              >
                <template #fallback><AchievementArt :item-key="item.key" :locked="badgeState(item) === 'locked'" /></template>
              </AchievementBadge>
              <!-- The chosen one: a short ink mark under it, like the mark of the active tab. -->
              <span
                v-if="selected?.key === item.key"
                class="absolute bottom-0 h-[3px] w-4 rounded-full bg-inverse"
                aria-hidden="true"
                data-testid="item-chosen"
              />
            </button>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
