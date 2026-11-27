<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AchievementArt from '@/components/achievements/AchievementArt.vue'
import StickyActions from '@/components/StickyActions.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { api } from '@/api'
import { BACKGROUND } from '@/api/background'
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
 * - The preview is drawn from what the app already holds - the streak of "Today", the achievement of the Achievements screen - in the
 *   composition of the server's picture (`StoryCardRenderer`, 1080 x 1920): a 9:16 card in ink (ink in both themes), the stamp of the kit
 *   (a muhr ring, a paper disc with a dotted ring) with the number of days or the badge of the achievement (`AchievementKey::badge()`;
 *   a key this build does not know - its art), the title (up to 2 lines), the description (up to 3) and "Muhrla · @bot" (the brand alone
 *   while no link names the bot). Every size is the server's, scaled to the card. The caption under it says it is approximate (the fonts
 *   differ). Opening the screen asks the server for nothing; without that data (a reload on this address) it goes back to where the card
 *   lives.
 * - "Поделиться в сторис" (Primary) - `useStoryShare`: the picture of the server (`POST /share/story`) is asked only on the tap and once
 *   for the screen; the story editor of Telegram (7.8+), else the plain share. Telegram tells nothing back, so nothing is said to be
 *   "posted". A refusal of the limit / the network / the server - the text and "Retry". Below 7.8 the button reads "Поделиться".
 * - "Позвать друга на баттл" (Secondary) - the existing invitation of a friend (a battle is only with a friend): `useShare('friend_invite')`
 *   with the f_ link of `GET /friends` (the one the store holds; asked on this tap only when it holds none).
 * - The line under the card: the reward for friends is days of Pro (docs/40 §3, В4: no "+100 баллов лиги").
 * - Telegram's Back - to the screen before (Achievements / the streak screen), else to where the card lives. No tab bar (kit F3): the
 *   buttons stand in the bottom zone of the kit (12 / 20 px and the safe area), sticky - a long error never pushes them off a 320 x 568 screen.
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

/**
 * The mark in the stamp of the server's picture for an achievement (`AchievementKey::badge()` - the server has no illustrations of the
 * families yet, so it draws these). A key this build does not know is drawn with its art.
 */
const STORY_BADGES: Readonly<Record<string, string>> = {
  first_session: '1',
  streak_7: '7',
  streak_30: '30',
  streak_100: '100',
  words_100: '100',
  words_500: '500',
  words_1000: '1000',
  league_top1: '#1',
  first_friend: '+1',
  game_first_boss: 'BOSS',
  long_recall_25: '25',
}

/** What the card shows (`badge` null - the art of `art` in the stamp); null - the app holds nothing to show. */
const card = computed((): { art: string; badge: string | null; title: string; text: string } | null => {
  if (props.kind === 'achievement') {
    const item = achievement.value
    if (item === null) return null
    const badge = Object.prototype.hasOwnProperty.call(STORY_BADGES, item.key) ? (STORY_BADGES[item.key] ?? null) : null
    return { art: item.key, badge, title: item.title, text: item.description }
  }
  const days = streakDays.value
  if (days <= 0) return null
  const goal = today.data?.goal.target ?? 0
  return {
    art: 'streak_7',
    badge: String(days),
    title: stn('story.streak.title', days),
    text: goal > 0 ? stn('story.streak.text', goal, { brand: BRAND }) : '',
  }
})

/** The words of the title; one with a hyphen («ketma-ket») is kept whole, so a line never breaks inside it. */
const titleParts = computed((): { text: string; whole: boolean }[] =>
  (card.value?.title ?? '').split(/(\s+)/).filter((part) => part !== '').map((text) => ({ text, whole: text.includes('-') })),
)

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

/** A short window (320 x 568 and the like): a smaller card, so the caption, the line and the two buttons stay in view with it. */
const compact = typeof window !== 'undefined' && window.innerHeight < 700
/** The width of the card (kit F3: 232 on a 390 px screen); the picture of the server is 1080 wide - `--u` is one of its pixels. */
const CARD_WIDTH = compact ? 150 : 232
const UNIT = CARD_WIDTH / 1080
const cardStyle = { width: `${CARD_WIDTH}px`, '--u': `${UNIT}px` }
/** The box of the art in the stamp (370 of the server), for a key without a badge. */
const ART = Math.round(370 * UNIT)

/**
 * The size of the badge in the stamp, in pixels of the server: as large as the server starts (225 pt, about 300 px), smaller for a longer
 * mark so it stays inside the dotted ring (the server's limit: 56 % of the disc, 316 px; a bold figure is about 0.62 of its size).
 */
const badgeSize = computed((): number => {
  const length = Math.max(1, [...(card.value?.badge ?? '')].length)
  return Math.min(300, Math.floor(316 / (0.62 * length)))
})

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
      link = (await api.referrals.get(BACKGROUND)).link
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
      link = (await api.friends.get(BACKGROUND)).invite_link
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
  <div v-if="card" class="page pb-0" :data-kind="kind" data-testid="share-story-view">
    <h1 class="sr-only">{{ st('story.heading') }}</h1>

    <div class="flex flex-1 flex-col items-center pt-2">
      <!--
        The preview in the composition of the server's picture (StoryCardRenderer, 1080 x 1920): every size is the server's, in its pixels
        (`--u`). The picture itself comes on the tap.
      -->
      <figure
        class="story-card relative flex flex-col items-center overflow-hidden text-center"
        :class="compact ? 'rounded-md' : 'rounded-xl'"
        :style="cardStyle"
        role="img"
        :aria-label="`${st('story.preview_note')}: ${card.title}`"
        data-testid="story-card"
      >
        <div class="story-stamp relative flex shrink-0 items-center justify-center" data-testid="story-stamp">
          <svg class="absolute inset-0 size-full" viewBox="0 0 600 600" aria-hidden="true">
            <circle class="story-ring" cx="300" cy="300" r="300" />
            <circle class="story-paper" cx="300" cy="300" r="282" />
            <!-- 44 dots of 9 px on a circle of 256 px, as the server draws them. -->
            <circle class="story-dots" cx="300" cy="300" r="256" fill="none" stroke-width="9" stroke-linecap="round" stroke-dasharray="0 36.556" />
          </svg>
          <span
            v-if="card.badge !== null"
            class="story-badge relative font-semibold"
            :style="{ fontSize: `calc(${badgeSize} * var(--u))` }"
            data-testid="story-badge"
          >{{ card.badge }}</span>
          <AchievementArt v-else :item-key="card.art" :size="ART" class="relative" />
        </div>
        <p class="story-title line-clamp-2 font-semibold" data-testid="story-title">
          <span v-for="(part, i) in titleParts" :key="i" :class="part.whole ? 'whitespace-nowrap' : undefined">{{ part.text }}</span>
        </p>
        <p v-if="card.text" class="story-text line-clamp-3" data-testid="story-text">{{ card.text }}</p>
        <p class="story-brand-line" :class="card.text ? undefined : 'story-brand-line--close'" data-testid="story-brand">
          <span class="story-brand font-semibold">{{ BRAND }}</span>
          <span v-if="botHandle" class="story-bot"> · {{ botHandle }}</span>
        </p>
      </figure>

      <p class="t-caption mt-2 text-center text-tertiary" data-testid="story-preview-note">{{ st('story.preview_note') }}</p>

      <!-- On a short window an error takes the place of the line about Pro: the buttons stay where they are. -->
      <p
        v-if="!(compact && error)"
        class="max-w-80 text-center text-balance text-muted"
        :class="compact ? 'mt-2 text-[13px] leading-[18px]' : 't-body-m mt-4'"
        data-testid="story-hint"
      >{{ st('story.hint') }}</p>
    </div>

    <StickyActions class="-mx-5 mt-4">
      <InlineAlert v-if="error" tone="danger" class="mb-1" :class="compact ? 'py-2 text-[13px] leading-[18px]' : undefined" data-testid="story-error">
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
    </StickyActions>
  </div>
</template>

<style scoped>
/*
 * The card of the story, the composition of StoryCardRenderer in its own pixels (`--u` - one of the 1080 of the picture): ink in both
 * themes, as the picture. Light: the inverse pair; dark: the surface (the ink of the dark palette) in a hairline, so it stands off the
 * darker page. The stamp: Ø 600 centred at 700 (its top at 400), a ring of 18, the paper disc, 44 dots; the title 84 pt bold (about 112 px,
 * lines of 104), the description 44 pt (59 px, lines of 62), the footer 40 pt (53 px); the texts wrap in 900 px.
 */
.story-card {
  aspect-ratio: 9 / 16;
  padding: calc(400 * var(--u)) calc(90 * var(--u)) 0;
  --story-bg: var(--app-inverse);
  --story-text: var(--app-on-inverse);
  --story-paper: var(--app-on-inverse);
  --story-ring: var(--app-muhr);
  --story-dots: var(--app-muhr);
  --story-badge: var(--app-accent);
  --story-muted: color-mix(in srgb, var(--app-on-inverse) 68%, var(--app-inverse));
  background-color: var(--story-bg);
  color: var(--story-text);
}
:root[data-theme='dark'] .story-card {
  --story-bg: var(--app-surface);
  --story-text: var(--app-text);
  --story-paper: var(--app-inverse);
  --story-badge: color-mix(in srgb, var(--app-muhr) 70%, var(--app-on-inverse));
  --story-muted: var(--app-muted);
  box-shadow: inset 0 0 0 1px var(--app-hairline);
}
.story-stamp {
  width: calc(600 * var(--u));
  height: calc(600 * var(--u));
}
.story-ring {
  fill: var(--story-ring);
}
.story-paper {
  fill: var(--story-paper);
}
.story-dots {
  stroke: var(--story-dots);
}
.story-badge {
  color: var(--story-badge);
  line-height: 1;
  letter-spacing: -0.01em;
}
.story-title {
  margin-top: calc(34 * var(--u));
  width: 100%;
  font-size: calc(112 * var(--u));
  line-height: calc(104 * var(--u));
  overflow-wrap: anywhere;
}
.story-text {
  margin-top: calc(34 * var(--u));
  width: 100%;
  color: var(--story-muted);
  font-size: calc(59 * var(--u));
  line-height: calc(62 * var(--u));
}
.story-brand-line {
  margin-top: calc(49 * var(--u));
  font-size: calc(53 * var(--u));
  line-height: calc(68 * var(--u));
  white-space: nowrap;
}
.story-brand-line--close {
  margin-top: calc(8 * var(--u));
}
.story-brand {
  color: var(--story-ring);
}
</style>
