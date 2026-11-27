/**
 * "Share through the bot" (docs/04 A.22, docs/36 §1в): the kinds the Mini App shares now, the prepared message and the client events.
 * `league_week` / `month_place` come later (the server answers 422 to them for now).
 */
export type ShareKind = 'achievement' | 'game_city' | 'invite' | 'friend_invite'

/** POST /share/prepare: `subject` - the achievement key or the stage id (as a string); none for the invitations. */
export interface SharePrepareRequest {
  kind: ShareKind
  subject?: string
}

/** 200 of POST /share/prepare: the id for `WebApp.shareMessage` and when Telegram forgets it (ISO 8601). */
export interface SharePrepared {
  id: string
  expires_at: string
}

/** Why the plain share dialog was opened instead: a client below Mini Apps 8.0, or prepare / `shareMessage` failed. */
export type ShareFallbackReason = 'old_client' | 'error'

/**
 * F3 "Share to a story" (docs/40 §2.4, docs/04 A.26): what a story can show - the learner's streak (no subject: the server reads the
 * streak itself) or an achievement of the learner (its key).
 */
export type StoryShareKind = 'streak' | 'achievement'

/** POST /share/story: `subject` - the achievement key; none for the streak. */
export interface StoryShareRequest {
  kind: StoryShareKind
  subject?: string
}

/**
 * `StoryShareResource`: the 9:16 picture the server drew (a public `https` JPEG for `WebApp.shareToStory`), the caption (≤ 200 characters,
 * the referral link in it) and the link of the widget (shown by Telegram to Premium users only).
 */
export interface StoryShare {
  media_url: string
  text: string
  widget_link: { url: string; name: string } | null
}

/**
 * Why the story editor was not opened and the plain share went instead: `old_client` - below Mini Apps 7.8; `unavailable` - the server
 * cannot give a picture here (403 `share_unavailable`, sharing off, 404); `error` - Telegram refused the picture (an SDK exception).
 */
export type StoryFallbackReason = 'old_client' | 'unavailable' | 'error'

/** POST /share/events (204): `reason` only, and always, for `fallback` / `story_fallback`. `story_sent` means "the editor opened". */
export type ShareEventRequest =
  | { event: 'sent'; kind: ShareKind }
  | { event: 'fallback'; kind: ShareKind; reason: ShareFallbackReason }
  | { event: 'story_sent'; kind: StoryShareKind }
  | { event: 'story_fallback'; kind: StoryShareKind; reason: StoryFallbackReason }
