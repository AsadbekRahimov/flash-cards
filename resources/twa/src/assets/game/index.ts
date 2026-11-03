/**
 * The pictures of the game's items (docs/05 §5b.5, docs/18 §12.2.6): `game_items.asset` is a NAME from the server's whitelist
 * (`App\Domain\Game\Support\GameAssets::NAMES`), never a URL; the file is `{name}.svg` next to this one. Flat, 2-3 colours with their own
 * dark outline and a transparent background, so they read on the light and the dark theme alike.
 */
const FILES = import.meta.glob<string>('./*.svg', { eager: true, query: '?url', import: 'default' })

/** The picture for a name the client has no file for yet (the server may add a name before a release of the Mini App). */
const FALLBACK = 'unknown'

/** `name` → the URL of its picture; the fallback is not in it. */
export const GAME_ASSETS: Readonly<Record<string, string>> = Object.fromEntries(
  Object.entries(FILES)
    .map(([path, url]): [string, string] => [path.replace(/^\.\/|\.svg$/g, ''), url])
    .filter(([name]) => name !== FALLBACK),
)

const FALLBACK_URL: string = FILES[`./${FALLBACK}.svg`] ?? ''

/** The picture of an asset name; the fallback picture for a name that is not known. */
export function gameAssetUrl(name: string | null | undefined): string {
  return (name ? GAME_ASSETS[name] : undefined) ?? FALLBACK_URL
}
