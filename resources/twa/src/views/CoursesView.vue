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

const router = useRouter()
const auth = useAuthStore()
const catalog = useCoursesStore()
const { showBackButton } = useTelegram()
const { t } = useI18n()

const { courses, status, selecting, errorMessage } = storeToRefs(catalog)

let hideBack: (() => void) | null = null

async function load(): Promise<void> {
  try {
    await auth.ensure()
    await catalog.load()
  } catch (err) {
    catalog.fail(err)
  }
}

onMounted(() => {
  hideBack = showBackButton(() => void router.push({ name: 'landing' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <h1 class="text-xl font-bold mb-4">{{ t('catalog.title') }}</h1>

    <div v-if="status === 'idle' || status === 'loading'" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="status === 'error'" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <p v-else-if="courses.length === 0" class="flex-1 flex items-center justify-center text-center text-tg-hint" data-testid="empty">
      {{ t('catalog.empty') }}
    </p>

    <div v-else class="space-y-3">
      <p v-if="errorMessage" class="text-sm text-red-600" data-testid="select-error">{{ errorMessage }}</p>

      <article
        v-for="course in courses"
        :key="course.slug"
        class="card space-y-3"
        :data-testid="`course-${course.slug}`"
      >
        <RouterLink
          :to="{ name: 'course', params: { slug: course.slug } }"
          class="block space-y-1"
          data-testid="course-link"
        >
          <h2 class="text-lg font-semibold">{{ course.title }}</h2>
          <p v-if="course.description" class="text-sm text-tg-hint">{{ course.description }}</p>
          <p class="text-xs text-tg-hint" data-testid="meta">{{ courseMeta(course) }}</p>
        </RouterLink>

        <div class="space-y-1">
          <ProgressBar :done="course.progress.done" :total="course.progress.total" />
          <p class="text-xs text-tg-hint" data-testid="progress-text">
            {{ t('catalog.progress', { done: course.progress.done, total: course.progress.total }) }}
          </p>
        </div>

        <button
          v-if="course.is_active"
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
          @click="catalog.select(course.slug)"
        >
          {{ t('catalog.choose') }}
        </button>
      </article>
    </div>
  </section>
</template>
