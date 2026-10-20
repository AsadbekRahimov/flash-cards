<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import ProgressBar from '@/components/ProgressBar.vue'
import { useAuthStore } from '@/stores/auth'
import { useDecksStore } from '@/stores/decks'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * "Мои колоды" (docs/04 A.10): the learner's own decks, their plan limits and a form for a new one.
 * A write refused by a plan limit opens the paywall sheet; the deck itself is edited in DeckEditView.
 */
const router = useRouter()
const auth = useAuthStore()
const store = useDecksStore()
const { showBackButton } = useTelegram()
const { t, tn } = useI18n()

const { decks, limits, listStatus: status, busy, errorMessage, limitReached } = storeToRefs(store)

const title = ref('')
let hideBack: (() => void) | null = null

async function load(): Promise<void> {
  try {
    await auth.ensure()
    await store.load()
  } catch (err) {
    store.fail(err, 'list')
  }
}

async function create(): Promise<void> {
  const value = title.value.trim()
  if (value === '' || busy.value) return

  const deck = await store.create(value)
  if (deck) {
    title.value = ''
    void router.push({ name: 'deck', params: { slug: deck.slug } })
  }
}

onMounted(() => {
  hideBack = showBackButton(() => void router.push({ name: 'today' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <h1 class="text-xl font-bold mb-2">{{ t('decks.title') }}</h1>
    <p class="text-sm text-tg-hint mb-4" data-testid="intro">{{ t('decks.intro') }}</p>

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

    <div v-else class="space-y-3">
      <p v-if="limits" class="text-xs text-tg-hint" data-testid="limits">
        {{ t('decks.limit', { used: limits.used_decks, max: limits.decks, words: limits.deck_words }) }}
      </p>

      <form class="flex gap-2" data-testid="create-form" @submit.prevent="create">
        <input
          v-model="title"
          type="text"
          maxlength="60"
          autocomplete="off"
          class="flex-1 rounded-xl px-3 py-3"
          :placeholder="t('decks.new.placeholder')"
          data-testid="title-input"
        />
        <button type="submit" class="btn btn-primary" :disabled="busy || title.trim() === ''" data-testid="create-btn">
          {{ t('decks.new.submit') }}
        </button>
      </form>

      <p v-if="errorMessage" class="text-sm text-red-600" role="alert" data-testid="write-error">{{ errorMessage }}</p>

      <p v-if="decks.length === 0" class="text-center text-tg-hint py-6" data-testid="empty">{{ t('decks.empty') }}</p>

      <article v-for="deck in decks" :key="deck.slug" class="card space-y-3" :data-testid="`deck-${deck.slug}`">
        <RouterLink :to="{ name: 'deck', params: { slug: deck.slug } }" class="block space-y-1" data-testid="deck-link">
          <h2 class="text-lg font-semibold">{{ deck.title }}</h2>
          <p class="text-xs text-tg-hint" data-testid="meta">{{ tn('catalog.words', deck.words_count) }}</p>
        </RouterLink>

        <div class="space-y-1">
          <ProgressBar :done="deck.progress.done" :total="deck.progress.total" />
          <p class="text-xs text-tg-hint" data-testid="progress-text">
            {{ t('catalog.progress', { done: deck.progress.done, total: deck.progress.total }) }}
          </p>
        </div>

        <p v-if="deck.is_active" class="text-sm font-semibold" data-testid="active-badge">✓ {{ t('catalog.active') }}</p>
        <button
          v-else
          type="button"
          class="btn btn-primary w-full"
          :disabled="busy || deck.words_count === 0"
          data-testid="choose-btn"
          @click="store.activate(deck.slug)"
        >
          {{ t('catalog.choose') }}
        </button>
      </article>
    </div>

    <PaywallSheet v-if="limitReached" reason="decks" @close="store.dismissLimit()" />
  </section>
</template>
