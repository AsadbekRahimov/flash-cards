<script setup lang="ts">
/**
 * The head of a step of the onboarding (kit Muhrla 02-onboarding): the picture of the step (slot, centred), the question in Display and
 * the line under it in the secondary colour, all on the centre axis.
 *
 * @example <StepIntro :title="t('onboarding.age.title')" :lead="t('onboarding.age.lead')"><MascotArt pose="thinking" :height="88" /></StepIntro>
 */
defineProps<{ title: string; lead?: string }>()
</script>

<template>
  <header class="flex flex-col items-center text-center">
    <div v-if="$slots.default" class="mb-3 flex justify-center">
      <slot />
    </div>
    <h1 class="t-display-m text-balance" data-testid="onboarding-title">{{ title }}</h1>
    <p v-if="lead" class="t-body-m mt-1.5 text-balance text-muted" data-testid="onboarding-lead">{{ lead }}</p>
  </header>
</template>
