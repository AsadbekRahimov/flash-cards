import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { GAME_ASSETS, gameAssetUrl } from '@/assets/game'

// vitest runs with cwd = resources/twa
const serverFile = join(process.cwd(), '..', '..', 'app', 'Domain', 'Game', 'Support', 'GameAssets.php')
const assetFile = (name: string): string => join(process.cwd(), 'src', 'assets', 'game', `${name}.svg`)

/** The names of the server's whitelist, read from the PHP file itself (so a name added there without a picture here fails this test). */
function serverNames(): string[] {
  const source = readFileSync(serverFile, 'utf8')
  const list = /const NAMES = \[([\s\S]*?)\];/.exec(source)?.[1] ?? ''
  return [...list.matchAll(/'([a-z0-9_]+)'/g)].map((match) => match[1] as string)
}

describe('the pictures of the game', () => {
  const names = serverNames()

  it('reads the 12 names of the server', () => {
    expect(names).toHaveLength(12)
    expect(names).toContain('camel_dromedary')
    expect(names).toContain('booster_second_chance')
  })

  it.each(names)('has a file and a map entry for %s', (name) => {
    expect(existsSync(assetFile(name))).toBe(true)
    expect(GAME_ASSETS[name], name).toBeTruthy()
    expect(gameAssetUrl(name)).toBe(GAME_ASSETS[name])
  })

  it('has no picture that the server does not name', () => {
    expect(Object.keys(GAME_ASSETS).sort()).toEqual([...names].sort())
  })

  it('draws a spare picture for an unknown name, an empty one and none', () => {
    const spare = gameAssetUrl('no_such_asset')
    expect(spare).toBeTruthy()
    expect(gameAssetUrl('')).toBe(spare)
    expect(gameAssetUrl(null)).toBe(spare)
    expect(Object.values(GAME_ASSETS)).not.toContain(spare)
  })

  it('keeps every picture transparent and on one 96 px canvas', () => {
    for (const name of names) {
      const svg = readFileSync(assetFile(name), 'utf8')
      expect(svg, name).toContain('viewBox="0 0 96 96"')
      expect(svg, name).not.toMatch(/<rect[^>]*width="96"[^>]*height="96"/)
    }
  })
})
