import { readonly, ref } from 'vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { ShareEventRequest, ShareKind, StoryFallbackReason, StoryShare, StoryShareKind } from '@/api/shareTypes'
import { errorMessage } from '@/i18n/errors'
import { useShare, type ShareFallback } from './useShare'
import { useTelegram } from './useTelegram'

/** The plain share a story falls back to (docs/40 §2.4): the message through the bot (`useShare`), then `t.me/share/url`. */
export interface StoryFallbackTarget extends ShareFallback {
  kind: Extract<ShareKind, 'achievement' | 'invite'>
  subject: string | null
  /** What the plain share marks once it is out (the "shared" mark of an achievement), as the screen that shares it does. */
  onShared?: () => void
}

/**
 * How a tap ended: `opened` - the story editor of Telegram was asked to open (it tells nothing back: not "posted"); `fallback` - the plain
 * share went instead (sent or its dialog opened); `cancelled` - the learner closed the chat picker of the plain share; `error` - nothing
 * opened, `error` says why and the tap can be repeated; `busy` - a share of this screen is still on its way (the tap is ignored).
 */
export type StoryOutcome = 'opened' | 'fallback' | 'cancelled' | 'error' | 'busy'

/**
 * "Share to a story" of F3 (docs/40 §2.4, docs/04 A.26), for one card - the streak or one achievement - on one screen.
 *
 * - The picture is asked of the server (`POST /share/story`) only on the tap, once: a second tap while it is on its way does nothing, and
 *   the answer is kept for the next taps of this screen (no second request for the same card).
 * - A client of Mini Apps 7.8+ opens the story editor (`shareToStory`) with the picture, the caption and the widget link. Below 7.8 the
 *   server is not asked: the plain share opens at once (`useShare`: the message through the bot, then `t.me/share/url`).
 * - 403 `share_unavailable` / 404 (no https on a stand, sharing off, the subject is gone) and a refusal of the SDK fall back to the plain
 *   share as well; a refusal of the server is kept, so a next tap does not ask again. 422 (a mistake of this build) - the same, written
 *   to the console with the kind and the code only.
 * - 429 (the limit of shares), the network, 5xx: nothing opens, `error` says what, the tap can be repeated.
 * - Each way reports itself without waiting (`POST /share/events`: `story_sent`, `story_fallback {reason}`); a failed report changes nothing.
 *
 * `fallbackOf(story)` gives the link and the text of the plain share: from the answer of the server when there is one, else from what
 * the screen knows; `null` - there is nothing to share (no link): `error` says so.
 */
export function useStoryShare(
  kind: StoryShareKind,
  subject: string | null,
  fallbackOf: (story: StoryShare | null) => Promise<StoryFallbackTarget | null>,
  messages: { noLink: () => string },
) {
  const { canShareToStory, shareToStory } = useTelegram()
  const plain = useShare()
  const pending = ref(false)
  const error = ref('')

  /** The answer of the server for this card, kept for the next taps of the screen. */
  let ready: StoryShare | null = null
  /** The server refused the picture (403 / 404 / 422): the next taps take the plain share without asking again. */
  let refused = false

  function report(body: ShareEventRequest): void {
    try {
      void api.share.event(body).catch(() => undefined)
    } catch {
      /* analytics never breaks a share */
    }
  }

  async function fallback(reason: StoryFallbackReason, story: StoryShare | null): Promise<StoryOutcome> {
    report({ event: 'story_fallback', kind, reason })
    const target = await fallbackOf(story)
    if (target === null) {
      error.value = messages.noLink()
      return 'error'
    }
    const outcome = await plain.share(target.kind, target.subject, { link: target.link, text: target.text }, target.onShared)
    if (outcome === 'busy') return 'busy'
    return outcome === 'cancelled' ? 'cancelled' : 'fallback'
  }

  async function share(): Promise<StoryOutcome> {
    if (pending.value) return 'busy'
    pending.value = true
    error.value = ''
    try {
      if (!canShareToStory()) return await fallback('old_client', null)
      if (refused) return await fallback('unavailable', null)

      let story = ready
      if (story === null) {
        try {
          story = await api.share.story(kind, subject)
          ready = story
        } catch (err) {
          if (err instanceof ApiClientError && (err.status === 403 || err.status === 404 || err.status === 422)) {
            if (err.status === 422) console.warn('share.story refused', { kind, code: err.code })
            refused = true
            return await fallback(err.status === 422 ? 'error' : 'unavailable', null)
          }
          error.value = errorMessage(err, 'settings')
          return 'error'
        }
      }

      const opened = shareToStory(story.media_url, { text: story.text, widget_link: story.widget_link ?? null })
      if (opened === 'opened') {
        report({ event: 'story_sent', kind })
        return 'opened'
      }
      return await fallback(opened === 'unsupported' ? 'old_client' : 'error', story)
    } finally {
      pending.value = false
    }
  }

  return { pending: readonly(pending), error: readonly(error), canShareToStory, share }
}
