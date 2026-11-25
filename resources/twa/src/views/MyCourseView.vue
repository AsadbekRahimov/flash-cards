<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import CourseSkeleton from '@/components/course/CourseSkeleton.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'

/**
 * The "Course" tab (docs/14 §6.5, decision of 25.11.2026): it opens the learner's own course, not the catalog. The screen only looks
 * the course up - the list of courses says which one is active - and puts the course screen in its place (`replace`: "Back" never
 * lands here); without a course of their own the learner gets the catalog. Meanwhile the skeleton of the course screen, so the switch
 * does not jump; a failed lookup is the usual error with "Retry". A tab root: no system "Back".
 */
const router = useRouter()
const auth = useAuthStore()
const catalog = useCoursesStore()
const { listStatus: status, errorMessage } = storeToRefs(catalog)

useBackButton(null)

async function open(): Promise<void> {
  try {
    await auth.ensure()
    // Always asked anew: the course may have been switched elsewhere (the bot, Settings) since the list was read.
    await catalog.load()
  } catch (err) {
    catalog.fail(err, 'list')
  }
  if (status.value !== 'ready') return
  const active = catalog.courses.find((course) => course.is_active)
  await router.replace(active ? { name: 'course', params: { slug: active.slug } } : { name: 'courses' })
}

onMounted(() => {
  void open()
})
</script>

<template>
  <div class="page">
    <ErrorState v-if="status === 'error'" :message="errorMessage" @retry="open" />
    <CourseSkeleton v-else />
  </div>
</template>
