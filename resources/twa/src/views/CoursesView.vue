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
import ProgressLine from '@/components/ui/ProgressLine.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { catalogTabs, courseChips, progressPercent, progressText } from '@/composables/courseLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useCourseSelect } from '@/composables/useCourseSelect'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'
import { useI18n } from '@/i18n'

/**
 * "Courses" tab root (docs/14 §6.5): the catalog of courses with the learner's progress. A card opens the course;
 * its button starts the course (or switches to it: the source of the new words of the daily session) and leads to Home. The twin screen
 * of the tab is "My decks" (the switch under the heading). A tab root: no system "Back" button.
 */
const auth = useAuthStore()
const catalog = useCoursesStore()
const { t } = useI18n()
const { chooseLabel, choose } = useCourseSelect()

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
    <SegmentedNav :items="tabs" :ariaLabel="t('catalog.title')" />
    <p class="mt-3 text-[13px] leading-[18px] text-muted" data-testid="hint">{{ t('catalog.hint') }}</p>

    <LoadingState v-if="status === 'idle' || status === 'loading'" class="mt-4" />

    <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="load" />

    <EmptyState v-else-if="courses.length === 0" icon="academic-cap" :text="t('catalog.empty')" />

    <div v-else class="mt-4 space-y-3">
      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" data-testid="select-error" />

      <!-- The course the learner is on stands out as the selected card (accent-soft, primary frame): "you are here". -->
      <article
        v-for="course in courses"
        :key="course.slug"
        class="card"
        :class="{ 'bg-accent-soft shadow-[inset_0_0_0_1.5px_var(--app-primary)]': course.is_active }"
        :data-testid="`course-${course.slug}`"
      >
        <span v-if="course.is_active" class="chip chip-success mb-2" data-testid="active-badge">
          {{ t('catalog.active') }}
        </span>
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
            <p v-if="course.description" class="mt-2 line-clamp-2 text-[15px] leading-5 text-muted">{{ course.description }}</p>
          </div>
          <AppIcon name="chevron-right" :size="16" class="mt-[3px] text-muted" />
        </RouterLink>

        <div class="mt-3.5 flex flex-col-reverse gap-1.5">
          <ProgressLine
            :value="progressPercent(course.progress)"
            tone="success"
            :label="progressText(course.progress)"
            class="w-full"
            data-testid="progress-bar"
          />
          <span class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="progress-text">{{ progressText(course.progress) }}</span>
        </div>

        <div class="mt-3">
          <RouterLink v-if="course.is_active" :to="{ name: 'course', params: { slug: course.slug } }" class="btn btn-primary btn-block">
            {{ t('catalog.open_lessons') }}
            <AppIcon name="arrow-right" :size="17" />
          </RouterLink>
          <button
            v-else
            type="button"
            class="btn btn-tinted btn-block"
            :disabled="selecting !== null"
            data-testid="choose-btn"
            @click="choose(course.slug)"
          >
            {{ chooseLabel }}
          </button>
        </div>
      </article>
    </div>
  </div>
</template>
