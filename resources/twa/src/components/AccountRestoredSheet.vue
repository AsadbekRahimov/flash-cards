<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'

/**
 * "Welcome back! The account is restored" (R11b): a one-time sheet for a learner who deleted the account and came back
 * within the 30 days. The server decides (`account_restored` of `POST /auth`, kept by the auth store); the sheet lives in the app shell,
 * so it shows on whatever screen the Mini App opens with. It takes the notice from the store once per session, which tells the server
 * (`seen`) - so neither a token refresh nor the bot's `/start` repeats it.
 * With `pro_can_resume` it adds that the Pro auto-renewal was stopped by the deletion and a button to the subscription screen.
 */
const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()

const open = ref(false)
const canResume = ref(false)

// The shell mounts before `/auth` is done (and `/auth` runs again at every token refresh): watch the store, take the notice once.
watch(
  () => auth.accountRestored,
  (pending) => {
    if (pending === null) return
    const notice = auth.takeAccountRestored()
    if (notice === null) return
    canResume.value = notice.pro_can_resume === true
    open.value = true
  },
  { immediate: true },
)

function openBilling(): void {
  open.value = false
  void router.push({ name: 'billing' })
}
</script>

<template>
  <BottomSheet v-if="open" :label="t('restored.title')" data-testid="restored-sheet" @close="open = false">
    <!-- The mascot greets the learner back (ASSETS.md: hello), on the centre axis of the kit. -->
    <header class="flex flex-col items-center gap-2 pt-1 text-center">
      <MascotArt pose="hello" :height="96" />
      <h2 class="t-display-m" data-testid="restored-title">{{ t('restored.title') }}</h2>
    </header>

    <div class="space-y-2 text-center">
      <p class="t-body-m" data-testid="restored-text">{{ t('restored.text') }}</p>
      <p v-if="canResume" class="t-body-m text-muted" data-testid="restored-pro">{{ t('restored.pro') }}</p>
    </div>

    <div class="space-y-1 pb-1">
      <button type="button" class="btn btn-primary btn-block" data-testid="restored-ok" @click="open = false">
        {{ t('restored.ok') }}
      </button>
      <button v-if="canResume" type="button" class="btn btn-ghost btn-block" data-testid="restored-billing" @click="openBilling()">
        {{ t('restored.billing') }}
      </button>
    </div>
  </BottomSheet>
</template>
