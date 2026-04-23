/**
 * Text-to-speech helper — uses the browser's Web Speech API where available,
 * otherwise falls back to the server-side TTS endpoint (backend Sprint 9+).
 *
 * iOS Safari quirk: `getVoices()` may return `[]` on first call; we listen
 * to `voiceschanged` to pick a voice asynchronously.
 */
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

  async function speak(text: string, lang = 'en-US'): Promise<void> {
    if (!text) return
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
    await speakFallback(text)
  }

  async function speakFallback(text: string): Promise<void> {
    try {
      const url = `/api/twa/tts?word=${encodeURIComponent(text)}`
      const audio = new Audio(url)
      await audio.play()
    } catch {
      // Silent — TTS is best-effort.
    }
  }

  return { supported, speak }
}
