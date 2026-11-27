<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AchievementArt from '@/components/achievements/AchievementArt.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { api } from '@/api'
import type { StoryShare, StoryShareKind } from '@/api/shareTypes'
import type { AchievementItem } from '@/api/types'
import { BRAND } from '@/brand'
import { useBackButton } from '@/composables/useBackButton'
import { useShare } from '@/composables/useShare'
import { useStoryShare, type StoryFallbackTarget } from '@/composables/useStoryShare'
import { useTelegram } from '@/composables/useTelegram'
import { useAchievementsStore } from '@/stores/achievements'
import { useFriendsStore } from '@/stores/friends'
import { useReferralsStore } from '@/stores/referrals'
import { useTodayStore } from '@/stores/today'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import { useStoryI18n } from '@/i18n/story'

/**
 * F3 of the kit Muhrla (03-new-features/F3-share-stories.png, docs/40 §2.4): "Share to a story" and "Call a friend to a battle".
 * Opened from Achievements (`kind=achievement&subject=<key>`, a received one) and from F4 "Серия спасена" (`kind=streak`).
 *
 * - The preview is drawn from what the app already holds - the streak of "Today", the achievement of the Achievements screen: a 9:16
 *   card in ink (the server's picture is ink in both themes), the stamp of 150 px, the title, the line and "Muhrla · @bot". Opening the
 *   screen asks the server for nothing; without that data (a reload on this address) it goes back to where the card lives.
 * - "Поделиться в сторис" (Primary) - `useStoryShare`: the picture of the server (`POST /share/story`) is asked only on the tap and once
 *   for the screen; the story editor of Telegram (7.8+), else the plain share. Telegram tells nothing back, so nothing is said to be
 *   "posted". A refusal of the limit / the network / the server - the text and "Retry". Below 7.8 the button reads "Поделиться".
 * - "Позвать друга на баттл" (Secondary) - the existing invitation of a friend (a battle is only with a friend): `useShare('friend_invite')`
 *   with the f_ link of `GET /friends` (the one the store holds; asked on this tap only when it holds none).
 * - The line under the card: the reward for friends is days of Pro (docs/40 §3, В4: no "+100 баллов лиги").
 * - Telegram's Back - to the screen before (Achievements / the streak screen), else to where the card lives.
 */
const props = defineProps<{ kind: StoryShareKind; subject: string | null }>()

const router = useRouter()
const today = useTodayStore()
const achievements = useAchievementsStore()
const referrals = useReferralsStore()
const friendsStore = useFriendsStore()
const { haptic } = useTelegram()
const { t } = useI18n()
const { t: st, tn: stn } = useStoryI18n()

const HOME = computed(() => (props.kind === 'achievement' ? ({ name: 'achievements' } as const) : ({ name: 'today' } as const)))

function leave(): void {
  if (router.options.history.state.back !== null && router.options.history.state.back !== undefined) router.back()
  else void router.replace(HOME.value)
}

useBackButton(leave)

/** The achievement of the card: a received one of the Achievements screen; null - none (the screen leaves). */
const achievement = computed((): AchievementItem | null => {
  if (props.kind !== 'achievement' || props.subject === null) return null
  const item = achievements.data?.items.find((entry) => entry.key === props.subject) ?? null
  return item !== null && item.unlocked_at !== null ? item : null
})

const streakDays = computed(() => (props.kind === 'streak' ? (today.data?.streak.current ?? 0) : 0))

/** What the card shows; null - the app holds nothing to show. */
const card = computed((): { art: string; title: string; text: string } | null => {
  if (props.kind === 'achievement') {
    const item = achievement.value
    return item === null ? null : { art: item.key, title: item.title, text: item.description }
  }
  const days = streakDays.value
  if (days <= 0) return null
  const goal = today.data?.goal.target ?? 0
  return {
    art: 'streak_7',
    title: stn('story.streak.title', days),
    text: goal > 0 ? stn('story.streak.text', goal, { brand: BRAND }) : '',
  }
})

onMounted(() => {
  if (card.value === null) void router.replace(HOME.value)
})

/** The bot of the links the app already holds (`t.me/<bot>?start=…`), for "Muhrla · @bot" of the card; none known - the brand alone. */
const botHandle = computed((): string | null => {
  const links = [achievements.data?.share_link, referrals.data?.link, friendsStore.friends?.invite_link]
  for (const link of links) {
    const match = typeof link === 'string' ? /^https?:\/\/t\.me\/([A-Za-z0-9_]{3,64})(?:[/?]|$)/.exec(link) : null
    if (match?.[1]) return `@${match[1]}`
  }
  return null
})

/** A short window (320 x 568 and the like): a smaller card and stamp, so the two buttons stay in view. */
const compact = typeof window !== 'undefined' && window.innerHeight < 700
const ART = compact ? 88 : 150

/** The invite link asked on a tap of this screen (none known before it): asked once. */
let referralLink: string | null = null

/** The link and the text of the plain share: the learner's invite link (the answer of the server, else what the app holds, else asked now). */
async function fallbackOf(story: StoryShare | null): Promise<StoryFallbackTarget | null> {
  if (props.kind === 'achievement') {
    const item = achievement.value
    const link = achievements.data?.share_link ?? story?.widget_link?.url ?? null
    if (item === null || link === null) return null
    const key = item.key
    return {
      kind: 'achievement',
      subject: key,
      link,
      text: item.share_text ?? item.title,
      onShared: () => void achievements.markShared(key),
    }
  }
  let link = story?.widget_link?.url ?? referrals.data?.link ?? achievements.data?.share_link ?? referralLink
  if (link === null) {
    try {
      link = (await api.referrals.get()).link
      referralLink = link
    } catch {
      link = null
    }
  }
  if (link === null) return null
  return { kind: 'invite', subject: null, link, text: stn('story.streak.share_text', streakDays.value, { brand: BRAND }) }
}

const story = useStoryShare(props.kind, props.subject, fallbackOf, { noLink: () => st('story.no_link') })
const canStory = story.canShareToStory()

async function shareStory(): Promise<void> {
  if (busy.value) return
  haptic('light')
  battleError.value = ''
  await story.share()
}

// "Call a friend to a battle": the invitation of a friend that exists (FriendsView), with the f_ link.
const battle = useShare()
const battleError = ref('')
const battleLoading = ref(false)
let inviteLink: string | null = null

async function callFriend(): Promise<void> {
  if (busy.value) return
  haptic('light')
  battleError.value = ''
  let link = inviteLink ?? friendsStore.friends?.invite_link ?? null
  if (link === null) {
    battleLoading.value = true
    try {
      link = (await api.friends.get()).invite_link
      inviteLink = link
    } catch (err) {
      battleError.value = errorMessage(err, 'settings')
      return
    } finally {
      battleLoading.value = false
    }
  }
  if (link === null) {
    battleError.value = st('story.no_link')
    return
  }
  await battle.share('friend_invite', null, { link, text: t('friends.invite.share_text') })
}

const busy = computed(() => story.pending.value || battle.pending.value || battleLoading.value)
const error = computed(() => story.error.value || battleError.value)

function retry(): void {
  if (battleError.value) void callFriend()
  else void shareStory()
}
</script>

<template>
  <div v-if="card" class="page" :data-kind="kind" data-testid="share-story-view">
    <h1 class="sr-only">{{ st('story.heading') }}</h1>

    <div class="flex flex-1 flex-col items-center pt-2">
      <!-- The preview: what the picture of the story shows, drawn here from the data of the app (the picture itself comes on the tap). -->
      <figure
        class="story-card flex flex-col items-center justify-center rounded-[28px] text-center"
        :class="compact ? 'story-card--compact' : undefined"
        role="img"
        :aria-label="`${st('story.preview')}: ${card.title}`"
        data-testid="story-card"
      >
        <AchievementArt :item-key="card.art" :size="ART" />
        <p class="story-title line-clamp-2 break-words font-display font-semibold" data-testid="story-title">{{ card.title }}</p>
        <p v-if="card.text" class="story-text line-clamp-3" data-testid="story-text">{{ card.text }}</p>
        <p class="story-brand-line flex items-baseline gap-1.5" data-testid="story-brand">
          <span class="story-brand font-display font-semibold text-muhr">{{ BRAND }}</span>
          <span v-if="botHandle" class="story-bot">· {{ botHandle }}</span>
        </p>
      </figure>

      <p
        class="max-w-80 text-center text-balance text-muted"
        :class="compact ? 'mt-3 text-[13px] leading-[18px]' : 't-body-m mt-5'"
        data-testid="story-hint"
      >{{ st('story.hint') }}</p>
    </div>

    <div class="flex flex-col gap-2" :class="compact ? 'mt-4' : 'mt-6'" data-testid="actions">
      <InlineAlert v-if="error" tone="danger" class="mb-1" data-testid="story-error">
        {{ error }}
        <button type="button" class="ml-1 font-semibold underline underline-offset-2" data-testid="story-retry" @click="retry">
          {{ t('common.retry') }}
        </button>
      </InlineAlert>
      <button
        type="button"
        class="btn btn-primary btn-block"
        :aria-disabled="busy ? 'true' : undefined"
        :aria-busy="story.pending.value ? 'true' : undefined"
        :aria-label="story.pending.value ? st('story.preparing') : undefined"
        data-testid="story-share"
        @click="shareStory"
      >
        <span v-if="story.pending.value" class="btn-dots" aria-hidden="true"><span /><span /><span /></span>
        <span v-else>{{ canStory ? st('story.share') : st('story.share_plain') }}</span>
      </button>
      <button
        type="button"
        class="btn btn-secondary btn-block"
        :aria-disabled="busy ? 'true' : undefined"
        :aria-busy="battle.pending.value || battleLoading ? 'true' : undefined"
        data-testid="story-battle"
        @click="callFriend"
      >
        <span
          v-if="battle.pending.value || battleLoading"
          class="size-4 shrink-0 animate-spin rounded-full border-2 border-track border-t-accent motion-reduce:animate-none"
          aria-hidden="true"
        />
        <span>{{ st('story.battle') }}</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
/*
 * The card of the story (kit F3: 232 x 412 on a 390 px screen): 9:16, ink in both themes - the picture of the server is ink, and the
 * preview shows what goes out. Light: the inverse pair; dark: the surface (the ink of the dark palette) in a hairline, so it stands off
 * the darker page.
 */
.story-card {
  width: 232px;
  aspect-ratio: 9 / 16;
  padding: 24px 20px;
  background-color: var(--app-inverse);
  color: var(--app-on-inverse);
}
.story-card--compact {
  width: 144px;
  padding: 14px 12px;
}
:root[data-theme='dark'] .story-card {
  background-color: var(--app-surface);
  color: var(--app-text);
  box-shadow: inset 0 0 0 1px var(--app-hairline);
}
.story-title {
  margin-top: 16px;
  font-size: 19px;
  line-height: 24px;
  letter-spacing: -0.01em;
}
.story-text {
  margin-top: 8px;
  font-size: 12px;
  line-height: 17px;
}
.story-brand-line {
  margin-top: 16px;
}
.story-brand {
  font-size: 14px;
  line-height: 18px;
}
.story-bot {
  font-size: 11px;
  line-height: 14px;
}
.story-card--compact .story-title {
  margin-top: 10px;
  font-size: 14px;
  line-height: 18px;
}
.story-card--compact .story-text {
  margin-top: 4px;
  font-size: 10px;
  line-height: 14px;
}
.story-card--compact .story-brand-line {
  margin-top: 8px;
}
.story-card--compact .story-brand {
  font-size: 12px;
}
</style>
