<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useDecksStore } from '@/stores/decks'
import { useI18n } from '@/i18n'
import type { DeckImportFormat } from '@/api/types'

/**
 * One deck (docs/04 A.10, docs/14 §6.5): rename, choosing the deck as the main one, adding words as a list or CSV
 * (the server cleans the text and reports the lines it dropped), the word list with "remove" and "deleting" the deck
 * (the server archives it, the progress stays). "Back" is the system button (-> my decks).
 */
const MAX_BYTES = 256 * 1024

const props = defineProps<{ slug: string }>()

const router = useRouter()
const auth = useAuthStore()
const store = useDecksStore()
const { t } = useI18n()

const { detail, detailStatus: status, busy, errorMessage, limitReached, lastImport } = storeToRefs(store)

const title = ref('')
const format = ref<DeckImportFormat>('list')
const text = ref('')
const sizeError = ref('')
const confirmDelete = ref(false)

useBackButton({ name: 'decks' })

const formatOptions = computed(() => [
  { value: 'list', label: t('decks.edit.format.list'), testid: 'format-list' },
  { value: 'csv', label: t('decks.edit.format.csv'), testid: 'format-csv' },
])

const hint = computed(() => (format.value === 'csv' ? t('decks.edit.hint.csv') : t('decks.edit.hint.list')))

/** Something went into the deck (a new word or a restored one); a report of only duplicates gets a neutral icon. */
const importedAny = computed(() => (lastImport.value?.added ?? 0) + (lastImport.value?.reactivated ?? 0) > 0)

function setFormat(value: string | number): void {
  format.value = value === 'csv' ? 'csv' : 'list'
}

async function load(): Promise<void> {
  try {
    await auth.ensure()
    await store.loadDetail(props.slug)
  } catch (err) {
    store.fail(err, 'detail')
  }
}

watch(detail, (value) => {
  if (value) title.value = value.title
})

async function rename(): Promise<void> {
  const value = title.value.trim()
  if (value === '' || value === detail.value?.title || busy.value) return
  await store.rename(props.slug, value)
}

async function addWords(): Promise<void> {
  sizeError.value = ''
  if (text.value.trim() === '' || busy.value) return
  if (new Blob([text.value]).size > MAX_BYTES) {
    sizeError.value = t('decks.error.too_large')
    return
  }

  if (await store.importWords(props.slug, text.value, format.value)) text.value = ''
}

async function archive(): Promise<void> {
  if (await store.archive(props.slug)) void router.push({ name: 'decks' })
  else confirmDelete.value = false
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page">
    <LoadingState v-if="status === 'idle' || status === 'loading'" />

    <ErrorState v-else-if="status === 'error' || !detail" :message="errorMessage" @retry="load">
      <RouterLink :to="{ name: 'decks' }" class="btn btn-tinted btn-block" data-testid="back-link">
        {{ t('decks.edit.back') }}
      </RouterLink>
    </ErrorState>

    <template v-else>
      <PageHeader :title="detail.title" />

      <form class="card" data-testid="rename-form" @submit.prevent="rename">
        <label class="block text-[13px] leading-[18px] font-medium text-tg-hint" for="deck-title">{{ t('decks.edit.rename') }}</label>
        <div class="mt-2 flex gap-2">
          <input
            id="deck-title"
            v-model="title"
            type="text"
            maxlength="60"
            autocomplete="off"
            class="field min-w-0 flex-1"
            data-testid="rename-input"
          />
          <button
            type="submit"
            class="btn btn-secondary shrink-0"
            :disabled="busy || title.trim() === '' || title.trim() === detail.title"
            data-testid="rename-btn"
          >
            {{ t('decks.edit.save') }}
          </button>
        </div>
      </form>

      <!-- The one primary button of the screen: make the deck the main one (a chip once it is). -->
      <span v-if="detail.is_active" class="chip chip-success mt-4 self-start" data-testid="active-badge">
        <AppIcon name="check" solid :size="14" />
        {{ t('decks.active') }}
      </span>
      <button
        v-else
        type="button"
        class="btn btn-primary btn-block mt-4"
        :disabled="busy || detail.words_count === 0"
        data-testid="choose-btn"
        @click="store.activate(slug)"
      >
        {{ t('decks.choose') }}
      </button>

      <p
        v-if="errorMessage"
        class="mt-4 flex items-start gap-2 rounded-xl bg-danger-soft px-3.5 py-2.5 text-[15px] leading-5 text-danger-ink"
        role="alert"
        data-testid="write-error"
      >
        <AppIcon name="exclamation-circle" :size="20" class="mt-px" />
        <span>{{ errorMessage }}</span>
      </p>

      <section class="card mt-4 space-y-3" data-testid="import">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('decks.edit.import') }}</h2>

        <SegmentedControl
          :model-value="format"
          :options="formatOptions"
          :ariaLabel="t('decks.edit.format.label')"
          @update:model-value="setFormat"
        />

        <p class="text-[13px] leading-[18px] text-tg-hint" data-testid="hint">{{ hint }}</p>

        <textarea
          v-model="text"
          rows="6"
          class="field font-mono"
          :placeholder="t('decks.edit.text.placeholder')"
          :aria-label="t('decks.edit.import')"
          data-testid="text-input"
        />

        <p
          v-if="sizeError"
          class="flex items-start gap-2 rounded-xl bg-danger-soft px-3.5 py-2.5 text-[15px] leading-5 text-danger-ink"
          role="alert"
          data-testid="size-error"
        >
          <AppIcon name="exclamation-circle" :size="20" class="mt-px" />
          <span>{{ sizeError }}</span>
        </p>

        <button
          type="button"
          class="btn btn-tinted btn-block"
          :disabled="busy || text.trim() === ''"
          data-testid="add-btn"
          @click="addWords"
        >
          {{ t('decks.edit.add') }}
        </button>

        <div v-if="lastImport" class="space-y-2 rounded-xl bg-fill px-3.5 py-3" data-testid="report">
          <p class="flex items-start gap-2 text-[15px] leading-5" data-testid="report-summary">
            <AppIcon
              :name="importedAny ? 'check-circle' : 'information-circle'"
              solid
              :size="20"
              class="mt-px"
              :class="importedAny ? 'text-success-ink' : 'text-tg-hint'"
            />
            <span>
              {{
                t('decks.report', {
                  added: lastImport.added,
                  reactivated: lastImport.reactivated,
                  duplicates: lastImport.duplicates,
                  rejected: lastImport.rejected.length,
                })
              }}
            </span>
          </p>
          <ul v-if="lastImport.rejected.length > 0" class="space-y-1" data-testid="report-rejected">
            <li
              v-for="item in lastImport.rejected.slice(0, 20)"
              :key="item.line"
              class="flex items-start gap-2 text-[13px] leading-[18px] text-tg-hint"
            >
              <AppIcon name="exclamation-triangle" solid :size="16" class="mt-px text-warning-ink" />
              <span>{{ t('decks.rejected', { line: item.line, reason: t(`decks.reason.${item.reason}`) }) }}</span>
            </li>
          </ul>
        </div>
      </section>

      <ListGroup v-if="detail.words.length > 0" :header="t('decks.words_title', { count: detail.words_count })" data-testid="words">
        <div v-for="word in detail.words" :key="word.id" class="list-row" :data-testid="`word-${word.id}`">
          <div class="min-w-0 flex-1">
            <p class="text-base leading-[22px] break-words">{{ word.word }}</p>
            <p class="text-[13px] leading-[18px] text-tg-hint break-words">{{ word.translation }}</p>
          </div>
          <button
            type="button"
            class="-mr-2 flex size-11 shrink-0 items-center justify-center rounded-full text-danger active:bg-danger-soft disabled:opacity-50"
            :aria-label="t('decks.edit.remove_word')"
            :disabled="busy"
            data-testid="remove-word"
            @click="store.removeWord(slug, word.id)"
          >
            <AppIcon name="trash" :size="20" />
          </button>
        </div>
      </ListGroup>
      <template v-else>
        <h2 class="section-label">{{ t('decks.words_title', { count: detail.words_count }) }}</h2>
        <p class="card text-center text-[15px] leading-5 text-tg-hint" data-testid="no-words">{{ t('decks.edit.no_words') }}</p>
      </template>

      <button
        v-if="!confirmDelete"
        type="button"
        class="btn btn-danger btn-block mt-6"
        :disabled="busy"
        data-testid="delete-btn"
        @click="confirmDelete = true"
      >
        {{ t('decks.edit.delete') }}
      </button>
      <div v-else class="card mt-6 space-y-3" data-testid="delete-confirm">
        <p class="text-[15px] leading-5">{{ t('decks.edit.delete.confirm', { title: detail.title }) }}</p>
        <div class="flex gap-2">
          <button type="button" class="btn btn-danger flex-1" :disabled="busy" data-testid="delete-yes" @click="archive">
            {{ t('decks.edit.delete.yes') }}
          </button>
          <button type="button" class="btn btn-secondary flex-1" data-testid="delete-no" @click="confirmDelete = false">
            {{ t('decks.edit.delete.no') }}
          </button>
        </div>
      </div>
    </template>

    <PaywallSheet v-if="limitReached" reason="decks" @close="store.dismissLimit()" />
  </div>
</template>
