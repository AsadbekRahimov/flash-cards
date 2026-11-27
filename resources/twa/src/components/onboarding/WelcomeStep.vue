<script setup lang="ts">
import MascotArt from '@/components/ui/MascotArt.vue'
import TopicArt from '@/components/ui/TopicArt.vue'
import OnboardingActions from './OnboardingActions.vue'
import { BRAND } from '@/brand'
import { useI18n } from '@/i18n'

/**
 * O1 "Welcome" (kit 02-onboarding/O1): the mascot `hello` on a paper circle inside a dashed ring in the muhr colour, six pictures of
 * topics around it (tea, a suitcase, a bus, a cap with books, plov, a dutar), the brand, one line, "Start". No "Back" here: Telegram's
 * "Close" is the way out.
 */
const emit = defineEmits<{ next: [] }>()
const { t } = useI18n()

/** The pictures around the ring: the course and module of the picture, and its centre in % of the box (as in the kit). */
const AROUND: readonly { course: string; stage: number; x: number; y: number }[] = [
  { course: 'english-a1-a2', stage: 3, x: 11, y: 21 },
  { course: 'english-a1-a2', stage: 11, x: 89, y: 21 },
  { course: 'english-a1-a2', stage: 5, x: 9, y: 66 },
  { course: 'english-a1-a2', stage: 15, x: 91, y: 66 },
  { course: 'english-b1', stage: 4, x: 24, y: 89 },
  { course: 'english-a1-a2', stage: 20, x: 76, y: 89 },
]
</script>

<template>
  <div class="flex flex-1 flex-col">
    <div class="welcome-art relative mx-auto mt-6 w-full max-w-[360px]" aria-hidden="true" data-testid="welcome-art">
      <span class="welcome-ring absolute rounded-full" />
      <span class="welcome-paper absolute flex items-end justify-center overflow-hidden rounded-full bg-surface">
        <MascotArt pose="hello" :height="176" loading="eager" class="welcome-mascot" />
      </span>
      <TopicArt
        v-for="item in AROUND"
        :key="`${item.course}-${item.stage}`"
        :course="item.course"
        :stage="item.stage"
        :size="56"
        class="welcome-topic absolute"
        :style="{ left: `${item.x}%`, top: `${item.y}%` }"
      />
    </div>
    <div class="mt-8 flex flex-col items-center gap-3 text-center">
      <h1 class="t-display-l" data-testid="onboarding-title">{{ BRAND }}</h1>
      <p class="t-body-l text-balance text-muted" data-testid="onboarding-lead">{{ t('onboarding.welcome.lead') }}</p>
    </div>
    <OnboardingActions :label="t('onboarding.welcome.start')" @primary="emit('next')">
      <template #note>
        <p class="t-caption pt-1 text-center text-muted" data-testid="welcome-note">{{ t('onboarding.welcome.note') }}</p>
      </template>
    </OnboardingActions>
  </div>
</template>

<style scoped>
/* The box of the kit's picture: 360 x 295 at 1x; the ring (250) and the paper circle (190) are centred at 50% / 46%. */
.welcome-art {
  aspect-ratio: 360 / 295;
}
.welcome-ring {
  left: 50%;
  top: 46%;
  width: 69.5%;
  aspect-ratio: 1;
  transform: translate(-50%, -50%);
  border: 1.5px dashed var(--app-muhr);
}
.welcome-paper {
  left: 50%;
  top: 46%;
  width: 52.8%;
  aspect-ratio: 1;
  transform: translate(-50%, -50%);
}
.welcome-mascot {
  height: 92%;
  width: auto;
  margin-bottom: 4%;
}
.welcome-topic {
  width: 15.5%;
  height: auto;
  transform: translate(-50%, -50%);
}
</style>
