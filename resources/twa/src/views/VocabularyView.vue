<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import IconButton from '@/components/ui/IconButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import MemoryDots from '@/components/ui/MemoryDots.vue'
import { VOCABULARY_FILTERS, type VocabularyFilter, type VocabularyItem } from '@/api/vocabularyTypes'
import { useBackButton } from '@/composables/useBackButton'
import { useOfflineContext } from '@/composables/useOnline'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'
import { errorMessage as describeError } from '@/i18n/errors'
import { useVocabularyI18n } from '@/i18n/vocabulary'
import { useAuthStore } from '@/stores/auth'
import { SEARCH_MAX, useVocabularyStore } from '@/stores/vocabulary'

/**
 * "My dictionary" (docs/40 §2.3, kit Muhrla F2-moy-slovar): the words the learner has graded, in the order of the alphabet. The heading
 * with the number of words, the search (the word or its translation), the chips All / Weak / Strong with their numbers (the selected one
 * in ink), the rows - the word and its translation, the five dots of its memory strength, the speaker. The next page comes when the end
 * of the list scrolls into view (or "Show more"). A screen of the "Course" tab, opened from the course screen ("My dictionary"; `?from=`
 * is the course, the system "Back" returns there).
 *
 * The states: the skeleton (seven rows), the empty dictionary (the mascot reading, "Pass the first lesson"), nothing found, an error with
 * "Retry". A lost connection is the common screen "Нет соединения" of the app (F5): it is told what to retry here, so the list read so far
 * stays.
 *
 * Requests: one when the screen opens, one per page, one per change of the search (after a pause in the typing) or of the filter - all in
 * the store (`stores/vocabulary.ts`).
 */
const route = useRoute()
const auth = useAuthStore()
const store = useVocabularyStore()
const { items, total, counts, filter, query, status, refreshing, loadingMore, errorMessage, moreError, nextCursor, isEmpty } = storeToRefs(store)
const { t: tApp } = useI18n()
const { t, tn } = useVocabularyI18n()
const { speak } = useTts()
const { selectionHaptic } = useTelegram()

const fromCourse = typeof route.query.from === 'string' && route.query.from !== '' ? route.query.from : null
useBackButton(fromCourse !== null ? { name: 'course', params: { slug: fromCourse } } : { name: 'my-course' })

/** The text of the field as typed; the list follows it after a pause (`store.search`). */
const text = ref('')

/** The search and the chips are there as soon as the dictionary has a word (they stay while another search is read, or failed). */
const hasWords = computed(() => counts.value !== null && counts.value.all > 0)

const filterLabels: Record<VocabularyFilter, 'vocabulary.filter.all' | 'vocabulary.filter.weak' | 'vocabulary.filter.strong'> = {
  all: 'vocabulary.filter.all',
  weak: 'vocabulary.filter.weak',
  strong: 'vocabulary.filter.strong',
}

function chipLabel(value: VocabularyFilter): string {
  const label = t(filterLabels[value])
  const count = counts.value?.[value]
  return count === undefined ? label : t('vocabulary.filter_count', { label, count })
}

/** The selected chip is ink; "Weak" is the soft accent of the kit when not selected; the rest are the sunken paper. */
function chipTone(value: VocabularyFilter): string {
  if (value === filter.value) return 'chip-ink'
  return value === 'weak' ? 'chip-accent' : 'chip-neutral'
}

function onInput(): void {
  store.search(text.value)
}

function pick(value: VocabularyFilter): void {
  if (value === filter.value) return
  selectionHaptic()
  store.setFilter(value, text.value)
}

function listen(item: VocabularyItem): void {
  selectionHaptic()
  void speak(item.word, 'en-US', item.audio_url)
}

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    store.$patch({ status: 'error', errorMessage: describeError(err, 'catalog') })
    return
  }
  await store.open()
}

function retry(): void {
  if (status.value === 'idle') void load()
  else void store.retry()
}

// The common screen "Нет соединения" (F5) repeats what the network lost here - the first page or the next one - instead of opening the
// screen anew: the pages read so far stay.
useOfflineContext({
  retry,
  chip: () => tApp('offline.chip_server'),
  pendingAnswer: () => false,
})

/**
 * The next page when the end of the list comes into view (200 px early). Watched again after every page: the end may still be in view
 * (a short page), and the observer says nothing about an element that did not move. Without `IntersectionObserver` - "Show more".
 */
const sentinel = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null

function observe(): void {
  observer?.disconnect()
  if (sentinel.value === null || typeof IntersectionObserver === 'undefined') return
  observer ??= new IntersectionObserver(
    (entries) => {
      if (entries.some((entry) => entry.isIntersecting)) void store.loadMore()
    },
    { rootMargin: '200px 0px' },
  )
  observer.observe(sentinel.value)
}

watch([sentinel, () => items.value.length], observe, { flush: 'post' })

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  observer?.disconnect()
  observer = null
  store.close()
})
</script>

<template>
  <div class="page" data-testid="vocabulary-view">
    <!-- The heading of the kit: the title in Unbounded, the number of words on the right. -->
    <header class="flex items-baseline justify-between gap-3 pt-2 pb-4">
      <h1 class="t-display-l min-w-0 [overflow-wrap:anywhere]">{{ t('vocabulary.title') }}</h1>
      <span v-if="hasWords && total !== null" class="t-body-m shrink-0 text-muted" data-testid="vocabulary-total">{{ tn('vocabulary.total', total) }}</span>
    </header>

    <!-- The first read: the shapes of the screen - the field, the chips, seven rows. -->
    <div v-if="status === 'idle' || status === 'loading'" role="status" aria-busy="true" data-testid="spinner">
      <span class="sr-only">{{ tApp('common.loading') }}</span>
      <div aria-hidden="true">
        <div class="skeleton h-14 w-full rounded-control" />
        <div class="mt-4 flex gap-2">
          <div class="skeleton h-8 w-20 rounded-full" />
          <div class="skeleton h-8 w-24 rounded-full" />
          <div class="skeleton h-8 w-24 rounded-full" />
        </div>
        <div class="list mt-4">
          <div v-for="row in 7" :key="row" class="list-row px-4" data-testid="skeleton-row">
            <span class="flex-1 space-y-2">
              <span class="skeleton block h-4" :class="row % 2 === 1 ? 'w-1/3' : 'w-1/4'" />
              <span class="skeleton block h-3.5 w-1/4" />
            </span>
            <span class="skeleton h-2 w-[52px] rounded-full" />
            <span class="skeleton size-11 rounded-full" />
          </div>
        </div>
      </div>
    </div>

    <ErrorState v-else-if="status === 'error' && !hasWords" :message="errorMessage" @retry="retry" />

    <EmptyState v-else-if="isEmpty" mascot="reading" :text="t('vocabulary.empty')" data-testid="vocabulary-empty" />

    <template v-else>
      <!-- The search: the word or its translation; 16 px, so iOS does not zoom into the field. -->
      <label class="relative block">
        <span class="sr-only">{{ t('vocabulary.search') }}</span>
        <AppIcon name="search" :size="20" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted" />
        <input
          v-model="text"
          type="search"
          class="field pl-12"
          :placeholder="t('vocabulary.search')"
          :maxlength="SEARCH_MAX"
          autocomplete="off"
          autocapitalize="off"
          autocorrect="off"
          spellcheck="false"
          enterkeyhint="search"
          data-testid="vocabulary-search"
          @input="onInput"
        />
      </label>

      <div class="mt-4 flex flex-wrap gap-2" role="group" :aria-label="t('vocabulary.filters')" data-testid="vocabulary-filters">
        <button
          v-for="value in VOCABULARY_FILTERS"
          :key="value"
          type="button"
          class="chip tap-target h-8 px-3.5 text-sm"
          :class="chipTone(value)"
          :aria-pressed="value === filter ? 'true' : 'false'"
          :data-testid="`filter-${value}`"
          @click="pick(value)"
        >
          {{ chipLabel(value) }}
        </button>
      </div>

      <ErrorState v-if="status === 'error'" class="mt-4" :message="errorMessage" @retry="retry" />

      <EmptyState
        v-else-if="items.length === 0"
        :mascot="false"
        :icon="query !== '' ? 'search' : undefined"
        :title="query !== '' ? t('vocabulary.not_found') : undefined"
        :text="query !== '' ? t('vocabulary.not_found_text') : t('vocabulary.filter_empty')"
        :aria-busy="refreshing ? 'true' : undefined"
        data-testid="vocabulary-not-found"
      />

      <template v-else>
        <ul class="list mt-4 transition-opacity" :class="{ 'opacity-60': refreshing }" :aria-busy="refreshing ? 'true' : undefined" data-testid="vocabulary-list">
          <li v-for="item in items" :key="item.word_id" class="list-row px-4" data-testid="vocabulary-row">
            <span class="min-w-0 flex-1">
              <span class="block text-[17px] leading-6 font-semibold [overflow-wrap:anywhere]" lang="en" data-testid="vocabulary-word">{{ item.word }}</span>
              <span class="mt-0.5 block text-[15px] leading-5 text-muted [overflow-wrap:anywhere]" data-testid="vocabulary-translation">{{ item.translation }}</span>
            </span>
            <MemoryDots :strength="item.strength" size="sm" />
            <IconButton icon="speaker-wave" :label="t('vocabulary.listen', { word: item.word })" data-testid="vocabulary-audio" @click="listen(item)" />
          </li>
        </ul>

        <InlineAlert v-if="moreError" tone="danger" :message="moreError" class="mt-4" data-testid="more-error" />
        <div v-if="nextCursor !== null" ref="sentinel" class="mt-4 flex justify-center" data-testid="vocabulary-more">
          <button
            type="button"
            class="btn btn-ghost"
            :disabled="loadingMore || refreshing"
            :aria-busy="loadingMore ? 'true' : undefined"
            data-testid="more-btn"
            @click="moreError ? store.retry() : store.loadMore()"
          >
            {{ moreError ? tApp('common.retry') : t('vocabulary.more') }}
          </button>
        </div>
      </template>
    </template>
  </div>
</template>
