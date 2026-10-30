import { computed, reactive, shallowRef } from 'vue'
import { api } from '@/api'
import { usePracticeAudio } from '@/composables/usePracticeAudio'
import type { AudioSlot, AudioState } from '@/stores/practice'

interface Clip {
  url: string
  slot: AudioSlot
  audio: ReturnType<typeof usePracticeAudio>
}

/**
 * The clips of a dictation battle that are loaded in the room before "Ready" (docs/16 §10.3): the five recordings of
 * the match, one slot of `usePracticeAudio` for each, fetched with the token (`api.battles.audioBlob`). "Ready" waits
 * until all five are in; a clip that did not come can be asked for again.
 *
 * The loaded clips are kept as blobs: the round of the match asks `blobFor(url)` before it goes to the network, so the
 * match does not depend on the network for its sound once the room has let the players in.
 *
 * - `preload(urls)` — starts loading `audio_preload` of the battle (the same list again changes nothing; another list
 *   drops the old clips).
 * - `state` — `idle` (nothing to load) / `loading` / `ready` (all loaded) / `failed` (some failed, none is loading).
 * - `loaded` / `total` — "3 of 5".
 * - `retry()` — loads the failed clips again. `release()` — gives the `blob:` URLs back (the battle is left).
 */
export function useBattleAudio() {
  const clips = shallowRef<Clip[]>([])
  const blobs = new Map<string, Blob>()

  async function fetchBlob(url: string): Promise<Blob> {
    const blob = await api.battles.audioBlob(url)
    if (blob.size > 0) blobs.set(url, blob)
    return blob
  }

  const total = computed(() => clips.value.length)
  const loaded = computed(() => clips.value.filter((clip) => clip.slot.state === 'ready').length)
  const state = computed((): AudioState => {
    if (clips.value.length === 0) return 'idle'
    if (loaded.value === clips.value.length) return 'ready'
    const working = clips.value.some((clip) => clip.slot.state === 'loading' || clip.slot.state === 'idle')
    return working ? 'loading' : 'failed'
  })

  function preload(urls: readonly string[]): void {
    if (urls.length === clips.value.length && urls.every((url, index) => url === clips.value[index]?.url)) return

    release()
    clips.value = urls.map((url): Clip => {
      const slot: AudioSlot = reactive({ state: 'idle', blobUrl: null })
      return { url, slot, audio: usePracticeAudio({ slot, fetchBlob }) }
    })
    for (const clip of clips.value) void clip.audio.load(clip.url)
  }

  function retry(): void {
    for (const clip of clips.value) {
      if (clip.slot.state === 'failed') void clip.audio.load(clip.url, { force: true })
    }
  }

  /** The loaded recording behind an `audio_url`, or null when it was not preloaded. */
  function blobFor(url: string): Blob | null {
    return blobs.get(url) ?? null
  }

  function release(): void {
    for (const clip of clips.value) clip.audio.revoke()
    clips.value = []
    blobs.clear()
  }

  return { state, loaded, total, preload, retry, blobFor, release }
}
