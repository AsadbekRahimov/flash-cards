<script setup lang="ts">
import InitialAvatar from '@/components/InitialAvatar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { useI18n } from '@/i18n'
import type { FriendItem } from '@/api/types'

/**
 * "Who do we play with?" (docs/16 §11.2): the friends of the learner, one tap on a friend leads to the setup of the
 * battle. The list is the one of the Friends screen (`GET /friends`); with no friends the sheet points to inviting one.
 *
 * - `status` — `loading` (a skeleton), `ready`, `error` (`message` and "Retry").
 * Events: `pick` (the friend), `invite` (to the Friends screen), `retry`, `close`.
 */
defineProps<{
  friends: FriendItem[]
  status: 'loading' | 'ready' | 'error'
  /** The failure, already localized; shown with `status = 'error'`. */
  message?: string
}>()

const emit = defineEmits<{ pick: [friend: FriendItem]; invite: []; retry: []; close: [] }>()

const { t, tn } = useI18n()

const nameOf = (friend: FriendItem): string => friend.first_name || t('friends.list.anonymous')
const subtitleOf = (friend: FriendItem): string =>
  t('friends.list.subtitle', { streak: friend.streak, words: tn('friends.list.words', friend.words_learned) })
</script>

<template>
  <BottomSheet :label="t('practice.hub.friends.title')" data-testid="friend-picker" @close="emit('close')">
    <h2 class="pt-1 pr-10 text-[22px] leading-7 font-bold">{{ t('practice.hub.friends.title') }}</h2>

    <LoadingState v-if="status === 'loading'" variant="list" />

    <div v-else-if="status === 'error'" class="space-y-3 pb-1">
      <InlineAlert tone="danger" :message="message" data-testid="friends-error" />
      <button type="button" class="btn btn-tinted btn-block" data-testid="friends-retry" @click="emit('retry')">{{ t('common.retry') }}</button>
    </div>

    <EmptyState v-else-if="friends.length === 0" icon="users" :text="t('practice.hub.friends.empty')" data-testid="friends-empty">
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="friends-invite" @click="emit('invite')">{{ t('practice.hub.friends.invite') }}</button>
      </template>
    </EmptyState>

    <div v-else class="list mb-1" data-testid="friend-list">
      <button
        v-for="friend in friends"
        :key="friend.id"
        type="button"
        class="list-row"
        :aria-label="t('practice.hub.friends.play_with', { name: nameOf(friend) })"
        :data-testid="`friend-${friend.id}`"
        @click="emit('pick', friend)"
      >
        <InitialAvatar :name="nameOf(friend)" />
        <span class="min-w-0 flex-1">
          <span class="block truncate text-base leading-[22px] font-medium">{{ nameOf(friend) }}</span>
          <span class="block truncate text-[13px] leading-[18px] text-tg-hint">{{ subtitleOf(friend) }}</span>
        </span>
        <AppIcon name="chevron-right" :size="16" class="text-tg-hint" />
      </button>
    </div>
  </BottomSheet>
</template>
