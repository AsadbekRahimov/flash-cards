import { computed, reactive, ref, shallowRef } from 'vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { REMOTE_AUDIO_FAILED, RemoteAudio, type ClipBody } from '@/api/remoteAudio'
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
 * match does not depend on the network for its sound once the room has let the players in. A word on R2 is kept as its public
 * address (`RemoteAudio`, docs/26 §9.9): the room has loaded it into an `<audio>`, the round plays it from the cache of the device.
 *
 * - `preload(urls)` — starts loading `audio_preload` of the battle (the same list again changes nothing; another list
 *   drops the old clips).
 * - `state` — `idle` (nothing to load) / `loading` / `ready` (all loaded) / `failed` (some failed, none is loading).
 * - `loaded` / `total` — "3 of 5".
 * - `missing` — the URLs whose last answer was 503: the server has no file for them (it answers so for nothing else) - or a word
 *   on R2 that the device could not load (`remote_audio_failed`: the bucket may have no such file, only the server can tell). The screen
 *   tells the server (`audio-failed`), which looks at the file itself and voids the match, or says the file is there and the clip is
 *   asked for again; a clip leaves the list when it comes, or is asked for again.
 * - `retry()` — loads the failed clips again. `release()` — gives the `blob:` URLs back (the battle is left).
 */
export function useBattleAudio() {
  const clips = shallowRef<Clip[]>([])
  const blobs = new Map<string, ClipBody>()
  const unavailable = ref<string[]>([])

  const forget = (url: string): void => {
    unavailable.value = unavailable.value.filter((known) => known !== url)
  }

  async function fetchBlob(url: string): Promise<ClipBody> {
    try {
      const body = await api.battles.audioBlob(url)
      if (body instanceof RemoteAudio || body.size > 0) blobs.set(url, body)
      forget(url)
      return body
    } catch (err) {
      // A late answer for a list that has been dropped is nobody's.
      const wanted = clips.value.some((clip) => clip.url === url)
      const absent = err instanceof ApiClientError && (err.status === 503 || err.code === REMOTE_AUDIO_FAILED)
      if (wanted && absent && !unavailable.value.includes(url)) unavailable.value = [...unavailable.value, url]
      throw err
    }
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
      if (clip.slot.state !== 'failed') continue
      forget(clip.url) // a 503 that comes again is news again
      void clip.audio.load(clip.url, { force: true })
    }
  }

  /** The loaded recording behind an `audio_url` (its bytes, or the address of a word on R2), or null when it was not preloaded. */
  function blobFor(url: string): ClipBody | null {
    return blobs.get(url) ?? null
  }

  function release(): void {
    for (const clip of clips.value) clip.audio.revoke()
    clips.value = []
    blobs.clear()
    unavailable.value = []
  }

  const missing = computed((): string[] => unavailable.value)

  return { state, loaded, total, missing, preload, retry, blobFor, release }
}
