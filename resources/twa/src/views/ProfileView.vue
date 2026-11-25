<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import IntroSheet from '@/components/IntroSheet.vue'
import LanguageSheet from '@/components/LanguageSheet.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import GameItemImage from '@/components/game/GameItemImage.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { formatDate } from '@/composables/format'
import { trialEndsAt } from '@/composables/trial'
import { supportContactLink } from '@/composables/supportContact'
import { useBackButton } from '@/composables/useBackButton'
import { useLocaleSwitch } from '@/composables/useLocaleSwitch'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useGameShopStore } from '@/stores/gameShop'
import { useSettingsStore } from '@/stores/settings'
import { errorMessage } from '@/i18n/errors'
import { LANGUAGE_TITLE, useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { GameItemSlot } from '@/api/gameShopTypes'

/**
 * "Profile" tab (docs/14 §5.2, docs/design/2026-11-25-redesign Profile): the brand row and the title; the big tinted card — the initial,
 * the name with the emoji of Pro (docs/31 §5а: `learner.pro_emoji`, sent only while Pro lasts), the Free / Pro chip and the plan line
 * (for Free the daily limit of `/me`, never a number of the mock-up); then two tinted groups, "My results" (achievements, readiness,
 * friends) and "My account" (language, settings, subscription, the emoji of Pro). What the mock-up does not have stays, below it in the
 * same style (owner's decision): invitations and "How it works", the caravan card, the Pro card of Free, the support contact.
 * The data is `GET /me` through the billing store (the plan and the support contact; the settings and the emoji are handed on to the
 * settings store) and the learner's name from the auth store (`/auth` and `/me` carry the same `first_name`). A tab root: no system
 * "Back" button.
 */
const { t, tn, locale } = useI18n()
const auth = useAuthStore()
const billing = useBillingStore()
const shop = useGameShopStore()
const settings = useSettingsStore()
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
  await billing.load()
  attempted.value = true
}

const name = computed(() => (auth.learner?.first_name ?? '').trim())
/** First letter of the name (a whole code point, so an emoji is not cut in half). */
const initial = computed(() => (Array.from(name.value)[0] ?? '').toUpperCase())

/** The sheet "How it works" (the same one Home shows to a newcomer). */
const introOpen = ref(false)

/** "Til · Язык": the row shows the current language by its own name (the settings' list), the sheet changes it. */
const languageOpen = ref(false)
const { currentLabel: languageLabel } = useLocaleSwitch()

const isPro = computed(() => entitlement.value?.plan === 'pro')

/** «Pro до 1 декабря 2026 г.» / «Пробный Pro до 1 декабря 2026 г.» / «Pro без срока» / «Pro от центра» / «Бесплатный тариф: 20 новых слов в день». */
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

    <LoadingState v-if="status === 'loading'" variant="list" />

    <ErrorState v-else-if="status === 'error'" :message="errorText" @retry="load" />

    <div v-else class="space-y-3">
      <!-- The big card of the mock-up: the initial, a thin line, the name with the emoji of Pro and the chip, the plan; books on the right. -->
      <section class="flex items-center gap-3 rounded-hero bg-highlight py-3.5 pr-2.5 pl-3.5" data-testid="profile-header">
        <span
          v-if="initial"
          class="flex size-[58px] shrink-0 items-center justify-center rounded-full bg-primary text-[26px] leading-none font-extrabold text-on-primary"
          aria-hidden="true"
          data-testid="profile-avatar"
        >
          {{ initial }}
        </span>
        <AppIcon v-else name="user-circle" solid :size="58" class="shrink-0 text-accent" data-testid="profile-avatar" />
        <span class="h-[46px] w-px shrink-0 bg-accent-line" aria-hidden="true" />

        <div class="flex min-w-0 flex-1 flex-col gap-1">
          <div class="flex min-w-0 items-center gap-2">
            <h2 class="min-w-0 truncate text-2xl leading-[30px] font-extrabold tracking-[-0.3px]" data-testid="profile-name">
              {{ name || t('profile.anonymous') }}
            </h2>
            <span v-if="nameEmoji" class="shrink-0 text-[22px] leading-none" data-testid="profile-emoji">{{ nameEmoji }}</span>
            <span
              class="inline-flex h-[22px] shrink-0 items-center rounded-full border px-[9px] text-[13px] leading-none font-bold"
              :class="isPro ? 'border-primary bg-primary text-on-primary' : 'border-accent-line bg-surface text-accent'"
              :data-plan="isPro ? 'pro' : 'free'"
              data-testid="profile-chip"
            >
              {{ planChip }}
            </span>
          </div>
          <p class="text-sm leading-[18px] text-muted" data-testid="profile-plan">{{ planLine }}</p>
        </div>

        <!-- The picture of the mock-up: two rolled books and a sprig, in the tokens of the palette; left out on a narrow screen. -->
        <svg
          aria-hidden="true"
          width="96"
          height="75"
          viewBox="0 0 200 156"
          fill="none"
          stroke-width="2.2"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="shrink-0 stroke-accent max-[359px]:hidden"
          data-testid="profile-art"
        >
          <path d="M152 68c-6-20 0-40 14-56" />
          <path d="M158 40c-14-2-22-12-22-24 12 0 22 8 22 24zM163 34c2-14 12-22 24-22 0 14-8 22-24 22zM154 58c-14 2-24-4-28-16 12-4 24 2 28 16z" class="fill-nav-active" />
          <path d="M40 66h112a8 8 0 0 1 8 8v20a8 8 0 0 1-8 8H40c-8 0-14-8-14-18s6-18 14-18z" class="fill-surface" />
          <path d="M48 66c-6 8-6 28 0 36" />
          <path d="M58 76h92M58 84h92M58 92h92" stroke-width="1.4" />
          <path d="M30 104h124a8 8 0 0 1 8 8v22a8 8 0 0 1-8 8H30c-8 0-14-8-14-19s6-19 14-19z" class="fill-nav-active" />
          <path d="M38 104c-6 8-6 30 0 38" />
          <path d="M48 114h104M48 123h104M48 132h104" stroke-width="1.4" />
          <path d="M4 148h192M22 153h56M120 153h52" stroke-width="1.4" />
        </svg>
      </section>

      <ListGroup highlight :header="t('profile.learning')" data-testid="group-results">
        <ListRow
          :to="{ name: 'achievements' }"
          :title="t('achievements.title')"
          icon="trophy" tile="blue"
          data-testid="row-achievements"
        />
        <ListRow
          v-if="activeCourseSlug"
          :to="`/courses/${encodeURIComponent(activeCourseSlug)}/readiness?source=profile`"
          :title="t('profile.readiness')"
          :subtitle="t('profile.readiness.hint')"
          icon="chart-bar" tile="blue"
          data-testid="row-readiness"
        />
        <ListRow
          :to="{ name: 'friends' }"
          :title="t('profile.friends')"
          icon="users" tile="blue"
          data-testid="row-friends"
        />
      </ListGroup>

      <ListGroup highlight :header="t('profile.account')" data-testid="group-account">
        <ListRow
          :title="LANGUAGE_TITLE"
          :value="languageLabel || locale.toUpperCase()"
          icon="globe-alt" tile="blue"
          :chevron="true"
          data-testid="row-language"
          @click="languageOpen = true"
        />
        <ListRow
          :to="{ name: 'settings' }"
          :title="t('settings.title')"
          icon="cog-6-tooth" tile="blue"
          data-testid="row-settings"
        />
        <ListRow
          :to="{ name: 'billing' }"
          :title="t('billing.title')"
          :value="planChip"
          icon="credit-card" tile="blue"
          data-testid="row-billing"
        />
        <!-- The emoji of Pro (docs/31 §5а): Pro — the symbol, to the picker of the Settings; Free — a lock, to the paywall. -->
        <ListRow
          v-if="emojiOptions.length > 0 && !emojiLocked"
          :to="{ name: 'settings' }"
          :title="t('profile.pro_emoji')"
          icon="sparkles" tile="blue"
          :value="emojiValue"
          data-locked="false"
          data-testid="row-pro-emoji"
        />
        <ListRow
          v-else-if="emojiOptions.length > 0"
          :title="t('profile.pro_emoji')"
          icon="sparkles" tile="blue"
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
      <ListGroup highlight :header="t('profile.more')" data-testid="group-more">
        <ListRow
          :to="{ name: 'referrals' }"
          :title="t('referrals.title')"
          :subtitle="t('profile.referrals.hint')"
          icon="gift" tile="blue"
          data-testid="row-referrals"
        />
        <ListRow
          :title="t('profile.how_it_works')"
          icon="information-circle" tile="blue"
          :chevron="true"
          data-testid="row-intro"
          @click="introOpen = true"
        />
      </ListGroup>

      <section v-if="showCaravan" class="card space-y-3" :aria-label="t('profile.caravan.title')" data-testid="profile-caravan">
        <h2 class="text-lg leading-6 font-extrabold">{{ t('profile.caravan.title') }}</h2>
        <div class="flex items-center gap-3">
          <!-- The frame goes round the avatar: it is drawn behind a smaller avatar. -->
          <span class="relative flex size-[72px] shrink-0 items-center justify-center" data-testid="caravan-frame">
            <GameItemImage v-if="frame" :name="frame.asset" :size="72" class="absolute inset-0" data-testid="caravan-frame-image" />
            <span class="relative flex size-11 items-center justify-center rounded-full bg-primary text-[20px] leading-none font-extrabold text-on-primary" aria-hidden="true">{{ initial }}</span>
          </span>
          <ul class="grid min-w-0 flex-1 grid-cols-3 gap-2">
            <li v-for="slot in worn" :key="slot.slot" class="flex min-w-0 flex-col items-center gap-1 text-center" :data-testid="`caravan-${slot.slot}`">
              <span class="flex size-12 items-center justify-center rounded-control bg-accent-soft">
                <GameItemImage v-if="slot.item" :name="slot.item.asset" :size="40" />
              </span>
              <span class="w-full truncate text-[12px] leading-4 text-muted">{{ slot.label }}</span>
              <span v-if="!slot.item" class="sr-only">{{ t('gameCaravan.profile.none') }}</span>
            </li>
          </ul>
        </div>
        <RouterLink :to="{ name: 'game-caravan', query: { from: 'profile' } }" class="btn btn-tinted btn-block" data-testid="profile-caravan-open">
          {{ t('profile.caravan.open') }}
        </RouterLink>
      </section>

      <section v-if="!isPro" class="card space-y-3" data-testid="profile-pro-card">
        <div>
          <h2 class="text-lg leading-6 font-extrabold">{{ t('profile.pro.title') }}</h2>
          <p class="mt-1 text-[15px] leading-5 text-muted">{{ t('profile.pro.text') }}</p>
        </div>
        <RouterLink :to="{ name: 'billing' }" class="btn btn-primary btn-block" data-testid="profile-pro-cta">
          {{ t('profile.pro.more') }}
        </RouterLink>
      </section>

      <p v-if="support" class="px-4 pt-2 text-center text-[13px] leading-[18px] text-muted" data-testid="profile-support">
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
