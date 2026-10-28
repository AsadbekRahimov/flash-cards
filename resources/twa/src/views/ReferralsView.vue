<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import InitialAvatar from '@/components/InitialAvatar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useReferralsStore } from '@/stores/referrals'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { ReferralStatus } from '@/api/types'

/**
 * "Invite a friend" (docs/13 §7.1, docs/14 §6.7, docs/04 A.12): the reward and the three steps of the rule, the
 * learner's invite link with Telegram's share dialog, the counts and the invited learners (first names only).
 * Back -> Profile; the way to the friends and challenges is the row at the bottom.
 */
const auth = useAuthStore()
const referrals = useReferralsStore()
const { shareUrl, haptic } = useTelegram()
const { copy } = useCopyToClipboard()
const { t, locale } = useI18n()

useBackButton({ name: 'profile' })

const failed = ref('')

const data = computed(() => referrals.data)
const message = computed(() => failed.value || referrals.errorMessage)

/** 1. send the link, 2. the friend studies, 3. both get Pro: the old one-sentence rule, split in the order of events. */
const steps = computed<string[]>(() => {
  const d = data.value
  if (!d) return []
  return [
    t('referrals.step1'),
    t('referrals.step2', { days: d.activation.days, reviews: d.activation.reviews_per_day, window: d.activation.window_days }),
    t('referrals.step3', { reward: d.reward_days }),
  ]
})

const STATUSES: Record<ReferralStatus, { label: StringKey; chip: string }> = {
  pending: { label: 'referrals.status.pending', chip: 'chip-neutral' },
  activated: { label: 'referrals.status.activated', chip: 'chip-accent' },
  rewarded: { label: 'referrals.status.rewarded', chip: 'chip-success' },
}

const COUNTS: { key: 'invited' | 'activated' | 'rewarded'; label: StringKey }[] = [
  { key: 'invited', label: 'referrals.counts.invited' },
  { key: 'activated', label: 'referrals.counts.activated' },
  { key: 'rewarded', label: 'referrals.counts.rewarded' },
]

function share(): void {
  if (!data.value?.link) return
  haptic('light')
  shareUrl(data.value.link, t('referrals.share_text'))
}

async function copyLink(): Promise<void> {
  if (!data.value?.link) return
  await copy(data.value.link)
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
  void load()
})
</script>

<template>
  <div class="page" data-testid="referrals-view">
    <PageHeader :title="t('referrals.title')" />

    <LoadingState v-if="!data && !message" />

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <div v-else class="space-y-3">
      <section class="card" data-testid="hero">
        <div class="flex items-center gap-3">
          <span class="icon-tile tile-pink size-12 rounded-2xl"><AppIcon name="gift" :size="26" /></span>
          <h2 class="min-w-0 flex-1 text-xl leading-6 font-bold" data-testid="hero-title">
            {{ t('referrals.hero', { reward: data.reward_days }) }}
          </h2>
        </div>

        <ol class="mt-4 space-y-3" data-testid="rule">
          <li v-for="(step, index) in steps" :key="index" class="flex items-start gap-3">
            <span
              class="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-[13px] leading-none font-semibold text-accent-ink"
              aria-hidden="true"
            >
              {{ index + 1 }}
            </span>
            <span class="text-[15px] leading-6">{{ step }}</span>
          </li>
        </ol>

        <template v-if="data.link">
          <p class="mt-4 text-[13px] leading-[18px] text-tg-hint">{{ t('referrals.link_label') }}</p>
          <!-- Read-only field: the link wraps by characters and is selected with one tap, so it can be copied by hand too. -->
          <div class="field mt-1.5 font-mono text-[13px] leading-[18px] break-all select-all" data-testid="link">{{ data.link }}</div>
          <div class="mt-3 space-y-2">
            <button type="button" class="btn btn-primary btn-block" data-testid="share-btn" @click="share">
              {{ t('referrals.share') }}
            </button>
            <button type="button" class="btn btn-tinted btn-block" data-testid="copy-btn" @click="copyLink">
              {{ t('referrals.copy') }}
            </button>
          </div>
        </template>
        <template v-else>
          <p class="mt-4 text-[13px] leading-[18px] text-tg-hint">{{ t('referrals.code_label') }}</p>
          <div class="field mt-1.5 font-mono text-lg select-all" data-testid="code">{{ data.code }}</div>
          <p class="mt-3 text-[15px] leading-5 text-tg-subtitle" data-testid="no-link">{{ t('referrals.no_link', { code: data.code }) }}</p>
        </template>
      </section>

      <div class="grid grid-cols-3 gap-2" data-testid="counts">
        <div v-for="count in COUNTS" :key="count.key" class="card flex flex-col items-center px-1.5 py-3 text-center">
          <div class="text-2xl leading-8 font-bold tabular-nums" :data-testid="`count-${count.key}`">{{ data.counts[count.key] }}</div>
          <div class="mt-0.5 text-xs leading-4 break-words text-tg-hint">{{ t(count.label) }}</div>
        </div>
      </div>
      <p v-if="data.counts.capped > 0" class="px-4 text-[13px] leading-[18px] text-tg-hint" data-testid="capped-hint">
        {{ t('referrals.counts.capped', { count: data.counts.capped }) }}
      </p>

      <ListGroup :header="t('referrals.list.title')" :data-testid="data.referrals.length > 0 ? 'list' : undefined">
        <EmptyState v-if="data.referrals.length === 0" icon="user-plus" :text="t('referrals.list.empty')" data-testid="list-empty" />
        <ListRow
          v-for="(item, index) in data.referrals"
          :key="index"
          :title="item.first_name || t('referrals.list.anonymous')"
          :subtitle="formatDate(item.linked_at, 'dayMonthShort', locale)"
          data-testid="referral-row"
        >
          <template #leading>
            <InitialAvatar :name="item.first_name || t('referrals.list.anonymous')" size="sm" />
          </template>
          <template #trailing>
            <span class="chip shrink-0" :class="STATUSES[item.status].chip" data-testid="referral-status">
              {{ t(STATUSES[item.status].label) }}
            </span>
          </template>
        </ListRow>
      </ListGroup>

      <ListGroup>
        <ListRow :to="{ name: 'friends' }" icon="users" tile="blue" :title="t('friends.open')" data-testid="friends-link" />
      </ListGroup>
    </div>
  </div>
</template>
