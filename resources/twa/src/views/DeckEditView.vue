<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import { useAuthStore } from '@/stores/auth'
import { useDecksStore } from '@/stores/decks'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { DeckImportFormat } from '@/api/types'

/**
 * One deck (docs/04 A.10): rename, the word list with "remove", adding words as a list or CSV
 * (the server cleans the text and reports the lines it dropped), choosing the deck as the active
 * course and "deleting" it (the server archives it, the progress stays).
 */
const MAX_BYTES = 256 * 1024

const props = defineProps<{ slug: string }>()

const router = useRouter()
const auth = useAuthStore()
const store = useDecksStore()
const { showBackButton } = useTelegram()
const { t } = useI18n()

const { detail, detailStatus: status, busy, errorMessage, limitReached, lastImport } = storeToRefs(store)

const title = ref('')
const format = ref<DeckImportFormat>('list')
const text = ref('')
const sizeError = ref('')
const confirmDelete = ref(false)

let hideBack: (() => void) | null = null

const hint = computed(() => (format.value === 'csv' ? t('decks.edit.hint.csv') : t('decks.edit.hint.list')))

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
  hideBack = showBackButton(() => void router.push({ name: 'decks' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <div v-if="status === 'idle' || status === 'loading'" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="status === 'error' || !detail" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ errorMessage }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
        <RouterLink :to="{ name: 'decks' }" class="btn btn-secondary w-full" data-testid="back-link">
          {{ t('decks.edit.back') }}
        </RouterLink>
      </div>
    </div>

    <div v-else class="space-y-4">
      <form class="space-y-2" data-testid="rename-form" @submit.prevent="rename">
        <label class="text-sm text-tg-hint" for="deck-title">{{ t('decks.edit.rename') }}</label>
        <div class="flex gap-2">
          <input
            id="deck-title"
            v-model="title"
            type="text"
            maxlength="60"
            autocomplete="off"
            class="flex-1 rounded-xl px-3 py-3"
            data-testid="rename-input"
          />
          <button
            type="submit"
            class="btn btn-secondary"
            :disabled="busy || title.trim() === '' || title.trim() === detail.title"
            data-testid="rename-btn"
          >
            {{ t('decks.edit.save') }}
          </button>
        </div>
      </form>

      <p v-if="detail.is_active" class="text-sm font-semibold" data-testid="active-badge">✓ {{ t('catalog.active') }}</p>
      <button
        v-else
        type="button"
        class="btn btn-primary w-full"
        :disabled="busy || detail.words_count === 0"
        data-testid="choose-btn"
        @click="store.activate(slug)"
      >
        {{ t('catalog.choose') }}
      </button>

      <p v-if="errorMessage" class="text-sm text-red-600" role="alert" data-testid="write-error">{{ errorMessage }}</p>

      <div class="card space-y-3" data-testid="import">
        <h2 class="font-semibold">{{ t('decks.edit.import') }}</h2>

        <div class="flex gap-2" role="group">
          <button
            type="button"
            class="btn flex-1"
            :class="format === 'list' ? 'btn-primary' : 'btn-secondary'"
            :aria-pressed="format === 'list'"
            data-testid="format-list"
            @click="format = 'list'"
          >
            {{ t('decks.edit.format.list') }}
          </button>
          <button
            type="button"
            class="btn flex-1"
            :class="format === 'csv' ? 'btn-primary' : 'btn-secondary'"
            :aria-pressed="format === 'csv'"
            data-testid="format-csv"
            @click="format = 'csv'"
          >
            {{ t('decks.edit.format.csv') }}
          </button>
        </div>

        <p class="text-xs text-tg-hint" data-testid="hint">{{ hint }}</p>

        <textarea
          v-model="text"
          rows="6"
          class="w-full rounded-xl px-3 py-3 font-mono text-sm"
          :placeholder="t('decks.edit.text.placeholder')"
          data-testid="text-input"
        />
        <p v-if="sizeError" class="text-sm text-red-600" role="alert" data-testid="size-error">{{ sizeError }}</p>

        <button
          type="button"
          class="btn btn-primary w-full"
          :disabled="busy || text.trim() === ''"
          data-testid="add-btn"
          @click="addWords"
        >
          {{ t('decks.edit.add') }}
        </button>

        <div v-if="lastImport" class="space-y-1 text-sm" data-testid="report">
          <p data-testid="report-summary">
            {{
              t('decks.report', {
                added: lastImport.added,
                reactivated: lastImport.reactivated,
                duplicates: lastImport.duplicates,
                rejected: lastImport.rejected.length,
              })
            }}
          </p>
          <ul v-if="lastImport.rejected.length > 0" class="text-xs text-tg-hint list-disc pl-5" data-testid="report-rejected">
            <li v-for="item in lastImport.rejected.slice(0, 20)" :key="item.line">
              {{ t('decks.rejected', { line: item.line, reason: t(`decks.reason.${item.reason}`) }) }}
            </li>
          </ul>
        </div>
      </div>

      <div class="space-y-2">
        <h2 class="font-semibold">{{ t('decks.edit.words') }}</h2>
        <p v-if="detail.words.length === 0" class="text-sm text-tg-hint" data-testid="no-words">{{ t('decks.edit.no_words') }}</p>
        <ul v-else class="divide-y divide-black/10" data-testid="words">
          <li v-for="word in detail.words" :key="word.id" class="flex items-center gap-3 py-2" :data-testid="`word-${word.id}`">
            <div class="flex-1 min-w-0">
              <p class="font-medium break-words">{{ word.word }}</p>
              <p class="text-sm text-tg-hint break-words">{{ word.translation }}</p>
            </div>
            <button
              type="button"
              class="text-sm text-tg-link underline shrink-0"
              :disabled="busy"
              data-testid="remove-word"
              @click="store.removeWord(slug, word.id)"
            >
              {{ t('decks.edit.remove_word') }}
            </button>
          </li>
        </ul>
      </div>

      <div class="pt-2 space-y-2">
        <button
          v-if="!confirmDelete"
          type="button"
          class="btn btn-secondary w-full"
          :disabled="busy"
          data-testid="delete-btn"
          @click="confirmDelete = true"
        >
          {{ t('decks.edit.delete') }}
        </button>
        <div v-else class="space-y-2" data-testid="delete-confirm">
          <p class="text-sm">{{ t('decks.edit.delete.confirm', { title: detail.title }) }}</p>
          <div class="flex gap-2">
            <button type="button" class="btn btn-primary flex-1" :disabled="busy" data-testid="delete-yes" @click="archive">
              {{ t('decks.edit.delete.yes') }}
            </button>
            <button type="button" class="btn btn-secondary flex-1" data-testid="delete-no" @click="confirmDelete = false">
              {{ t('decks.edit.delete.no') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <PaywallSheet v-if="limitReached" reason="decks" @close="store.dismissLimit()" />
  </section>
</template>
