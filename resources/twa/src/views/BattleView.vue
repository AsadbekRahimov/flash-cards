<script setup lang="ts">
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useBackButton } from '@/composables/useBackButton'
import { useI18n } from '@/i18n'

/**
 * A battle: the invitation, the room, the countdown, the rounds and the result (docs/16 §11.2). PLACEHOLDER of task
 * 18.1-T: the route exists (invitations and the private message of the bot lead to `/battles/{id}`), the screen is made by 18.6-T.
 * "Back" leads to Friends.
 */
defineProps<{ id: number }>()

const { t } = useI18n()

useBackButton({ name: 'friends' })
</script>

<template>
  <div class="page" data-testid="battle-view">
    <PageHeader :title="t('practice.hub.battle.title')" />
    <EmptyState icon="bolt" :title="t('practice.hub.battle.soon_title')" :text="t('practice.hub.battle.soon_text')" />
  </div>
</template>
