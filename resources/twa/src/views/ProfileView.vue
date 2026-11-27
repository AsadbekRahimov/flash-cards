<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import InitialAvatar from '@/components/InitialAvatar.vue'
import IntroSheet from '@/components/IntroSheet.vue'
import LanguageSheet from '@/components/LanguageSheet.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AchievementArt from '@/components/achievements/AchievementArt.vue'
import GameItemImage from '@/components/game/GameItemImage.vue'
import AchievementBadge from '@/components/ui/AchievementBadge.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { formatDate } from '@/composables/format'
import { ACHIEVEMENT_ART_BY_KEY, type AchievementArt as KitArt } from '@/composables/illustrations'
import { shownName } from '@/composables/learnerName'
import { trialEndsAt } from '@/composables/trial'
import { supportContactLink } from '@/composables/supportContact'
import { useBackButton } from '@/composables/useBackButton'
import { useLocaleSwitch } from '@/composables/useLocaleSwitch'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useAchievementsStore } from '@/stores/achievements'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useCupsStore } from '@/stores/cups'
import { useGameShopStore } from '@/stores/gameShop'
import { useLeagueStore } from '@/stores/league'
import { useSettingsStore } from '@/stores/settings'
import { useTodayStore } from '@/stores/today'
import { errorMessage } from '@/i18n/errors'
import { LANGUAGE_TITLE, useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { GameItemSlot } from '@/api/gameShopTypes'
import type { AchievementItem } from '@/api/types'

/**
 * "Profile" tab (docs/14 §5.2), drawn by the kit Muhrla (09-profil): the brand row and the title; the hero on the centre axis — the
 * avatar with the initial in the muhr colour, the name with the emoji of Pro (docs/31 §5а: `learner.pro_emoji`, sent only while Pro lasts),
 * the plan line (for Free the daily limit of `/me`, never a number of the mock-up) and the Free / Pro chip; the statistics in equal
 * columns (what this session already knows); three achievements with the way to all of them; then the groups of rows "My results"
 * (achievements, readiness, friends) and "My account" (language, settings, subscription, the emoji of Pro). What the mock-up does not have
 * stays, below it in the same style (owner's decision): invitations and "How it works", the caravan card, the Pro card of Free, the
 * support contact.
 * The data is `GET /me` through the billing store (the plan and the support contact; the settings and the emoji are handed on to the
 * settings store), the learner's name from the auth store (`display_name ?? first_name`, `shownName`; `/auth` and `/me` carry the same fields) and `GET /achievements` (best
 * effort). A tab root: no system "Back" button.
 */
const { t, tn, locale } = useI18n()
const auth = useAuthStore()
const billing = useBillingStore()
const shop = useGameShopStore()
const settings = useSettingsStore()
const cups = useCupsStore()
const achievements = useAchievementsStore()
const today = useTodayStore()
const league = useLeagueStore()
const { entitlement, paywall } = storeToRefs(billing)

useBackButton(null)

/** Why the screen failed (already localized); '' = no failure. */
const failed = ref('')
/** A load has finished (successfully or not), so an empty store means a failure and not "still loading". */
const attempted = ref(false)

const status = computed<'loading' | 'error' | 'ready'>(() => {
  if (failed.value !== '') return 'error'
  if (entitlement.value !== null) return 'ready'
  // `billing.load()` keeps quiet on failure; another screen's request may still be in flight, so wait for it too.
  return attempted.value && !billing.loading ? 'error' : 'loading'
})

const errorText = computed(() => failed.value || t('error.generic'))

async function load(): Promise<void> {
  failed.value = ''
  attempted.value = false
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  // "Friends' cups" (docs/33 §7.1): the row shows once the learner has a cup; best effort, a young read is not repeated, a switched
  // off cup is not asked again in this session.
  void cups.load()
  // The three achievements of the screen: best effort, the profile works without them (no row of badges then). Read once: what the
  // store already has (an earlier visit, the "Achievements" screen) is shown as is, no request on every opening of the tab.
  void achievements.ensureLoaded()
  await billing.load()
  attempted.value = true
}

const name = computed(() => shownName(auth.learner))
/** First letter of the name (a whole code point, so an emoji is not cut in half). */
const initial = computed(() => (Array.from(name.value)[0] ?? '').toUpperCase())

/**
 * The statistics of the kit (09-profil): the streak and the words learned in the active course from `/today`, the place in this week's
 * league from `/today` or the League screen — only what this session has already read (the profile asks for neither: `/today` carries the
 * timing marks of the first screen). A number not known yet is left out; none known - no card.
 */
interface ProfileStat {
  key: 'streak' | 'words' | 'league'
  value: string
  label: string
}

const stats = computed((): ProfileStat[] => {
  const list: ProfileStat[] = []
  const streak = today.data?.streak?.current
  if (typeof streak === 'number') list.push({ key: 'streak', value: String(streak), label: tn('profile.stats.streak', streak) })
  const learned = today.data?.active_course?.progress?.learned
  if (typeof learned === 'number') list.push({ key: 'words', value: String(learned), label: tn('profile.stats.words', learned) })
  const rank = today.data?.league?.rank ?? league.data?.membership?.rank ?? null
  if (typeof rank === 'number' && rank > 0) list.push({ key: 'league', value: String(rank), label: t('profile.stats.league') })
  return list
})

interface ProfileBadge {
  item: AchievementItem
  art: KitArt | null
  state: 'unlocked' | 'progress' | 'locked'
  progress: number
  meta: string
}

/** Three achievements: the newest received first, then the ones ahead with the most progress (`/achievements`). */
const badges = computed((): ProfileBadge[] => {
  const items = achievements.data?.items ?? []
  const received = items
    .filter((item) => item.unlocked_at !== null)
    .sort((a, b) => String(b.unlocked_at).localeCompare(String(a.unlocked_at)))
  const share = (item: AchievementItem): number => (item.progress && item.progress.target > 0 ? item.progress.current / item.progress.target : 0)
  const ahead = items.filter((item) => item.unlocked_at === null).sort((a, b) => share(b) - share(a))
  return [...received, ...ahead].slice(0, 3).map((item): ProfileBadge => {
    const art = ACHIEVEMENT_ART_BY_KEY[item.key] ?? null
    if (item.unlocked_at !== null) {
      return { item, art, state: 'unlocked', progress: 0, meta: formatDate(item.unlocked_at, 'dayMonth', locale.value) }
    }
    if (item.progress && item.progress.current > 0) {
      return {
        item,
        art,
        state: 'progress',
        progress: Math.round(share(item) * 100),
        meta: t('achievements.progress', { current: item.progress.current, target: item.progress.target }),
      }
    }
    return { item, art, state: 'locked', progress: 0, meta: t('achievements.locked') }
  })
})

/** The sheet "How it works" (the same one Home shows to a newcomer). */
const introOpen = ref(false)

/** "Til · Язык": the row shows the current language by its own name (the settings' list), the sheet changes it. */
const languageOpen = ref(false)
const { currentLabel: languageLabel } = useLocaleSwitch()

const isPro = computed(() => entitlement.value?.plan === 'pro')

/** «Pro до 1 декабря 2026 г.» / «Пробный Pro до 1 декабря 2026 г.» / «Pro без срока» / «Pro от центра» / «20 новых слов в день» (Free). */
const planLine = computed(() => {
  const current = entitlement.value
  if (current === null) return ''

  if (current.plan === 'pro') {
    const renewal = current.renewal ?? null
    if (renewal === null && current.source.startsWith('sponsor:')) return t('profile.plan.pro_center')

    const trialEnd = trialEndsAt(current)
    if (trialEnd !== null) return t('profile.plan.trial_until', { date: formatDate(trialEnd, 'dayMonthYear', locale.value) })

    const until = renewal?.ends_at ?? current.until
    return until
      ? t('profile.plan.pro_until', { date: formatDate(until, 'dayMonthYear', locale.value) })
      : t('profile.plan.pro_forever')
  }

  // The chip already says Free: the line is the limit itself ("20 new words a day"), the number of `/me`.
  return current.new_words_per_day === null
    ? t('profile.plan.free_plain')
    : tn('profile.plan.free_short', current.new_words_per_day)
})

/** The emoji next to the name: the symbol `/me` sends only while Pro lasts (docs/31 §5а); null or '' — nothing is shown. */
const nameEmoji = computed(() => settings.proEmoji)

/**
 * The row "Emoji of Pro": for Pro the symbol chosen (from the set of `options.pro_emojis`), a tap leads to the picker of the Settings;
 * for Free a lock and "in Pro", a tap opens the paywall (as the locked picker of the Settings does). No set — no row.
 */
const emojiOptions = computed(() => settings.data?.options.pro_emojis ?? [])
const emojiLocked = computed(() => settings.data?.options.pro_emoji_locked ?? !isPro.value)
const emojiValue = computed(() => {
  const code = settings.data?.pro_emoji_choice ?? null
  const chosen = code === null ? undefined : emojiOptions.value.find((option) => option.code === code)
  return chosen?.emoji ?? t('profile.pro_emoji.none')
})
const emojiPaywallOpen = ref(false)
const { reviewFromPaywall: onEmojiPaywallReview } = useReviewFromPaywall(() => {
  emojiPaywallOpen.value = false
}, { toastErrors: true })

/** The active course (`/me.settings`, adopted by the billing store): the row "Readiness for the level" leads to it; no course - no row. */
const activeCourseSlug = computed(() => settings.data?.active_course?.slug ?? null)

const planChip = computed(() => t(isPro.value ? 'profile.chip.pro' : 'profile.chip.free'))
const support = computed(() => supportContactLink(paywall.value?.contact))

/**
 * "My caravan" (docs/05 §5b.6): what the learner wears, from `GET /game/profile`. Only while `/me.game.available`: a closed game has no card and
 * asks for nothing. A failed read leaves no card (the profile works without it).
 */
const gameOn = computed(() => billing.gameAccess.available)
const gameLoaded = ref(false)
const wornSlots: GameItemSlot[] = ['camel', 'carpet', 'tent']
const worn = computed(() =>
  wornSlots.map((slot) => ({ slot, label: t(`gameCaravan.slot.${slot}` as StringKey), item: shop.profile?.equipped[slot] ?? null })),
)
const frame = computed(() => shop.profile?.equipped.frame ?? null)
const showCaravan = computed(() => gameOn.value && gameLoaded.value && shop.profile !== null)

watch(
  gameOn,
  (on) => {
    if (on && !gameLoaded.value) void shop.loadProfile().then((ok) => (gameLoaded.value = ok))
  },
  { immediate: true },
)

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="profile-view">
    <PageHeader brand :title="t('nav.profile')" large />

    <div v-if="status === 'loading'" role="status" aria-busy="true" data-testid="spinner">
      <span class="sr-only">{{ t('common.loading') }}</span>
      <!-- The skeleton of the screen: the avatar, the name and the plan on the centre axis, the statistics, a group of rows. -->
      <div class="flex flex-col items-center gap-2.5 pb-6" aria-hidden="true">
        <span class="skeleton size-[72px] rounded-full" />
        <span class="skeleton h-7 w-40" />
        <span class="skeleton h-4 w-52" />
      </div>
      <div class="skeleton mb-6 h-[78px] rounded-lg" aria-hidden="true" />
      <LoadingState variant="list" aria-hidden="true" />
    </div>

    <ErrorState v-else-if="status === 'error'" :message="errorText" @retry="load" />

    <div v-else class="space-y-6">
      <!-- The hero of the kit (09-profil), on the centre axis: the avatar with the initial, the name with the emoji of Pro, the plan and its chip. -->
      <section class="flex flex-col items-center gap-2 text-center" data-testid="profile-header">
        <InitialAvatar :name="name" size="lg" muhr data-testid="profile-avatar" />
        <div class="mt-1 flex max-w-full min-w-0 items-center justify-center gap-2">
          <h2 class="t-display-m min-w-0 truncate" data-testid="profile-name">{{ name || t('profile.anonymous') }}</h2>
          <span v-if="nameEmoji" class="shrink-0 text-[24px] leading-none" data-testid="profile-emoji">{{ nameEmoji }}</span>
        </div>
        <div class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
          <p class="t-body-m text-muted" data-testid="profile-plan">{{ planLine }}</p>
          <span class="chip" :class="isPro ? 'chip-ink' : 'chip-neutral'" :data-plan="isPro ? 'pro' : 'free'" data-testid="profile-chip">
            {{ planChip }}
          </span>
        </div>
      </section>

      <!-- The statistics of the kit: equal columns with 1 px lines between them, the numbers in Numeric/L. What the screen already knows. -->
      <section v-if="stats.length > 0" class="card flex items-stretch px-2 py-4" :aria-label="t('profile.stats')" data-testid="profile-stats">
        <template v-for="(stat, index) in stats" :key="stat.key">
          <span v-if="index > 0" class="my-1 w-px shrink-0 bg-divider" aria-hidden="true" />
          <div class="flex min-w-0 flex-1 flex-col items-center gap-1 px-1 text-center" :data-testid="`stat-${stat.key}`">
            <span class="t-numeric-l">{{ stat.value }}</span>
            <span class="text-[13px] leading-[18px] text-muted">{{ stat.label }}</span>
          </div>
        </template>
      </section>

      <!-- Three achievements of the kit: the newest received first, then the nearest ahead; "N of M" leads to all of them. -->
      <section v-if="badges.length > 0" :aria-label="t('achievements.title')" data-testid="profile-achievements">
        <div class="mb-3 flex items-baseline justify-between gap-3">
          <h2 class="t-title-m">{{ t('achievements.title') }}</h2>
          <RouterLink :to="{ name: 'achievements' }" class="tap-target shrink-0 text-[13px] leading-[18px] font-semibold text-accent" data-testid="profile-achievements-all">
            {{ t('achievements.summary', { unlocked: achievements.data?.unlocked_count ?? 0, total: achievements.data?.total ?? 0 }) }}
          </RouterLink>
        </div>
        <div class="flex justify-between">
          <AchievementBadge
            v-for="badge in badges"
            :key="badge.item.key"
            :art="badge.art"
            :state="badge.state"
            :progress="badge.progress"
            :title="badge.item.title"
            :meta="badge.meta"
          >
            <template #fallback><AchievementArt :item-key="badge.item.key" :locked="badge.state === 'locked'" /></template>
          </AchievementBadge>
        </div>
      </section>

      <ListGroup :header="t('profile.learning')" data-testid="group-results">
        <ListRow
          :to="{ name: 'achievements' }"
          :title="t('achievements.title')"
          icon="award" tile="gray"
          data-testid="row-achievements"
        />
        <ListRow
          v-if="activeCourseSlug"
          :to="`/courses/${encodeURIComponent(activeCourseSlug)}/readiness?source=profile`"
          :title="t('profile.readiness')"
          :subtitle="t('profile.readiness.hint')"
          icon="chart-bar" tile="gray"
          data-testid="row-readiness"
        />
        <ListRow
          :to="{ name: 'friends' }"
          :title="t('profile.friends')"
          icon="users" tile="gray"
          data-testid="row-friends"
        />
        <ListRow
          v-if="cups.hasAny"
          :to="{ name: 'cups' }"
          :title="t('profile.cups')"
          icon="trophy" tile="gray"
          data-testid="row-cups"
        />
      </ListGroup>

      <ListGroup :header="t('profile.account')" data-testid="group-account">
        <ListRow
          :title="LANGUAGE_TITLE"
          :value="languageLabel || locale.toUpperCase()"
          icon="globe-alt" tile="gray"
          :chevron="true"
          data-testid="row-language"
          @click="languageOpen = true"
        />
        <ListRow
          :to="{ name: 'settings' }"
          :title="t('settings.title')"
          icon="cog-6-tooth" tile="gray"
          data-testid="row-settings"
        />
        <ListRow
          :to="{ name: 'billing' }"
          :title="t('billing.title')"
          :value="planChip"
          icon="crown" tile="gray"
          data-testid="row-billing"
        />
        <!-- The emoji of Pro (docs/31 §5а): Pro — the symbol, to the picker of the Settings; Free — a lock, to the paywall. -->
        <ListRow
          v-if="emojiOptions.length > 0 && !emojiLocked"
          :to="{ name: 'settings' }"
          :title="t('profile.pro_emoji')"
          icon="sparkles" tile="gray"
          :value="emojiValue"
          data-locked="false"
          data-testid="row-pro-emoji"
        />
        <ListRow
          v-else-if="emojiOptions.length > 0"
          :title="t('profile.pro_emoji')"
          icon="sparkles" tile="gray"
          :chevron="true"
          data-locked="true"
          data-testid="row-pro-emoji"
          @click="emojiPaywallOpen = true"
        >
          <template #trailing>
            <span class="flex shrink-0 items-center gap-1 text-[15px] leading-5 text-muted" data-testid="pro-emoji-locked">
              <AppIcon name="lock-closed" :size="16" />
              {{ t('profile.pro_emoji.locked') }}
            </span>
          </template>
        </ListRow>
      </ListGroup>

      <!-- What the mock-up does not have stays here, in the same style (owner's decision). -->
      <ListGroup :header="t('profile.more')" data-testid="group-more">
        <ListRow
          :to="{ name: 'referrals' }"
          :title="t('referrals.title')"
          :subtitle="t('profile.referrals.hint')"
          icon="gift" tile="gray"
          data-testid="row-referrals"
        />
        <ListRow
          :title="t('profile.how_it_works')"
          icon="information-circle" tile="gray"
          :chevron="true"
          data-testid="row-intro"
          @click="introOpen = true"
        />
      </ListGroup>

      <section v-if="showCaravan" class="card space-y-3" :aria-label="t('profile.caravan.title')" data-testid="profile-caravan">
        <h2 class="t-title-m">{{ t('profile.caravan.title') }}</h2>
        <div class="flex items-center gap-3">
          <!-- The frame goes round the avatar: it is drawn behind a smaller avatar. -->
          <span class="relative flex size-[72px] shrink-0 items-center justify-center" data-testid="caravan-frame">
            <GameItemImage v-if="frame" :name="frame.asset" :size="72" class="absolute inset-0" data-testid="caravan-frame-image" />
            <span class="relative flex size-11 items-center justify-center rounded-full bg-muhr font-display text-[20px] leading-none font-semibold text-on-muhr" aria-hidden="true">{{ initial }}</span>
          </span>
          <ul class="grid min-w-0 flex-1 grid-cols-3 gap-2">
            <li v-for="slot in worn" :key="slot.slot" class="flex min-w-0 flex-col items-center gap-1 text-center" :data-testid="`caravan-${slot.slot}`">
              <span class="flex size-12 items-center justify-center rounded-sm bg-sunken">
                <GameItemImage v-if="slot.item" :name="slot.item.asset" :size="40" />
              </span>
              <span class="w-full truncate text-[12px] leading-4 text-muted">{{ slot.label }}</span>
              <span v-if="!slot.item" class="sr-only">{{ t('gameCaravan.profile.none') }}</span>
            </li>
          </ul>
        </div>
        <RouterLink :to="{ name: 'game-caravan', query: { from: 'profile' } }" class="btn btn-secondary btn-block" data-testid="profile-caravan-open">
          {{ t('profile.caravan.open') }}
        </RouterLink>
      </section>

      <section v-if="!isPro" class="card space-y-3" data-testid="profile-pro-card">
        <div class="flex items-start gap-3">
          <span class="icon-tile" aria-hidden="true"><AppIcon name="crown" :size="20" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="t-title-m">{{ t('profile.pro.title') }}</h2>
            <p class="t-body-m mt-1 text-muted">{{ t('profile.pro.text') }}</p>
          </div>
        </div>
        <RouterLink :to="{ name: 'billing' }" class="btn btn-primary btn-block" data-testid="profile-pro-cta">
          {{ t('profile.pro.more') }}
        </RouterLink>
      </section>

      <p v-if="support" class="px-4 text-center text-[13px] leading-[18px] text-muted" data-testid="profile-support">
        {{ t('profile.support') }}
        <a
          v-if="support.href"
          :href="support.href"
          target="_blank"
          rel="noopener noreferrer"
          class="break-all text-accent underline"
          data-testid="profile-support-link"
        >{{ support.label }}</a>
        <span v-else class="font-semibold break-all" data-testid="profile-support-text">{{ support.label }}</span>
      </p>
    </div>

    <IntroSheet v-if="introOpen" @close="introOpen = false" />
    <LanguageSheet v-if="languageOpen" @close="languageOpen = false" />
    <PaywallSheet v-if="emojiPaywallOpen" reason="info" @close="emojiPaywallOpen = false" @review="onEmojiPaywallReview" />
  </div>
</template>
