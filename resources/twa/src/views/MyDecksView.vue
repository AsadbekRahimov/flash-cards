<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { catalogTabs, progressPercent, progressText } from '@/composables/courseLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useDeckLimit } from '@/composables/useDeckLimit'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useAuthStore } from '@/stores/auth'
import { useDecksStore } from '@/stores/decks'
import { useI18n } from '@/i18n'

/**
 * "My decks" (docs/04 A.10, docs/14 §6.5): the second screen of the Courses tab. The learner's own decks, their
 * plan limits and a form for a new one. A write refused by a plan limit opens the paywall sheet for a Free learner and
 * explains the limit to a Pro one (`useDeckLimit`); the deck itself is edited in DeckEditView. A tab root: no system
 * "Back" button.
 */
const router = useRouter()
const auth = useAuthStore()
const store = useDecksStore()
const { t, tn } = useI18n()

const { decks, limits, listStatus: status, busy, errorMessage } = storeToRefs(store)

const tabs = computed(catalogTabs)
const title = ref('')

useBackButton(null)
const { paywall, proNotice } = useDeckLimit()
const { reviewFromPaywall } = useReviewFromPaywall(() => store.dismissLimit(), { toastErrors: true })

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
  void load()
})
</script>

<template>
  <div class="page">
    <PageHeader :title="t('catalog.title')" large />
    <SegmentedNav :items="tabs" />
    <p class="mt-2 px-4 text-[13px] leading-[18px] text-tg-hint" data-testid="intro">{{ t('decks.intro') }}</p>

    <LoadingState v-if="status === 'idle' || status === 'loading'" class="mt-4" />

    <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="load" />

    <div v-else class="mt-4 space-y-3">
      <div>
        <form class="card flex gap-2" data-testid="create-form" @submit.prevent="create">
          <input
            v-model="title"
            type="text"
            maxlength="60"
            autocomplete="off"
            class="field min-w-0 flex-1"
            :placeholder="t('decks.new.placeholder')"
            :aria-label="t('decks.new.placeholder')"
            data-testid="title-input"
          />
          <button type="submit" class="btn btn-primary shrink-0" :disabled="busy || title.trim() === ''" data-testid="create-btn">
            {{ t('decks.new.submit') }}
          </button>
        </form>
        <p v-if="limits" class="mt-1.5 px-4 text-[13px] leading-[18px] text-tg-hint" data-testid="limits">
          {{ t('decks.limit', { used: limits.used_decks, max: limits.decks, words: limits.deck_words }) }}
        </p>
      </div>

      <InlineAlert v-if="proNotice" tone="warning" :message="proNotice" data-testid="pro-limit" />
      <InlineAlert v-else-if="errorMessage" tone="danger" :message="errorMessage" data-testid="write-error" />

      <EmptyState v-if="decks.length === 0" icon="rectangle-stack" :text="t('decks.empty')" />

      <article v-for="deck in decks" :key="deck.slug" class="card" :data-testid="`deck-${deck.slug}`">
        <RouterLink
          :to="{ name: 'deck', params: { slug: deck.slug } }"
          class="flex items-start gap-2 active:opacity-60"
          data-testid="deck-link"
        >
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold break-words">{{ deck.title }}</h2>
            <div class="mt-2 flex flex-wrap gap-1.5" data-testid="meta">
              <span class="chip chip-neutral">{{ tn('catalog.words', deck.words_count) }}</span>
            </div>
          </div>
          <AppIcon name="chevron-right" :size="16" class="mt-[3px] text-tg-hint" />
        </RouterLink>

        <div class="mt-3">
          <div
            class="progress"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="progressPercent(deck.progress)"
            :aria-label="progressText(deck.progress)"
            data-testid="progress-bar"
          >
            <div class="progress-fill" :style="{ width: `${progressPercent(deck.progress)}%` }" data-testid="progress-fill" />
          </div>
          <p class="mt-1.5 text-[13px] leading-[18px] text-tg-hint" data-testid="progress-text">{{ progressText(deck.progress) }}</p>
        </div>

        <div class="mt-3">
          <span v-if="deck.is_active" class="chip chip-success" data-testid="active-badge">
            <AppIcon name="check" solid :size="14" />
            {{ t('decks.active') }}
          </span>
          <button
            v-else
            type="button"
            class="btn btn-tinted btn-block"
            :disabled="busy || deck.words_count === 0"
            data-testid="choose-btn"
            @click="store.activate(deck.slug)"
          >
            {{ t('decks.choose') }}
          </button>
        </div>
      </article>
    </div>

    <PaywallSheet v-if="paywall" reason="decks" :deck-limits="limits" @close="store.dismissLimit()" @review="reviewFromPaywall" />
  </div>
</template>
