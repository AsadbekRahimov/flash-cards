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

/**
 * Touch targets (docs/14 §3): a control may LOOK smaller than a finger (a 36 px small button, a segment, a switch, the
 * value of a list row), the area that takes the press may not. The test environment (happy-dom) has no layout, so the
 * rules are read from the stylesheet.
 */
describe('style.css: touch targets', () => {
  const css = readFileSync(join(srcRoot, 'style.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

  /** The bodies of the rules whose selector list contains `selector` as one entry. */
  function rulesOf(selector: string): string[] {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\:]/g, '\\$&')
    const rule = new RegExp(`(?:^|[\\s,])${escaped}\\s*(?:,[^{}]*)?\\{([^}]*)\\}`, 'gm')
    return [...css.matchAll(rule)].map((match) => match[1] ?? '')
  }

  it('lays an invisible area of at least 44 x 44 px, centred, over a small button, a segment and a switch', () => {
    const layers = rulesOf('.btn-sm::after')

    expect(layers).toHaveLength(1)
    expect(rulesOf('.tap-target::after')).toEqual(layers) // one rule for both
    const layer = layers[0] ?? ''
    expect(layer).toMatch(/content:\s*''/)
    expect(layer).toMatch(/position:\s*absolute/)
    expect(layer).toMatch(/top:\s*50%/)
    expect(layer).toMatch(/left:\s*50%/)
    expect(layer).toMatch(/width:\s*max\(100%,\s*44px\)/)
    expect(layer).toMatch(/height:\s*max\(100%,\s*44px\)/)
    expect(layer).toMatch(/transform:\s*translate\(-50%,\s*-50%\)/)
    // it needs its control as the anchor
    expect(rulesOf('.btn-sm').some((body) => /position:\s*relative/.test(body))).toBe(true)
    expect(rulesOf('.tap-target').some((body) => /position:\s*relative/.test(body))).toBe(true)
  })

  it('does not change the look of a small button: it is still 36 px', () => {
    expect(rulesOf('.btn-sm').some((body) => /@apply[^;]*\bmin-h-9\b/.test(body))).toBe(true)
  })

  it('makes the inline select of a list row 44 px high and pulls it back, so that the row keeps its height', () => {
    const body = rulesOf('.field.field-inline').find((b) => b.includes('min-height')) ?? ''

    expect(body).toMatch(/min-height:\s*44px/)
    expect(body).toMatch(/margin-block:\s*-11px/) // (44 - the 22 px line of the text) / 2
    expect(body).not.toMatch(/min-height:\s*0\b/)
  })

  it('shows a keyboard user where the focus is on a field without a box, in a token colour', () => {
    const ring = rulesOf('.field.field-inline:focus-visible').find((b) => b.includes('outline')) ?? ''

    expect(ring).toMatch(/outline:\s*2px solid var\(--app-accent\)/)
  })
})
