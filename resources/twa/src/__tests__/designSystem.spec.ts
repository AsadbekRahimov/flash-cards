import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Guards of the design system (docs/14 §3-§4) that read the sources of the screens and components: what the redesign
 * cleaned up cannot creep back unnoticed.
 */

// vitest runs with cwd = resources/twa
const srcRoot = join(process.cwd(), 'src')

/** style.css without comments. */
const css = readFileSync(join(srcRoot, 'style.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

/** The bodies of the rules whose selector list contains `selector` as one entry. */
function rulesOf(selector: string): string[] {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\:]/g, '\\$&')
  const rule = new RegExp(`(?:^|[\\s,])${escaped}\\s*(?:,[^{}]*)?\\{([^}]*)\\}`, 'gm')
  return [...css.matchAll(rule)].map((match) => match[1] ?? '')
}

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

/**
 * Text on a soft background (docs/14 §4.1): the "ink" of a colour over the soft fill of the same colour, on a card and on
 * the bare page, in the built-in Telegram themes. The CSS variables cannot be computed in happy-dom, so the test does the
 * arithmetic of `color-mix(in srgb, ...)` itself from the numbers in `style.css` and the WCAG formula; a change of a share
 * in the stylesheet that drops a pair under 4.5:1 fails here.
 */
describe('style.css: contrast of text on soft backgrounds', () => {
  type Rgb = readonly [number, number, number]

  const hex = (value: string): Rgb => [1, 3, 5].map((i) => Number.parseInt(value.slice(i, i + 2), 16)) as unknown as Rgb
  /** `color-mix(in srgb, a share%, b)`; `share` is the part of `a` (0..1). */
  const mix = (a: Rgb, b: Rgb, share: number): Rgb => a.map((v, i) => v * share + (b[i] ?? 0) * (1 - share)) as unknown as Rgb
  const luminance = (c: Rgb): number => {
    const [r = 0, g = 0, b = 0] = c.map((v) => {
      const x = v / 255
      return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4
    })
    return 0.2126 * r + 0.7152 * g + 0.0722 * b
  }
  const contrast = (a: Rgb, b: Rgb): number => {
    const [la, lb] = [luminance(a), luminance(b)]
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
  }

  /** What a theme gives the tokens: `text_color`, `button_color`, `destructive_text_color`, `section_bg_color` (cards), `secondary_bg_color` (the page). */
  const THEMES = {
    'ios light': { text: '#000000', button: '#007aff', destructive: '#ff3b30', surface: '#ffffff', page: '#efeff4' },
    'ios dark': { text: '#ffffff', button: '#3e88f7', destructive: '#eb5545', surface: '#2c2c2e', page: '#1c1c1d' },
    'android light': { text: '#222222', button: '#50a8eb', destructive: '#cc2929', surface: '#ffffff', page: '#f0f0f0' },
    'android dark': { text: '#ffffff', button: '#5288c1', destructive: '#ee686f', surface: '#1d2733', page: '#151e27' },
    'desktop light': { text: '#000000', button: '#40a7e3', destructive: '#d14e4e', surface: '#ffffff', page: '#f1f1f1' },
    'desktop night blue': { text: '#f5f5f5', button: '#5288c1', destructive: '#ec3942', surface: '#17212b', page: '#232e3c' },
    'web light': { text: '#000000', button: '#3390ec', destructive: '#e53935', surface: '#ffffff', page: '#f4f4f5' },
    'web / desktop dark': { text: '#ffffff', button: '#8774e1', destructive: '#ff595a', surface: '#212121', page: '#181818' },
  } as const

  const KINDS = ['accent', 'success', 'warning', 'danger'] as const

  /** The percentage in `--app-<kind>-<part>: color-mix(in srgb, var(--app-<kind>) N%, <rest>)`. */
  function share(kind: string, part: 'ink' | 'soft'): number {
    const rest = part === 'ink' ? 'var\\(--tg-text\\)' : 'transparent'
    const match = new RegExp(`--app-${kind}-${part}:\\s*color-mix\\(in srgb,\\s*var\\(--app-${kind}\\)\\s+(\\d+)%,\\s*${rest}\\)`).exec(css)
    if (!match) throw new Error(`--app-${kind}-${part} is not a color-mix of the colour in style.css`)
    return Number(match[1]) / 100
  }

  /** The fixed colours of the app (`--app-success: #31b545;`). */
  function fixed(kind: 'success' | 'warning'): Rgb {
    const match = new RegExp(`--app-${kind}:\\s*(#[0-9a-fA-F]{6})\\s*;`).exec(css)
    if (!match?.[1]) throw new Error(`--app-${kind} is not a hex colour in style.css`)
    return hex(match[1])
  }

  const colourOf = (selector: string): string[] =>
    rulesOf(selector).flatMap((body) => [...body.matchAll(/(?:^|[\s;])color:\s*([^;]+);/g)].map((match) => (match[1] ?? '').trim()))

  it('reaches 4.5:1 for the ink of every colour on its soft fill, on a card and on the page, in every theme', () => {
    const failures: string[] = []

    for (const [name, theme] of Object.entries(THEMES)) {
      const text = hex(theme.text)
      const colours: Record<(typeof KINDS)[number], Rgb> = {
        accent: hex(theme.button),
        success: fixed('success'),
        warning: fixed('warning'),
        danger: hex(theme.destructive),
      }

      for (const kind of KINDS) {
        const ink = mix(colours[kind], text, share(kind, 'ink'))
        for (const place of ['surface', 'page'] as const) {
          const soft = mix(colours[kind], hex(theme[place]), share(kind, 'soft'))
          const ratio = contrast(ink, soft)
          if (ratio < 4.5) failures.push(`${name}: ${kind} ink on ${kind}-soft over the ${place} is ${ratio.toFixed(2)}:1`)
        }
      }
    }

    expect(failures).toEqual([])
  })

  it('measures what the ink replaced: the bare button blue of the light Android theme on its own soft fill over the page', () => {
    const android = THEMES['android light']
    const soft = mix(hex(android.button), hex(android.page), share('accent', 'soft'))

    expect(contrast(hex(android.button), soft)).toBeLessThan(2.5)
    expect(contrast(mix(hex(android.button), hex(android.text), share('accent', 'ink')), soft)).toBeGreaterThan(4.5)
  })

  it('puts the ink on the tinted button, the chips and the danger button', () => {
    expect(colourOf('.btn-tinted')).toEqual(['var(--app-accent-ink)'])
    expect(colourOf('.chip-accent')).toEqual(['var(--app-accent-ink)'])
    expect(colourOf('.chip-success')).toEqual(['var(--app-success-ink)'])
    expect(colourOf('.chip-warning')).toEqual(['var(--app-warning-ink)'])
    expect(colourOf('.chip-danger')).toEqual(['var(--app-danger-ink)'])
    expect(colourOf('.btn-danger')).toEqual(['var(--app-danger-ink)'])
  })

  it('has a Tailwind colour for every ink', () => {
    for (const kind of KINDS) expect(css).toContain(`--color-${kind}-ink: var(--app-${kind}-ink);`)
  })
})
