<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import ClosedState from '@/components/ClosedState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { cupStatusLine } from '@/composables/cupInfo'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useCupsStore, type CupListItem } from '@/stores/cups'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'

/**
 * "My cups" (docs/33 §7.1, docs/04 A.23 `GET /cups`, the row "Friends' cups" of "Profile"): the open cups first, then the last ten over,
 * each with its state and my result; a tap opens the cup. Read on every opening. The cup switched off - "not available".
 */
const auth = useAuthStore()
const cups = useCupsStore()
const { t, tn, locale } = useI18n()
const { cups: list, unavailable } = storeToRefs(cups)

useBackButton({ name: 'profile' })

const failed = ref('')
const loading = ref(true)

async function load(): Promise<void> {
  failed.value = ''
  loading.value = true
  try {
    await auth.ensure()
    await cups.load(true)
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
  } finally {
    loading.value = false
  }
}

const open = computed((): CupListItem[] => (list.value ?? []).filter((cup) => cup.status === 'scheduled' || cup.status === 'running'))
const over = computed((): CupListItem[] => (list.value ?? []).filter((cup) => cup.status === 'finished' || cup.status === 'canceled'))

/** Today's round of a cup that runs, as the server has it (`me.today.state`): to play, to continue, played; nothing - no round today. */
function todayPart(cup: CupListItem): string | null {
  if (cup.status !== 'running' || cup.paused !== null || cup.me === null || cup.me.status !== 'active') return null
  switch (cup.me.today?.state) {
    case 'not_started':
      return t('cup.list.today_play')
    case 'active':
      return t('cup.list.today_continue')
    case 'done':
      return t('cup.list.today_done')
    default:
      return null
  }
}

/** "Day 3 of 7 · play the round of the day · 14 points", "The cup is over · 60 points · Final place: 1". */
function subtitle(cup: CupListItem): string {
  const parts = [cupStatusLine(cup, locale.value)]
  const today = todayPart(cup)
  if (today !== null) parts.push(today)
  if (cup.me) {
    parts.push(tn('cup.points', cup.me.total))
    if (cup.me.final_rank !== null) parts.push(t('cup.final_place', { rank: cup.me.final_rank }))
  }
  return parts.join(' · ')
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="cups-view">
    <PageHeader :title="t('cup.list.title')" />

    <LoadingState v-if="loading && list === null" variant="list" />

    <ClosedState v-else-if="unavailable" mascot="thinking" :title="t('cup.off_title')" :text="t('cup.failure.off')" data-testid="off" />

    <ErrorState v-else-if="failed && list === null" :message="failed" @retry="load" />

    <!-- No cups yet: the centred hero of the kit, the mascot greets (an empty list is not an error). -->
    <ClosedState v-else-if="(list ?? []).length === 0" mascot="hello" :text="t('cup.list.empty')" data-testid="empty" />

    <div v-else class="space-y-3">
      <ListGroup v-if="open.length > 0" highlight :header="t('cup.list.open')" data-testid="cups-open">
        <ListRow v-for="cup in open" :key="cup.id" :to="{ name: 'cup', params: { id: cup.id } }" icon="trophy" :title="cup.group_title" :subtitle="subtitle(cup)" data-testid="cup-row" />
      </ListGroup>
      <ListGroup v-if="over.length > 0" :header="t('cup.list.over')" data-testid="cups-over">
        <ListRow v-for="cup in over" :key="cup.id" :to="{ name: 'cup', params: { id: cup.id } }" icon="flag" :title="cup.group_title" :subtitle="subtitle(cup)" data-testid="cup-row" />
      </ListGroup>
    </div>
  </div>
</template>
