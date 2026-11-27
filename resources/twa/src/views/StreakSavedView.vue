<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PaywallSheet from '@/components/PaywallSheet.vue'
import StickyActions from '@/components/StickyActions.vue'
import WeekCalendar from '@/components/today/WeekCalendar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import StampMark from '@/components/ui/StampMark.vue'
import { streakWeek } from '@/composables/useStreakMoments'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import { useStreakI18n } from '@/i18n/streak'

/**
 * F4 of the kit Muhrla (03-new-features/F4-seriya-spasena.png, docs/40 §2.5), opened by itself from "Today" once per event
 * (`useStreakMoments`). Everything it shows is in `/today` the store already holds - the screen asks the server for nothing; opened
 * without it (a reload on this address) it goes to "Today".
 *
 * - `saved` - a freeze held the missed day(s): the mascot in the scarf, «Серия спасена!», the week (`WeekCalendar`), the stamp «N ДНЕЙ»
 *   (the one stamp of the screen, pressed in with the haptic), «Осталось заморозок: N», Primary «Продолжить серию».
 * - `lost` - there was no freeze for it (`streak.lost`): a calm screen without a celebration - the thinking mascot, «Серия прервалась»,
 *   «Было N дней»; Free gets a quiet card «Заморозка сохранила бы серию — в Pro» that opens the paywall `streak_freeze` only on a tap;
 *   Pro reads why its freezes were not enough. Primary «Начать заново».
 * Both lead back to "Today" (the button and Telegram's Back). A saved streak offers «Поделиться серией» under the button: F3, the story of
 * the streak (`/share/story?kind=streak`, docs/40 §2.4).
 */
const props = defineProps<{ kind: 'saved' | 'lost' }>()

const router = useRouter()
const today = useTodayStore()
const { tn: appTn } = useI18n()
const { t, tn } = useStreakI18n()

const TODAY = { name: 'today' } as const

function toToday(): void {
  if (router.options.history.state.back === router.resolve(TODAY).fullPath) router.back()
  else void router.replace(TODAY)
}

useBackButton(toToday)

const streak = computed(() => today.data?.streak ?? null)
const week = computed(() => streakWeek(streak.value))
const lost = computed(() => (props.kind === 'lost' ? (streak.value?.lost ?? null) : null))
const isFree = computed(() => today.data?.entitlement.plan === 'free')

/** What there is to show: the week for a saved streak (an older server has none), the loss for a lost one. */
const ready = computed(() => (props.kind === 'saved' ? week.value !== null : lost.value !== null && lost.value.days > 0))

onMounted(() => {
  if (!ready.value) void router.replace(TODAY)
})

const current = computed(() => streak.value?.current ?? 0)

/** The days the freezes held in a row up to yesterday (this week; a Monday has only `frozen_yesterday`): "2 дня" for two. */
const missed = computed((): number => {
  const days = week.value
  if (days === null) return 1
  const index = days.findIndex((day) => day.is_today)
  let count = 0
  for (let i = index - 1; i >= 0 && days[i]?.held_by === 'freeze'; i--) count++
  return Math.max(count, 1)
})

const lead = computed(() => (missed.value > 1 ? tn('streak.saved.lead_missed', missed.value) : tn('streak.saved.lead', current.value)))

/** F3: the story of the streak. */
function toStory(): void {
  void router.push({ name: 'share-story', query: { kind: 'streak' } })
}

const paywallOpen = ref(false)
const { reviewFromPaywall: onPaywallReview } = useReviewFromPaywall(() => {
  paywallOpen.value = false
})
</script>

<template>
  <div v-if="ready" class="page" :data-kind="kind" data-testid="streak-view">
    <div class="streak-body flex flex-1 flex-col items-center pt-4 text-center">
      <template v-if="kind === 'saved'">
        <MascotArt pose="freeze" :height="150" loading="eager" class="streak-mascot" />
        <h1 class="streak-title t-display-l mt-5" data-testid="streak-title">{{ t('streak.saved.title') }}</h1>
        <p class="streak-lead t-body-l mt-3 max-w-80 font-normal text-muted" data-testid="streak-lead">{{ lead }}</p>
        <WeekCalendar v-if="week" :week="week" class="streak-gap mt-6 w-full" />
        <StampMark :value="current" :unit="appTn('today.streak.unit', current)" haptic class="streak-gap mt-6" />
        <p class="t-body-m mt-3 text-muted" data-testid="streak-freezes">{{ t('streak.saved.freezes_left', { count: streak?.freezes ?? 0 }) }}</p>
      </template>

      <template v-else-if="lost">
        <MascotArt pose="thinking" :height="150" loading="eager" class="streak-mascot" />
        <!-- A calm screen: the smaller heading, no stamp, no haptic. -->
        <h1 class="t-display-m mt-5" data-testid="streak-title">{{ t('streak.lost.title') }}</h1>
        <p class="t-body-l mt-3 max-w-80 font-normal text-body" data-testid="streak-lead">{{ tn('streak.lost.lead', lost.days) }}</p>
        <p class="t-body-m mt-2 max-w-80 text-muted" data-testid="streak-text">{{ t('streak.lost.text') }}</p>

        <!-- Free: an offer, not a wall - the paywall opens only on a tap. -->
        <div v-if="isFree" class="card mt-6 flex w-full items-start gap-3 text-left" data-testid="streak-pro-offer">
          <span class="icon-tile"><AppIcon name="snowflake" :size="20" /></span>
          <div class="min-w-0 flex-1">
            <p class="t-body-m">{{ t('streak.lost.pro_offer') }}</p>
            <!-- Under the text, not beside it: on a 320 px screen the two would squeeze each other into a column of words. -->
            <button type="button" class="btn btn-plain btn-sm -ml-3 mt-1" data-testid="streak-pro-more" @click="paywallOpen = true">
              {{ t('streak.lost.pro_more') }}
            </button>
          </div>
        </div>
        <p v-else class="t-body-m mt-6 max-w-80 text-muted" data-testid="streak-pro-note">{{ t('streak.lost.pro_note') }}</p>
      </template>
    </div>

    <StickyActions class="-mx-5">
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="streak-continue" @click="toToday">
        <span>{{ kind === 'saved' ? t('streak.saved.continue') : t('streak.lost.restart') }}</span>
        <AppIcon v-if="kind === 'saved'" name="arrow-right" :size="20" />
      </button>
      <button
        v-if="kind === 'saved' && current > 0"
        type="button"
        class="btn btn-plain btn-block"
        data-testid="streak-share"
        @click="toStory"
      >
        {{ t('streak.saved.share') }}
      </button>
    </StickyActions>

    <PaywallSheet v-if="paywallOpen" reason="streak_freeze" @close="paywallOpen = false" @review="onPaywallReview" />
  </div>
</template>

<style scoped>
/*
 * A short window (320 x 568 and the like): the stamp, the moment of the screen, has to be in view above the button - a smaller mascot and
 * heading and tighter gaps; the screen scrolls only when even that does not fit.
 */
@media (max-height: 700px) {
  .streak-mascot {
    height: 92px;
    width: auto;
  }
  .streak-title {
    font-size: 24px;
    line-height: 30px;
  }
  .streak-gap {
    margin-top: 1rem;
  }
  .streak-body {
    padding-top: 0;
  }
  .streak-lead {
    margin-top: 0.5rem;
    font-size: 15px;
    line-height: 22px;
  }
}
</style>
