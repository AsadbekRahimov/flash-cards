import { computed, getCurrentScope, onScopeDispose, ref, toRaw } from 'vue'
import { api } from '@/api'
import { usePracticeStore, type AudioSlot, type AudioState } from '@/stores/practice'

/**
 * What belongs to a slot but is not shown: which clip it holds, which load is the newest, what is playing. Kept by
 * slot, so that every `usePracticeAudio()` over the same slot (the screen that loads, the component that plays) works
 * with one clip.
 */
interface SlotRuntime {
  url: string | null
  seq: number
  player: HTMLAudioElement | null
}

const runtimes = new WeakMap<AudioSlot, SlotRuntime>()

function runtimeOf(slot: AudioSlot): SlotRuntime {
  const key = toRaw(slot)
  let runtime = runtimes.get(key)
  if (!runtime) {
    runtime = { url: null, seq: 0, player: null }
    runtimes.set(key, runtime)
  }
  return runtime
}

function stopPlayer(runtime: SlotRuntime): void {
  const player = runtime.player
  runtime.player = null
  if (player === null) return
  try {
    player.pause()
  } catch {
    // best effort: the clip is being dropped anyway
  }
}

export interface PracticeAudioOptions {
  /** Where the clip lives; the practice store's `audio` by default. A battle keeps one slot per clip. */
  slot?: AudioSlot
  /** Fetches the clip by the `audio_url` of an element; the solo endpoint by default (`api.battles.audioBlob` for a battle). */
  fetchBlob?: (url: string) => Promise<Blob>
}

/**
 * The clip of a dictation (docs/16 §10.2, §11.5).
 *
 * The recording is behind the learner's token, so it is fetched with `fetch` + `Authorization` (`api.practice.audioBlob`)
 * and played from a `blob:` URL; the server never gives a link that works without it. Nothing is read aloud by the
 * browser: the text of the task is not on the device, so there is nothing to give a speech synthesizer — Web Speech is
 * not used here and must not be (the dictation would be solved by the machine).
 *
 * - `load(url)` — fetch the clip of an element (once per URL; `force` loads it again, e.g. "Retry"). The state goes
 *   `loading` → `ready` | `failed`. A load that is overtaken by a newer one is dropped.
 * - `play()` — call it straight from the tap handler: the `<audio>` is created there, which keeps the gesture on iOS.
 *   Every press starts the clip from the beginning. Returns false when nothing could be played.
 * - `revoke()` — gives the `blob:` URL back and empties the slot (the element changed, the screen is left).
 *
 * The slot is a reactive `{ state, blobUrl }`: the screen reads `state` to show the loading and the failure, the
 * component reads it to enable its button.
 */
export function usePracticeAudio(options: PracticeAudioOptions = {}) {
  const slot: AudioSlot = options.slot ?? usePracticeStore().audio
  const fetchBlob = options.fetchBlob ?? ((url: string) => api.practice.audioBlob(url))
  const runtime = runtimeOf(slot)
  const playing = ref(false)

  const state = computed((): AudioState => slot.state)
  const blobUrl = computed((): string | null => slot.blobUrl)

  function release(): void {
    stopPlayer(runtime)
    playing.value = false
    if (slot.blobUrl !== null) URL.revokeObjectURL(slot.blobUrl)
    slot.blobUrl = null
  }

  async function load(url: string, { force = false }: { force?: boolean } = {}): Promise<void> {
    if (!force && runtime.url === url && (slot.state === 'loading' || slot.state === 'ready')) return

    const seq = ++runtime.seq
    runtime.url = url
    release()
    slot.state = 'loading'
    try {
      const blob = await fetchBlob(url)
      // A newer load (another element, "Retry") or `revoke()` has taken over: this clip is nobody's.
      if (seq !== runtime.seq) return
      if (blob.size === 0) throw new Error('empty clip')
      slot.blobUrl = URL.createObjectURL(blob)
      slot.state = 'ready'
    } catch {
      if (seq !== runtime.seq) return
      slot.state = 'failed'
    }
  }

  /** Not async on purpose: the `<audio>` must be created and started in the same turn as the tap. */
  function play(): Promise<boolean> {
    const source = slot.blobUrl
    if (slot.state !== 'ready' || source === null) return Promise.resolve(false)

    stopPlayer(runtime)
    const player = new Audio(source)
    runtime.player = player
    playing.value = true
    player.addEventListener('ended', () => {
      if (runtime.player === player) playing.value = false
    })
    player.addEventListener('error', () => {
      if (runtime.player !== player) return
      playing.value = false
      slot.state = 'failed'
    })

    return Promise.resolve(player.play()).then(
      () => true,
      (error: unknown) => {
        // `pause()` of a newer press (or of `revoke()`) interrupts this one: not a fault of the clip.
        if (runtime.player !== player || (error as Error | null)?.name === 'AbortError') return false
        playing.value = false
        slot.state = 'failed'
        return false
      },
    )
  }

  function revoke(): void {
    runtime.seq += 1
    runtime.url = null
    release()
    slot.state = 'idle'
  }

  // The screen that ends stops what it was playing; the clip itself stays for whoever holds the slot.
  if (getCurrentScope()) {
    onScopeDispose(() => {
      stopPlayer(runtime)
      playing.value = false
    })
  }

  return { state, blobUrl, playing, load, play, revoke }
}
