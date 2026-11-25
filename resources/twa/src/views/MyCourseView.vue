<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import CourseSkeleton from '@/components/course/CourseSkeleton.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'
import { useTodayStore } from '@/stores/today'

/**
 * The "Course" tab (docs/14 §6.5, decision of 25.11.2026): it opens the learner's own course, not the catalog. The screen only looks
 * the course up - the list of courses says which one is active - and puts the course screen in its place (`replace`: "Back" never
 * lands here); without a course of their own the learner gets the catalog. Meanwhile the skeleton of the course screen, so the switch
 * does not jump; a failed lookup is the usual error with "Retry". A tab root: no system "Back".
 *
 * A young `/today` that names the active course answers at once, without `GET /courses` (`today.isFresh`; switching the course here
 * makes it stale). The lookup is asynchronous: a learner who left the tab before it answered is not pulled back (`left`, and the
 * route must still be this tab).
 */
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const catalog = useCoursesStore()
const today = useTodayStore()
const { listStatus: status, errorMessage } = storeToRefs(catalog)

useBackButton(null)

let left = false
onBeforeUnmount(() => {
  left = true
})

async function goTo(slug: string | null): Promise<void> {
  if (left || route.name !== 'my-course') return
  await router.replace(slug !== null ? { name: 'course', params: { slug } } : { name: 'courses' })
}

async function open(): Promise<void> {
  const known = today.isFresh ? (today.data?.active_course?.slug ?? null) : null
  if (known !== null) {
    await goTo(known)
    return
  }
  try {
    await auth.ensure()
    // Asked anew otherwise: the course may have been switched elsewhere (the bot, Settings) since the list was read.
    await catalog.load()
  } catch (err) {
    catalog.fail(err, 'list')
  }
  if (status.value !== 'ready') return
  const active = catalog.courses.find((course) => course.is_active)
  await goTo(active ? active.slug : null)
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
