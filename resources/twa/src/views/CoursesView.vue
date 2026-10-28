<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { catalogTabs, courseChips, progressPercent, progressText } from '@/composables/courseLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'
import { useI18n } from '@/i18n'

/**
 * "Courses" tab root (docs/14 §6.5): the catalog of courses with the learner's progress. A card opens the course;
 * its button makes the course the main one (the source of the new words of the daily session). The twin screen
 * of the tab is "My decks" (the switch under the heading). A tab root: no system "Back" button.
 */
const auth = useAuthStore()
const catalog = useCoursesStore()
const { t } = useI18n()

const { courses, listStatus: status, selecting, errorMessage } = storeToRefs(catalog)

const tabs = computed(catalogTabs)

useBackButton(null)

async function load(): Promise<void> {
  try {
    await auth.ensure()
    await catalog.load()
  } catch (err) {
    catalog.fail(err, 'list')
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page">
    <PageHeader :title="t('catalog.title')" large />
    <SegmentedNav :items="tabs" />
    <p class="mt-2 px-4 text-[13px] leading-[18px] text-tg-hint" data-testid="hint">{{ t('catalog.hint') }}</p>

    <LoadingState v-if="status === 'idle' || status === 'loading'" class="mt-4" />

    <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="load" />

    <EmptyState v-else-if="courses.length === 0" icon="academic-cap" :text="t('catalog.empty')" />

    <div v-else class="mt-4 space-y-3">
      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" data-testid="select-error" />

      <article v-for="course in courses" :key="course.slug" class="card" :data-testid="`course-${course.slug}`">
        <RouterLink
          :to="{ name: 'course', params: { slug: course.slug } }"
          class="flex items-start gap-2 active:opacity-60"
          data-testid="course-link"
        >
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ course.title }}</h2>
            <div class="mt-2 flex flex-wrap gap-1.5" data-testid="meta">
              <span v-for="chip in courseChips(course)" :key="chip.key" class="chip" :class="`chip-${chip.tone}`">{{ chip.label }}</span>
            </div>
            <p v-if="course.description" class="mt-2 line-clamp-2 text-[15px] leading-5 text-tg-subtitle">{{ course.description }}</p>
          </div>
          <AppIcon name="chevron-right" :size="16" class="mt-[3px] text-tg-hint" />
        </RouterLink>

        <div class="mt-3">
          <div
            class="progress"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="progressPercent(course.progress)"
            :aria-label="progressText(course.progress)"
            data-testid="progress-bar"
          >
            <div class="progress-fill" :style="{ width: `${progressPercent(course.progress)}%` }" data-testid="progress-fill" />
          </div>
          <p class="mt-1.5 text-[13px] leading-[18px] text-tg-hint" data-testid="progress-text">{{ progressText(course.progress) }}</p>
        </div>

        <div class="mt-3">
          <span v-if="course.is_active" class="chip chip-success" data-testid="active-badge">
            <AppIcon name="check" solid :size="14" />
            {{ t('catalog.active') }}
          </span>
          <button
            v-else
            type="button"
            class="btn btn-tinted btn-block"
            :disabled="selecting !== null"
            data-testid="choose-btn"
            @click="catalog.select(course.slug)"
          >
            {{ t('catalog.choose') }}
          </button>
        </div>
      </article>
    </div>
  </div>
</template>
