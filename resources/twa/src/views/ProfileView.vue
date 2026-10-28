<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { formatDate } from '@/composables/format'
import { supportContactLink } from '@/composables/supportContact'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'

/**
 * "Profile" tab (docs/14 §5.2): who the learner is and the plan, the way to Pro, and the entries to the
 * secondary screens (achievements, invitations, friends, subscription, settings). The data is `GET /me` through
 * the billing store (the plan and the support contact) and the learner's name from the auth store (`/auth` and
 * `/me` carry the same `first_name`). A tab root: no system "Back" button.
 */
const { t, tn, locale } = useI18n()
const auth = useAuthStore()
const billing = useBillingStore()
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

const isPro = computed(() => entitlement.value?.plan === 'pro')

/** «Pro до 1 декабря 2026 г.» / «Pro без срока» / «Pro от центра» / «Бесплатный тариф: 20 новых слов в день». */
const planLine = computed(() => {
  const current = entitlement.value
  if (current === null) return ''

  if (current.plan === 'pro') {
    const renewal = current.renewal ?? null
    if (renewal === null && current.source.startsWith('sponsor:')) return t('profile.plan.pro_center')

    const until = renewal?.ends_at ?? current.until
    return until
      ? t('profile.plan.pro_until', { date: formatDate(until, 'dayMonthYear', locale.value) })
      : t('profile.plan.pro_forever')
  }

  return current.new_words_per_day === null
    ? t('profile.plan.free_plain')
    : tn('profile.plan.free', current.new_words_per_day)
})

const planChip = computed(() => t(isPro.value ? 'profile.chip.pro' : 'profile.chip.free'))
const support = computed(() => supportContactLink(paywall.value?.contact))

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="profile-view">
    <LoadingState v-if="status === 'loading'" variant="list" />

    <ErrorState v-else-if="status === 'error'" :message="errorText" @retry="load" />

    <div v-else class="space-y-4">
      <header class="flex items-center gap-4 pt-2" data-testid="profile-header">
        <span
          v-if="initial"
          class="flex size-16 shrink-0 items-center justify-center rounded-full bg-accent text-[28px] leading-none font-semibold text-tg-button-text"
          aria-hidden="true"
          data-testid="profile-avatar"
        >
          {{ initial }}
        </span>
        <AppIcon v-else name="user-circle" solid :size="64" class="text-tg-hint" data-testid="profile-avatar" />

        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2">
            <h1 class="min-w-0 truncate text-[22px] leading-7 font-bold" data-testid="profile-name">
              {{ name || t('profile.anonymous') }}
            </h1>
            <span class="chip shrink-0" :class="isPro ? 'chip-accent' : 'chip-neutral'" data-testid="profile-chip">
              {{ planChip }}
            </span>
          </div>
          <p class="mt-0.5 text-[15px] leading-5 text-tg-subtitle" data-testid="profile-plan">{{ planLine }}</p>
        </div>
      </header>

      <section v-if="!isPro" class="card space-y-3" data-testid="profile-pro-card">
        <div>
          <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('profile.pro.title') }}</h2>
          <p class="mt-1 text-[15px] leading-5 text-tg-subtitle">{{ t('profile.pro.text') }}</p>
        </div>
        <RouterLink :to="{ name: 'billing' }" class="btn btn-primary btn-block" data-testid="profile-pro-cta">
          {{ t('profile.pro.more') }}
        </RouterLink>
      </section>

      <ListGroup>
        <ListRow
          :to="{ name: 'achievements' }"
          icon="trophy"
          tile="yellow"
          :title="t('achievements.title')"
          data-testid="row-achievements"
        />
        <ListRow
          :to="{ name: 'referrals' }"
          icon="gift"
          tile="pink"
          :title="t('referrals.title')"
          :subtitle="t('profile.referrals.hint')"
          data-testid="row-referrals"
        />
        <ListRow
          :to="{ name: 'friends' }"
          icon="users"
          tile="blue"
          :title="t('profile.friends')"
          data-testid="row-friends"
        />
      </ListGroup>

      <ListGroup>
        <ListRow
          :to="{ name: 'billing' }"
          icon="credit-card"
          tile="purple"
          :title="t('billing.title')"
          :value="planChip"
          data-testid="row-billing"
        />
        <ListRow
          :to="{ name: 'settings' }"
          icon="cog-6-tooth"
          tile="gray"
          :title="t('settings.title')"
          data-testid="row-settings"
        />
      </ListGroup>

      <p v-if="support" class="px-4 pt-2 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="profile-support">
        {{ t('profile.support') }}
        <a
          v-if="support.href"
          :href="support.href"
          target="_blank"
          rel="noopener noreferrer"
          class="break-all text-tg-link underline"
          data-testid="profile-support-link"
        >{{ support.label }}</a>
        <span v-else class="font-semibold break-all" data-testid="profile-support-text">{{ support.label }}</span>
      </p>
    </div>
  </div>
</template>
