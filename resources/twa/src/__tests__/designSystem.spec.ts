import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Guards of the design system (docs/14 §3-§4) that read the sources of the screens and components: what the redesign
 * cleaned up cannot creep back unnoticed.
 */

// vitest runs with cwd = resources/twa
const srcRoot = join(process.cwd(), 'src')

function vueFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name)
    if (statSync(full).isDirectory()) return name === '__tests__' ? [] : vueFiles(full)
    return name.endsWith('.vue') ? [full] : []
  })
}

const stripComments = (code: string): string =>
  code
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/(^|\s)\/\/.*$/gm, '$1')

/** `path relative to src` -> the source without comments. */
const sources = new Map(vueFiles(srcRoot).map((file) => [relative(srcRoot, file), stripComments(readFileSync(file, 'utf8'))]))

describe('design system sources', () => {
  it('reads the screens and the components', () => {
    expect(sources.size).toBeGreaterThan(40)
    expect(sources.has('components/ui/InlineAlert.vue')).toBe(true)
  })

  it('has no colour outside the tokens: no Tailwind palette class in a template', () => {
    const palette =
      /(?<![\w-])(?:bg|text|border|ring|fill|stroke|divide|outline|from|via|to|decoration)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone|black|white)(?:-\d{2,3})?(?:\/\d+)?(?![\w-])/g

    const found = [...sources].flatMap(([file, code]) => (code.match(palette) ?? []).map((match) => `${file}: ${match}`))

    // The scrim behind the paywall sheet: the theme has no token for it, black dims the light and the dark page alike.
    expect(found).toEqual(['components/PaywallSheet.vue: bg-black/40'])
  })

  it('draws every inline message with InlineAlert: only ErrorState (a whole screen) writes role="alert" itself', () => {
    const own = [...sources].filter(([, code]) => /\brole="alert"/.test(code)).map(([file]) => file)

    expect(own).toEqual(['components/ui/ErrorState.vue'])
  })
})
