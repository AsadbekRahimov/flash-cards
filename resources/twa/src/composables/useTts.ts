/**
 * Text-to-speech helper — plays the pre-generated mp3 (`audio_url` of the card,
 * docs/12 task 1.2c) when there is one, else uses the browser's Web Speech API,
 * otherwise falls back to the server-side TTS endpoint (backend Sprint 9+).
 *
 * iOS Safari quirk: `getVoices()` may return `[]` on first call; we listen
 * to `voiceschanged` to pick a voice asynchronously.
 */

// One sound at a time, across all useTts() instances: a fast second tap stops the first
// instead of layering on top of it. `seq` lets a stale (superseded) speak() drop out quietly.
let current: HTMLAudioElement | null = null
let seq = 0

function stopCurrent(): void {
  if (!current) return
  try {
    current.pause()
    current.currentTime = 0
  } catch {
    // best-effort
  }
  current = null
}

export function useTts() {
  const synth: SpeechSynthesis | undefined =
    typeof window !== 'undefined' ? window.speechSynthesis : undefined
  const supported = Boolean(synth)
  let voice: SpeechSynthesisVoice | null = null

  if (synth) {
    const pick = (): void => {
      const voices = synth.getVoices()
      voice = voices.find((v) => v.lang.toLowerCase().startsWith('en')) ?? voices[0] ?? null
    }
    pick()
    synth.addEventListener?.('voiceschanged', pick)
  }

  async function speak(text: string, lang = 'en-US', audioUrl?: string | null): Promise<void> {
    if (!text) return
    const mine = ++seq
    stopCurrent()
    if (audioUrl) {
      synth?.cancel()
      try {
        const audio = new Audio(audioUrl)
        current = audio
        await audio.play()
        return
      } catch {
        // File missing / blocked — fall through to Web Speech.
        // play() rejects asynchronously, so this fallback cannot run synchronously inside the tap
        // handler: browsers that keep transient user activation for a few seconds (Chrome, Android
        // WebView) still speak; a strict iOS WebView may stay silent for that one tap.
        if (mine !== seq) return
        current = null
      }
    }
    if (mine !== seq) return
    if (supported && synth) {
      try {
        const u = new SpeechSynthesisUtterance(text)
        u.lang = lang
        u.rate = 0.9
        if (voice) u.voice = voice
        synth.cancel()
        synth.speak(u)
        return
      } catch {
        // fall through to server fallback
      }
    }
    await speakFallback(text, mine)
  }

  async function speakFallback(text: string, mine: number): Promise<void> {
    try {
      const url = `/api/twa/tts?word=${encodeURIComponent(text)}`
      const audio = new Audio(url)
      current = audio
      await audio.play()
    } catch {
      // Silent — TTS is best-effort.
      if (mine === seq) current = null
    }
  }

  return { supported, speak }
}
