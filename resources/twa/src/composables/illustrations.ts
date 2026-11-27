/**
 * The pictures of the kit Muhrla 2026-11-27 (docs/design/2026-11-27-muhrla-kit/docs/ASSETS.md): the topic of every module of the six
 * platform courses, the mascot "Muhrcha" and the achievements. WebP files of `resources/twa/public/assets/img/<version>/`, which Vite
 * copies as they are into the build (`/twa/assets/img/...`): never inlined into a JS chunk, and served under `/twa/assets/`, the
 * location nginx caches for a year (`immutable`). The names carry no hash, so a changed picture goes into a new version folder
 * (`ILLUSTRATIONS_VERSION`), never over an old file.
 *
 * Only URLs are built here, from the course slug and the module (stage) number: no list of files in the bundle.
 */

/** The folder of this set of pictures; a new set of files -> a new folder (the old URLs are cached for a year). */
export const ILLUSTRATIONS_VERSION = 'm1'

/** `/twa/assets/img/m1/` in the app (the base of Vite), `/assets/img/m1/` in the tests. */
export function illustrationsBase(): string {
  return `${import.meta.env.BASE_URL}assets/img/${ILLUSTRATIONS_VERSION}/`
}

/**
 * The platform courses that have a picture for each module: the folders of the kit `A1-A2, B1, B2, C1, C2, Phrases`. Files
 * `topics/<slug>/<NN>-<size>.webp`, NN = the stage number 01-20.
 */
export const TOPIC_COURSES = ['english-a1-a2', 'english-b1', 'english-b2', 'english-c1', 'english-c2', 'english-phrases'] as const

export type TopicCourse = (typeof TOPIC_COURSES)[number]

/** Modules of a course with a picture: 1..20. */
export const TOPIC_MODULES = 20

/** The two sizes of a topic picture: 120 px (up to 56-60 px on screen) and 240 px (up to 112 px, the 2x screens). */
export type TopicSize = 120 | 240

function isTopicCourse(slug: string): slug is TopicCourse {
  return (TOPIC_COURSES as readonly string[]).includes(slug)
}

/**
 * The URL of the picture of a module, or `null` when there is none: a course of a centre, a deck, a module number outside 1..20, an
 * unknown or missing slug. The caller shows its fallback then.
 */
export function topicImage(courseSlug: string | null | undefined, stageNumber: number | null | undefined, size: TopicSize = 240): string | null {
  if (typeof courseSlug !== 'string' || !isTopicCourse(courseSlug)) return null
  if (typeof stageNumber !== 'number' || !Number.isInteger(stageNumber) || stageNumber < 1 || stageNumber > TOPIC_MODULES) return null
  return `${illustrationsBase()}topics/${courseSlug}/${String(stageNumber).padStart(2, '0')}-${size}.webp`
}

/** `src` (120 px) and `srcset` (120w, 240w) of a module picture for an `<img>`, or `null` (see `topicImage`). */
export function topicImageSet(courseSlug: string | null | undefined, stageNumber: number | null | undefined): { src: string; srcset: string } | null {
  const small = topicImage(courseSlug, stageNumber, 120)
  const large = topicImage(courseSlug, stageNumber, 240)
  if (small === null || large === null) return null
  return { src: small, srcset: `${small} 120w, ${large} 240w` }
}

/** The poses of the mascot (ASSETS.md): hello (welcome, empty states), thinking (a question), celebrate (a lesson done), freeze (Pro,
 *  a saved streak), trophy (the league), reading (the course, an empty dictionary). */
export const MASCOT_POSES = ['hello', 'thinking', 'celebrate', 'freeze', 'trophy', 'reading'] as const

export type MascotPose = (typeof MASCOT_POSES)[number]

/** The pixel size of each mascot file (440 px high: twice the 220 px the kit shows at most), for `width` / `height` of the `<img>`. */
export const MASCOT_SIZES: Readonly<Record<MascotPose, { width: number; height: number }>> = {
  hello: { width: 308, height: 440 },
  thinking: { width: 253, height: 440 },
  celebrate: { width: 191, height: 440 },
  freeze: { width: 358, height: 440 },
  trophy: { width: 183, height: 440 },
  reading: { width: 291, height: 440 },
}

export function mascotImage(pose: MascotPose): string {
  return `${illustrationsBase()}mascot/${pose}.webp`
}

/** The six achievements the kit has a picture for (`achievements/<name>.webp` and `<name>-locked.webp`, 256 x 256). */
export const ACHIEVEMENT_ARTS = ['first-lesson', 'streak-7', 'words-100', 'perfect', 'battle', 'league'] as const

export type AchievementArt = (typeof ACHIEVEMENT_ARTS)[number]

/**
 * The keys of the app's achievements (`AchievementKey` of the server) whose meaning a picture of the kit has. The rest (streak_30,
 * streak_100, words_500, words_1000, first_friend, game_first_boss, long_recall_25) have none yet; `perfect` and `battle` of the kit
 * have no achievement of the app yet.
 */
export const ACHIEVEMENT_ART_BY_KEY: Readonly<Record<string, AchievementArt>> = {
  first_session: 'first-lesson',
  streak_7: 'streak-7',
  words_100: 'words-100',
  league_top1: 'league',
}

/** The picture of an achievement art, coloured or the grey "locked" one. */
export function achievementArtImage(art: AchievementArt, locked = false): string {
  return `${illustrationsBase()}achievements/${art}${locked ? '-locked' : ''}.webp`
}

/** The picture of an achievement of the app by its key, or `null` when the kit has none for it. */
export function achievementImage(key: string, locked = false): string | null {
  const art = ACHIEVEMENT_ART_BY_KEY[key]
  return art === undefined ? null : achievementArtImage(art, locked)
}

/** The pictures already asked for in this open (`prefetchImage`): each is fetched once. */
const prefetched = new Set<string>()

/** How long to wait for an idle moment before the fetch goes anyway (and the wait where there is no `requestIdleCallback`: Safari). */
const PREFETCH_IDLE_TIMEOUT_MS = 3000

/**
 * Fetches a picture into the cache of the browser in an idle moment after the start, once per open, so that a screen that needs it
 * later has it even without the network: the mascot `thinking` of F5 "Нет сети" (docs/40 §2.6) is shown exactly when nothing can be
 * loaded. `new Image()` asks for the very URL the `<img>` will ask for (the files are cached as `immutable`); a failed fetch costs nothing.
 */
export function prefetchImage(url: string): void {
  if (typeof window === 'undefined' || prefetched.has(url)) return
  prefetched.add(url)
  const load = (): void => {
    const image = new Image()
    image.decoding = 'async'
    image.src = url
  }
  if (typeof window.requestIdleCallback === 'function') window.requestIdleCallback(load, { timeout: PREFETCH_IDLE_TIMEOUT_MS })
  else window.setTimeout(load, PREFETCH_IDLE_TIMEOUT_MS)
}

/** For tests: forgets what was prefetched. */
export function resetPrefetched(): void {
  prefetched.clear()
}
