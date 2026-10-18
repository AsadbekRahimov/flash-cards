<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import ProgressBar from '@/components/ProgressBar.vue'
import { courseMeta } from '@/composables/courseLabels'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

const props = defineProps<{ slug: string }>()

const router = useRouter()
const auth = useAuthStore()
const catalog = useCoursesStore()
const { showBackButton } = useTelegram()
const { t } = useI18n()

const { detail, status, selecting, errorMessage } = storeToRefs(catalog)

let hideBack: (() => void) | null = null

async function load(): Promise<void> {
  try {
    await auth.ensure()
    await catalog.loadDetail(props.slug)
  } catch (err) {
    catalog.fail(err)
  }
}

onMounted(() => {
  hideBack = showBackButton(() => void router.push({ name: 'courses' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <RouterLink :to="{ name: 'courses' }" class="text-sm text-tg-link mb-3" data-testid="back-link">
      ← {{ t('course.back') }}
    </RouterLink>

    <div v-if="status === 'idle' || status === 'loading'" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="status === 'error' || detail === null" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <div v-else class="space-y-5">
      <header class="space-y-2">
        <h1 class="text-xl font-bold" data-testid="title">{{ detail.title }}</h1>
        <p v-if="detail.description" class="text-sm text-tg-hint">{{ detail.description }}</p>
        <p class="text-xs text-tg-hint" data-testid="meta">{{ courseMeta(detail) }}</p>
        <ProgressBar :done="detail.progress.done" :total="detail.progress.total" />
        <p class="text-xs text-tg-hint" data-testid="progress-text">
          {{ t('catalog.progress', { done: detail.progress.done, total: detail.progress.total }) }}
        </p>
        <p v-if="errorMessage" class="text-sm text-red-600" data-testid="select-error">{{ errorMessage }}</p>
        <button
          v-if="detail.is_active"
          type="button"
          class="btn btn-secondary w-full"
          disabled
          data-testid="active-badge"
        >
          ✓ {{ t('catalog.active') }}
        </button>
        <button
          v-else
          type="button"
          class="btn btn-primary w-full"
          :disabled="selecting !== null"
          data-testid="choose-btn"
          @click="catalog.select(detail.slug)"
        >
          {{ t('catalog.choose') }}
        </button>
      </header>

      <p v-if="detail.stages.length === 0" class="text-center text-tg-hint" data-testid="no-lessons">
        {{ t('course.no_lessons') }}
      </p>

      <section v-for="stage in detail.stages" :key="stage.number" class="space-y-2" :data-testid="`stage-${stage.number}`">
        <div class="flex items-baseline justify-between gap-2">
          <h2 class="font-semibold">{{ stage.title }}</h2>
          <span class="text-xs text-tg-hint whitespace-nowrap">{{ t('course.module', { number: stage.number }) }}</span>
        </div>
        <ProgressBar :done="stage.progress.done" :total="stage.progress.total" />

        <ul class="space-y-2">
          <li
            v-for="lesson in stage.lessons"
            :key="lesson.id"
            class="card !p-3 space-y-2"
            :data-testid="`lesson-${lesson.id}`"
          >
            <div class="flex items-baseline justify-between gap-2">
              <span class="font-medium">
                {{ t('course.lesson', { number: lesson.number }) }}<template v-if="lesson.title"> · {{ lesson.title }}</template>
              </span>
              <span class="text-xs text-tg-hint whitespace-nowrap" data-testid="lesson-progress">
                {{ lesson.progress.done }} / {{ lesson.progress.total }}
              </span>
            </div>
            <ProgressBar :done="lesson.progress.done" :total="lesson.progress.total" />
          </li>
        </ul>
      </section>
    </div>
  </section>
</template>
