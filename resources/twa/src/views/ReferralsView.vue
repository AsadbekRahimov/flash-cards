<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useReferralsStore } from '@/stores/referrals'
import { useTelegram } from '@/composables/useTelegram'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { ReferralStatus } from '@/api/types'

/**
 * "Invite a friend" (docs/13 §7.1, docs/04 A.12): the learner's invite link with Telegram's share
 * dialog, the rule and the reward, the counts and the invited learners (first names only).
 */
const router = useRouter()
const auth = useAuthStore()
const referrals = useReferralsStore()
const { showBackButton, shareUrl, haptic } = useTelegram()
const { t, locale } = useI18n()

const failed = ref('')
const copied = ref(false)
let hideBack: (() => void) | null = null

const data = computed(() => referrals.data)
const message = computed(() => failed.value || referrals.errorMessage)

const STATUS_LABELS: Record<ReferralStatus, StringKey> = {
  pending: 'referrals.status.pending',
  activated: 'referrals.status.activated',
  rewarded: 'referrals.status.rewarded',
}

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }).format(new Date(iso))
}

function share(): void {
  if (!data.value?.link) return
  haptic('light')
  shareUrl(data.value.link, t('referrals.share_text'))
}

async function copy(): Promise<void> {
  if (!data.value?.link) return
  try {
    await navigator.clipboard.writeText(data.value.link)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    /* clipboard unavailable: the link is visible on the screen anyway */
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
  await referrals.load()
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
    <h1 class="text-xl font-bold mb-4">🎁 {{ t('referrals.title') }}</h1>

    <RouterLink :to="{ name: 'friends' }" class="btn btn-secondary w-full mb-3" data-testid="friends-link">
      👥 {{ t('friends.open') }}
    </RouterLink>

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
      <p class="card text-sm" data-testid="rule">
        {{ t('referrals.rule', { days: data.activation.days, reviews: data.activation.reviews_per_day, window: data.activation.window_days, reward: data.reward_days }) }}
      </p>

      <div class="card space-y-3">
        <template v-if="data.link">
          <div class="text-xs text-tg-hint">{{ t('referrals.link_label') }}</div>
          <div class="font-mono text-sm break-all" data-testid="link">{{ data.link }}</div>
          <button type="button" class="btn btn-primary w-full" data-testid="share-btn" @click="share">
            {{ t('referrals.share') }}
          </button>
          <button type="button" class="btn btn-secondary w-full" data-testid="copy-btn" @click="copy">
            {{ copied ? '✓ ' + t('referrals.copied') : t('referrals.copy') }}
          </button>
        </template>
        <template v-else>
          <div class="text-xs text-tg-hint">{{ t('referrals.code_label') }}</div>
          <div class="font-mono text-lg" data-testid="code">{{ data.code }}</div>
          <p class="text-sm text-tg-hint" data-testid="no-link">{{ t('referrals.no_link', { code: data.code }) }}</p>
        </template>
      </div>

      <div class="card grid grid-cols-3 text-center" data-testid="counts">
        <div>
          <div class="text-2xl font-bold" data-testid="count-invited">{{ data.counts.invited }}</div>
          <div class="text-xs text-tg-hint">{{ t('referrals.counts.invited') }}</div>
        </div>
        <div>
          <div class="text-2xl font-bold" data-testid="count-activated">{{ data.counts.activated }}</div>
          <div class="text-xs text-tg-hint">{{ t('referrals.counts.activated') }}</div>
        </div>
        <div>
          <div class="text-2xl font-bold" data-testid="count-rewarded">{{ data.counts.rewarded }}</div>
          <div class="text-xs text-tg-hint">{{ t('referrals.counts.rewarded') }}</div>
        </div>
      </div>

      <div class="card space-y-2">
        <div class="font-semibold">{{ t('referrals.list.title') }}</div>
        <p v-if="data.referrals.length === 0" class="text-sm text-tg-hint" data-testid="list-empty">{{ t('referrals.list.empty') }}</p>
        <ul v-else class="divide-y" data-testid="list">
          <li v-for="(item, index) in data.referrals" :key="index" class="flex items-center justify-between py-2 text-sm" data-testid="referral-row">
            <span>{{ item.first_name || t('referrals.list.anonymous') }}</span>
            <span class="text-tg-hint">{{ t(STATUS_LABELS[item.status]) }} · {{ formatDate(item.linked_at) }}</span>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
