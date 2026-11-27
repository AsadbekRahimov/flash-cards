import { getCurrentScope, onScopeDispose, readonly, ref, shallowRef, type Ref } from 'vue'
import { onNetworkSignal } from '@/api/client'

/**
 * Is there a connection (docs/40 §2.6, F5 "Нет сети")? Two sources, no polling of the network on a timer:
 *
 * - the device: `navigator.onLine` at the start and its `online` / `offline` events;
 * - the requests of the API (`onNetworkSignal` of `api/client.ts`): `navigator.onLine` lies both ways (true on a Wi-Fi without the
 *   internet, stuck at false in some WebViews), so a request that reached the server puts `isOnline` back to true, and a load (GET) that
 *   ended as a `network` error raises `loadFailed` until any request reaches the server again (or `clearLoadFailed()`).
 *
 * The listeners live as long as the effect scope of the caller (a component, a test's `effectScope`) and go with it.
 */
export interface OnlineState {
  isOnline: Readonly<Ref<boolean>>
  loadFailed: Readonly<Ref<boolean>>
  clearLoadFailed: () => void
  /** Reads `navigator.onLine` again (the "Retry" button: a press is a good moment to ask). */
  recheck: () => void
}

function deviceOnline(): boolean {
  // No `navigator.onLine` at all (an old engine): nothing says the device is offline.
  return typeof navigator === 'undefined' || navigator.onLine !== false
}

export function useOnline(): OnlineState {
  const isOnline = ref(deviceOnline())
  const loadFailed = ref(false)

  const goOnline = (): void => {
    isOnline.value = true
  }
  const goOffline = (): void => {
    isOnline.value = false
  }

  window.addEventListener('online', goOnline)
  window.addEventListener('offline', goOffline)
  const stopSignals = onNetworkSignal(({ outcome, method }) => {
    if (outcome === 'reached') {
      isOnline.value = true
      loadFailed.value = false
    } else if (method === 'GET') {
      loadFailed.value = true
    }
  })

  if (getCurrentScope()) {
    onScopeDispose(() => {
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
      stopSignals()
    })
  }

  return {
    isOnline: readonly(isOnline),
    loadFailed: readonly(loadFailed),
    clearLoadFailed: () => {
      loadFailed.value = false
    },
    recheck: () => {
      isOnline.value = deviceOnline()
    },
  }
}

/**
 * What a screen tells the "Нет соединения" screen while it is open (the training: docs/40 §2.6). Without one the shell decides alone: an
 * ordinary screen is loaded again, an immersive one keeps its own state.
 *
 * - `retry` - "Повторить" and the return of the network: send again what did not go (the answer that waits, the card that did not load).
 * - `chip` - the line of the chip, only when it is true («Урок 3 · сохранено 4 из 8»); null - no chip.
 * - `pendingAnswer` - an answer of the learner waits on the device for the network: the text says so.
 */
export interface OfflineContext {
  retry: () => void
  chip: () => string | null
  pendingAnswer: () => boolean
}

const context = shallowRef<OfflineContext | null>(null)

/** The context of the screen on view, or null. */
export const offlineContext: Readonly<Ref<OfflineContext | null>> = context

/** Registers the context of the calling screen for as long as its scope lives. */
export function useOfflineContext(value: OfflineContext): void {
  context.value = value
  if (getCurrentScope()) {
    onScopeDispose(() => {
      if (context.value === value) context.value = null
    })
  }
}
