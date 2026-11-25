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

/** POST /share/events (204): `reason` only, and always, for `fallback`. */
export type ShareEventRequest =
  | { event: 'sent'; kind: ShareKind }
  | { event: 'fallback'; kind: ShareKind; reason: ShareFallbackReason }
