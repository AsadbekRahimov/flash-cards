<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import CupRules from '@/components/cup/CupRules.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { BRAND } from '@/brand'
import { cupFailure, cupPeriod, cupStatusLine, type CupFailure } from '@/composables/cupInfo'
import { useBackButton } from '@/composables/useBackButton'
import { useTelegram } from '@/composables/useTelegram'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useCupsStore } from '@/stores/cups'
import { useI18n } from '@/i18n'
import type { CupInvite } from '@/api/types'

/**
 * The entry from the link of the chat (docs/33 §7.1, docs/05 §5d, docs/04 A.23 `GET /cups/by-code/{code}`): `/cups/c/:code`
 * (`TwaScreen::CupInvite`) and `/cups/c/:code/play` (`CupPlay`, a participant goes straight to the round of the day). The code is only a
 * hint which cup to open: the server asks Telegram whether the learner is in the chat.
 *
 * - A participant (not one who left) is taken to the cup (`play` - to the round, while the cup runs).
 * - A member of the chat sees the invitation: the group, the level, the dates, the participants, the rules and "Name in the tournament"
 *   (seen in the chat), "Join" (`POST /cups/{id}/join`). A cup that is not open (a draft, over) says so, without the button.
 * - A refusal is a state of its own (`cupFailure`): `unavailable` (a stranger's or a missing code: ONE answer, nothing of the group is
 *   shown), `off`, `telegram` (try later, "Retry"), `busy` (wait and retry by hand - never a loop), `bot_not_admin` (ask the administrator
 *   of the group), `paused`, the rest with "Retry".
 */
const props = defineProps<{
  code: string
  /** `CupPlay`: the participant goes straight to the round. */
  play: boolean
  /** `?view=rules` of the button "Rules" of the card in the chat: the rules open at once. */
  rules?: boolean
}>()

/**
 * A bound of the field only: the server counts the name in what a person sees as characters (32, an emoji sequence is one) and says
 * why it refuses one (422 `cup_display_name_invalid {reason}`).
 */
const NAME_FIELD_MAX = 128

const router = useRouter()
const auth = useAuthStore()
const cups = useCupsStore()
const { haptic } = useTelegram()
const { t, tn, locale } = useI18n()

useBackButton({ name: 'today' })

const invite = ref<CupInvite | null>(null)
const failure = ref<CupFailure | null>(null)
const loading = ref(true)
const name = ref('')
const joining = ref(false)
const joinError = ref('')
const nameError = ref('')
let left = false
let seq = 0

async function load(): Promise<void> {
  const mine = ++seq
  loading.value = true
  failure.value = null
  try {
    await auth.ensure()
    const res = await api.cups.invite(props.code)
    if (left || mine !== seq) return
    if (res.me.joined && !res.me.left) {
      goInside(res.id, res.status === 'running')
      return
    }
    invite.value = res
    name.value = res.me.display_name ?? ''
  } catch (err) {
    if (left || mine !== seq) return
    invite.value = null
    failure.value = cupFailure(err)
  } finally {
    if (!left && mine === seq) loading.value = false
  }
}

/** A participant: the cup, or the round of the day for `CupPlay` (while the cup runs). */
function goInside(id: number, running: boolean): void {
  if (left) return
  void router.replace(props.play && running ? { name: 'cup-round', params: { id } } : { name: 'cup', params: { id } })
}

const open = computed((): boolean => invite.value !== null && (invite.value.status === 'scheduled' || invite.value.status === 'running') && invite.value.paused === null)
const period = computed((): string => (invite.value ? cupPeriod(invite.value, locale.value) : ''))
const statusLine = computed((): string => (invite.value ? cupStatusLine(invite.value, locale.value) : ''))
const trimmed = computed((): string => name.value.trim())
const canJoin = computed((): boolean => open.value && !joining.value && trimmed.value !== '')

/** "Join": with the name of the field (the server checks it before the membership). A second tap while it is on its way does nothing. */
async function join(): Promise<void> {
  const current = invite.value
  if (current === null || !canJoin.value) return
  haptic('light')
  joining.value = true
  joinError.value = ''
  nameError.value = ''
  try {
    await api.cups.join(current.id, trimmed.value)
    cups.invalidate()
    goInside(current.id, current.status === 'running')
  } catch (err) {
    if (left) return
    if (err instanceof ApiClientError && err.code === 'cup_display_name_invalid') nameError.value = cupFailure(err).message
    else joinError.value = cupFailure(err).message
  } finally {
    if (!left) joining.value = false
  }
}

const failureTitle = computed((): string => {
  switch (failure.value?.kind) {
    case 'unavailable':
      return t('cup.invite.unavailable_title')
    case 'off':
      return t('cup.off_title')
    case 'telegram':
    case 'busy':
      return t('cup.invite.check_title')
    case 'bot_not_admin':
      return t('cup.invite.bot_title')
    case 'paused':
      return t('cup.invite.paused_title')
    default:
      return t('common.error_title')
  }
})

watch(() => props.code, () => void load())

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  left = true
})
</script>

<template>
  <div class="page" :data-state="loading ? 'loading' : failure ? failure.kind : 'invite'" data-testid="cup-invite-view">
    <PageHeader :eyebrow="t('cup.brand_title', { brand: BRAND })" :title="invite ? invite.group_title : t('cup.title')" />

    <LoadingState v-if="loading" variant="cards" />

    <ErrorState v-else-if="failure && failure.retry" :title="failureTitle" :message="failure.message" data-testid="failure" @retry="load" />

    <EmptyState v-else-if="failure" icon="lock-closed" :title="failureTitle" :text="failure.message" data-testid="failure" />

    <div v-else-if="invite" class="space-y-3">
      <!-- The cup in the big card: the level, the dates, the state, the participants. -->
      <section class="flex flex-col gap-1.5 rounded-hero bg-highlight p-4" data-testid="invite-card">
        <p class="text-sm leading-[18px] font-semibold" data-testid="invite-level">{{ t('cup.level', { level: invite.level }) }}</p>
        <p v-if="period" class="text-[22px] leading-7 font-extrabold" data-testid="invite-period">{{ period }}</p>
        <p class="text-[15px] leading-5" data-testid="invite-status">{{ statusLine }}</p>
        <p class="text-[13px] leading-[18px] text-muted tabular-nums" data-testid="invite-participants">{{ tn('league.members', invite.participants_count) }}</p>
      </section>

      <details class="list group" :open="rules || undefined" data-testid="invite-rules">
        <summary class="list-row min-h-11 cursor-pointer list-none select-none active:bg-fill [&::-webkit-details-marker]:hidden">
          <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('cup.rules.title') }}</span>
        </summary>
        <div class="px-[18px] pt-1 pb-4">
          <CupRules :cup="invite" :rules="invite.rules" />
        </div>
      </details>

      <!-- Joining: the name in the tournament (the chat sees it) and "Join". -->
      <section v-if="open" class="card flex flex-col gap-3" data-testid="join-card">
        <label class="flex flex-col gap-1.5">
          <span class="text-[15px] leading-5 font-semibold">{{ t('cup.invite.name_label') }}</span>
          <input
            v-model="name"
            type="text"
            class="field"
            :maxlength="NAME_FIELD_MAX"
            autocomplete="nickname"
            :aria-invalid="nameError ? 'true' : undefined"
            :aria-describedby="nameError ? 'cup-name-error' : 'cup-name-hint'"
            :disabled="joining"
            data-testid="name-input"
          />
          <span id="cup-name-hint" class="text-[13px] leading-[18px] text-muted" data-testid="name-hint">{{ t('cup.invite.name_hint') }}</span>
        </label>
        <InlineAlert v-if="nameError" id="cup-name-error" tone="danger" :message="nameError" data-testid="name-error" />
        <InlineAlert v-if="joinError" tone="danger" :message="joinError" data-testid="join-error" />
        <button
          type="button"
          class="btn btn-primary btn-lg btn-block"
          :disabled="!canJoin"
          :aria-busy="joining ? 'true' : undefined"
          data-testid="join-btn"
          @click="join"
        >
          {{ invite.me.left ? t('cup.invite.rejoin') : t('cup.invite.join') }}
        </button>
      </section>

      <p v-else class="card text-[15px] leading-[22px] text-muted" data-testid="not-open">
        {{ invite.status === 'draft' ? t('cup.invite.draft') : invite.paused !== null ? statusLine : t('cup.invite.closed') }}
      </p>
    </div>
  </div>
</template>
