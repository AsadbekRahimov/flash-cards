<script setup lang="ts">
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useBackButton } from '@/composables/useBackButton'
import { useI18n } from '@/i18n'

/**
 * Setup of a battle (docs/16 §11.2: the friend, the course and lesson, the format, then "Invite"). PLACEHOLDER of task
 * 18.1-T: the route and the entries (the hub, the Friends screen) exist, the screen itself is made by 18.6-T. It
 * already takes what the entries pass in the address (`friendship`, `course`, `lesson`). "Back" leads to Friends.
 */
defineProps<{
  friendshipId: number | null
  course: string | null
  lessonId: number | null
}>()

const { t } = useI18n()

useBackButton({ name: 'friends' })
</script>

<template>
  <div class="page" data-testid="battle-setup-view">
    <PageHeader :title="t('practice.hub.battle.title')" />
    <EmptyState icon="bolt" :title="t('practice.hub.battle.soon_title')" :text="t('practice.hub.battle.soon_text')" />
  </div>
</template>
