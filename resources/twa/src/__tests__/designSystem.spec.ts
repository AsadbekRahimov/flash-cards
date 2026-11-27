import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { brotliDecompressSync } from 'node:zlib'
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

/**
 * The code points of the cmap of a woff2 file (formats 4 and 12): the table directory, the brotli stream of the tables, the cmap
 * (never transformed in woff2). Enough to check a glyph is there without a font library.
 */
function woff2CodePoints(file: Buffer): Set<number> {
  const TAGS = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill']
  let at = 48
  const base128 = (): number => {
    let value = 0
    for (let i = 0; i < 5; i++) {
      const byte = file[at++] ?? 0
      value = value * 128 + (byte & 0x7f)
      if ((byte & 0x80) === 0) break
    }
    return value
  }
  const tables: { tag: string; length: number }[] = []
  for (let i = 0; i < file.readUInt16BE(12); i++) {
    const flags = file[at++] ?? 0
    let tag = TAGS[flags & 0x3f] ?? ''
    if ((flags & 0x3f) === 0x3f) {
      tag = file.toString('latin1', at, at + 4)
      at += 4
    }
    const original = base128()
    const transformed = (tag === 'glyf' || tag === 'loca') ? flags >> 6 === 0 : flags >> 6 !== 0
    tables.push({ tag, length: transformed ? base128() : original })
  }
  const data = brotliDecompressSync(file.subarray(at, at + file.readUInt32BE(20)))
  let offset = 0
  let cmap: Buffer | null = null
  for (const table of tables) {
    if (table.tag === 'cmap') cmap = data.subarray(offset, offset + table.length)
    offset += table.length
  }
  const codes = new Set<number>()
  if (cmap === null) return codes
  for (let i = 0; i < cmap.readUInt16BE(2); i++) {
    const sub = cmap.readUInt32BE(8 + i * 8)
    const format = cmap.readUInt16BE(sub)
    if (format === 4) {
      const segments = cmap.readUInt16BE(sub + 6) / 2
      for (let s = 0; s < segments; s++) {
        const end = cmap.readUInt16BE(sub + 14 + 2 * s)
        const start = cmap.readUInt16BE(sub + 16 + 2 * segments + 2 * s)
        for (let c = start; c <= end && c < 0xffff; c++) codes.add(c)
      }
    } else if (format === 12) {
      for (let g = 0; g < cmap.readUInt32BE(sub + 12); g++) {
        const start = cmap.readUInt32BE(sub + 16 + g * 12)
        const end = cmap.readUInt32BE(sub + 20 + g * 12)
        for (let c = start; c <= end; c++) codes.add(c)
      }
    }
  }
  return codes
}

/** `path relative to src` -> the source without comments. */
const sources = new Map(vueFiles(srcRoot).map((file) => [relative(srcRoot, file).replace(/\\/g, '/'), stripComments(readFileSync(file, 'utf8'))]))

describe('design system sources', () => {
  it('reads the screens and the components', () => {
    expect(sources.size).toBeGreaterThan(40)
    expect(sources.has('components/ui/InlineAlert.vue')).toBe(true)
  })

  it('has no colour outside the tokens: no Tailwind palette class in a template', () => {
    const palette =
      /(?<![\w-])(?:bg|text|border|ring|fill|stroke|divide|outline|from|via|to|decoration)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone|black|white)(?:-\d{2,3})?(?:\/\d+)?(?![\w-])/g

    const found = [...sources].flatMap(([file, code]) => (code.match(palette) ?? []).map((match) => `${file}: ${match}`))

    // The scrim behind the sheets is `.scrim` of style.css (the paywall uses it too since the redesign): no exception left.
    expect(found).toEqual([])
  })

  it('sets no text in a serif: Onest and Unbounded are the faces of the interface (kit 2026-11-27)', () => {
    const serif = [...sources].filter(([, code]) => /\bfont-serif\b|Source Serif|\bserif\b/.test(code)).map(([file]) => file)

    expect(serif).toEqual([])
    expect(css).not.toMatch(/--font-serif|Source Serif/)
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
 * The run of "Karvon" (docs/05 §5b.3): every control is at least 44 px on a 320 px screen, the field of a typed answer is left alone by the
 * keyboard, and nothing is told by colour only. The sources are read (the test environment has no layout).
 */
describe('the run of Karvon: touch targets and fields', () => {
  const game = [...sources].filter(([file]) => file.startsWith('components/game/') || file === 'views/GameRunView.vue')

  it('reads the screen and its components', () => {
    expect(game.length).toBeGreaterThanOrEqual(11)
  })

  it('draws the answer options with .option (QuizOption, the one of the lesson), which is at least 56 px high', () => {
    expect(sources.get('components/game/ChoiceTask.vue')).toMatch(/<QuizOption\b/)
    expect(sources.get('components/QuizOption.vue')).toMatch(/class="option min-h-\[60px\] short:min-h-12"/)
    expect(rulesOf('.option').some((body) => /@apply[^;]*\bmin-h-14\b/.test(body))).toBe(true)
  })

  it('makes the ✕ of the header a 44 px target and the "Listen" circle bigger than that', () => {
    expect(sources.get('components/game/RunHeader.vue')).toMatch(/data-testid="close-btn"/)
    expect(sources.get('components/game/RunHeader.vue')).toMatch(/class="-ml-1 flex size-11\b/)
    expect(sources.get('components/game/ChoiceTask.vue')).toMatch(/\bsize-20\b/)
  })

  it('has no control under 44 px: only buttons of the design system, no fixed small size on a button', () => {
    for (const [file, code] of game) {
      for (const button of code.match(/<button\b[^>]*>/g) ?? []) {
        expect(button, file).not.toMatch(/\b(?:size|h|min-h)-(?:[1-9]|10)\b/)
      }
    }
  })

  it('leaves the field of a typed answer alone: no autocorrection, no capitals, no spell check', () => {
    const field = sources.get('components/game/TypedAnswer.vue') ?? ''

    expect(field).toMatch(/autocapitalize="off"/)
    expect(field).toMatch(/autocorrect="off"/)
    expect(field).toMatch(/autocomplete="off"/)
    expect(field).toMatch(/spellcheck="false"/)
  })

  it('tells the flasks and the stars by shape and in words, not by colour only', () => {
    expect(sources.get('components/game/FlaskIcons.vue')).toMatch(/aria-label="label"|:aria-label="label"/)
    expect(sources.get('components/game/RunSummary.vue')).toMatch(/:aria-label="t\('gameRun.summary.stars'/)
    expect(sources.get('components/game/FlaskIcons.vue')).toMatch(/:fill="full \? 'currentColor' : 'none'"/)
  })
})

/**
 * The toast (docs/14 §4.4) stands above whatever covers the bottom edge of the window: the tab bar, or the sticky block of buttons of
 * an immersive screen (`StickyActions`, which publishes its height as `--app-actions-h`). The test environment has no layout, so the
 * rules are read from the stylesheet.
 */
describe('style.css: the toast stands on what covers the bottom edge', () => {
  it('clears the sticky block of buttons: its height, or the home-indicator inset when there is no block', () => {
    const bottom = rulesOf('.toast').map((body) => /(?:^|[\s;])bottom:\s*([^;]+);/.exec(body)?.[1]).find((value) => value !== undefined)

    // the block's own height holds the inset (its padding is calc(0.75rem + var(--app-safe-b))): the larger of the two, not their sum
    expect(bottom).toBe('calc(12px + max(var(--app-actions-h, 0px), env(safe-area-inset-bottom, 0px)))')
  })

  it('still stands on the tab bar where there is one', () => {
    const bottom = rulesOf('.with-tabbar .toast').map((body) => /(?:^|[\s;])bottom:\s*([^;]+);/.exec(body)?.[1]).find((value) => value !== undefined)

    expect(bottom).toBe('calc(12px + var(--app-tabbar-h) + env(safe-area-inset-bottom, 0px))')
  })

  it('is above the sticky block in the stack: the block is z-10, the toast z-50', () => {
    expect(rulesOf('.toast').some((body) => /@apply[^;]*\bz-50\b/.test(body))).toBe(true)
    expect(sources.get('components/StickyActions.vue')).toMatch(/\bz-10\b/)
  })
})

/**
 * The app's own palette (docs/14 §4.1, kit Muhrla 2026-11-27): a light and a dark set of hex values in `style.css`, picked by
 * <html data-theme>. The test reads both sets and checks every pair of text and background the components use against WCAG:
 * 4.5:1 for text, 3:1 for what is not text (an icon, a dot, the frame of a selected item). A change of a value in the stylesheet
 * that drops a pair under the bar fails here. The CSS variables cannot be computed in happy-dom, so the arithmetic is done here.
 */
describe('style.css: the palette, light and dark', () => {
  type Rgb = readonly [number, number, number]

  const hex = (value: string): Rgb => [1, 3, 5].map((i) => Number.parseInt(value.slice(i, i + 2), 16)) as unknown as Rgb
  const luminance = (c: Rgb): number => {
    const [r = 0, g = 0, b = 0] = c.map((v) => {
      const x = v / 255
      return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4
    })
    return 0.2126 * r + 0.7152 * g + 0.0722 * b
  }
  const contrast = (a: string, b: string): number => {
    const [la, lb] = [luminance(hex(a)), luminance(hex(b))]
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
  }

  /** `--app-<name>: #rrggbb;` of one rule body -> `{ name: '#rrggbb' }`. */
  function paletteOf(selector: string): Record<string, string> {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const body = new RegExp(`(?:^|\\n)${escaped}\\s*\\{([^}]*)\\}`).exec(css)?.[1] ?? ''
    return Object.fromEntries([...body.matchAll(/--app-([a-z-]+):\s*(#[0-9a-fA-F]{6})\s*;/g)].map((m) => [m[1] ?? '', (m[2] ?? '').toLowerCase()]))
  }

  const PALETTES = { light: paletteOf(':root'), dark: paletteOf(":root[data-theme='dark']") }

  const KINDS = ['accent', 'success', 'warning', 'danger'] as const
  const BACKGROUNDS = ['page', 'surface', 'fill', 'sunken', 'accent-soft', 'highlight', 'nav-active'] as const

  /** [text, background] pairs that must reach 4.5:1. */
  const TEXT_PAIRS: [string, string][] = [
    // body and secondary text on every neutral background, on the selected row and on the big tinted card
    ...BACKGROUNDS.flatMap((bg): [string, string][] => [
      ['text', bg],
      ['muted', bg],
    ]),
    // meta and dates: on the page and on a card only (on the sunken paper it is the text of a disabled control, exempt)
    ['text-tertiary', 'page'],
    ['text-tertiary', 'surface'],
    // links and text buttons, the accent in a line of text; danger is a title colour too (a destructive list row)
    ['accent', 'page'],
    ['accent', 'surface'],
    ['accent', 'highlight'],
    ['accent', 'accent-soft'],
    ['accent', 'sunken'],
    // the score of the learner's own row of a table
    ['accent', 'nav-active'],
    ['danger', 'surface'],
    ['danger', 'page'],
    // the main button (ink); on-primary also sits on the accent (the "Listen" circle)
    ['on-primary', 'primary'],
    ['on-primary', 'accent'],
    ['on-inverse', 'inverse'],
    // the key of a checked answer: ink of the state with the on-inverse figure
    ['on-inverse', 'success-ink'],
    ['on-inverse', 'danger-ink'],
    // the toast is inverted
    ['surface', 'text'],
    // every ink: on its soft fill (status box, option, chip on a soft row), on a card and on the page
    ...KINDS.flatMap((kind): [string, string][] => [
      [`${kind}-ink`, `${kind}-soft`],
      [`${kind}-ink`, 'surface'],
      [`${kind}-ink`, 'page'],
    ]),
  ]

  /** [mark, background] pairs of what is not text (icons, dots, frames, the active tab line): 3:1. */
  const MARK_PAIRS: [string, string][] = [
    ...(['success', 'warning', 'danger'] as const).flatMap((kind): [string, string][] => [
      [kind, 'surface'],
      [kind, 'page'],
      [kind, `${kind}-soft`],
    ]),
    ['primary', 'page'],
    ['primary', 'surface'],
    ['primary', 'accent-soft'],
    ['primary', 'highlight'],
    // the frame of a selected card or answer
    ['border-strong', 'surface'],
    ['border-strong', 'page'],
    // the fill of a progress line on its track
    ['inverse', 'track'],
    // the stamp, the streak, the current segment and the mark of the active tab
    ['muhr', 'page'],
    ['muhr', 'surface'],
    ['muhr', 'track'],
    // a step done of the plan, a segment of the goal: white tick on the green
    ['on-primary', 'success'],
    // крупная цифра-метка, решение по макету кита: the number of the current step of the plan, the initial of the learner's own row
    // (the paper on muhr in the light palette, 3.6:1; ink in the dark one)
    ['on-muhr', 'muhr'],
  ]

  it('reads the same set of tokens for both palettes, all of them hex', () => {
    const names = Object.keys(PALETTES.light).sort()

    expect(names.length).toBeGreaterThanOrEqual(26)
    expect(Object.keys(PALETTES.dark).sort()).toEqual(names)
    for (const name of [
      'page', 'surface', 'fill', 'hairline', 'divider', 'track', 'text', 'muted', 'accent', 'primary', 'on-primary',
      'inverse', 'on-inverse', 'sunken', 'muhr', 'on-muhr', 'text-tertiary', 'border-strong', 'accent-soft',
    ]) {
      expect(names, name).toContain(name)
    }
    for (const kind of KINDS) for (const part of ['soft', 'ink', 'line']) expect(names).toContain(`${kind}-${part}`)
  })

  /** The roles of the kit (docs/design/2026-11-27-muhrla-kit/tokens/tokens.css) and the old role that carries each of them. */
  const KIT_ROLES = {
    'bg/canvas': 'page',
    'bg/surface': 'surface',
    'bg/sunken': 'sunken',
    'bg/accent-soft': 'accent-soft',
    'bg/inverse': 'inverse',
    'text/primary': 'text',
    'text/secondary': 'muted',
    'text/tertiary': 'text-tertiary',
    'text/on-inverse': 'on-inverse',
    'text/accent': 'accent',
    'border/default': 'hairline',
    'border/strong': 'border-strong',
    'accent/muhr': 'muhr',
    'status/success': 'success',
    'status/success-soft': 'success-soft',
    'status/error': 'danger',
    'status/error-soft': 'danger-soft',
  } as const

  const KIT = {
    light: {
      'bg/canvas': '#f6f2ea', 'bg/surface': '#fffdf8', 'bg/sunken': '#eee8dc', 'bg/accent-soft': '#fbe3d9', 'bg/inverse': '#1a1a1f',
      'text/primary': '#1a1a1f', 'text/secondary': '#5e5a52', 'text/tertiary': '#736c60', 'text/on-inverse': '#fffdf8',
      'text/accent': '#b53a18', 'border/default': '#e6dfd2', 'border/strong': '#1a1a1f', 'accent/muhr': '#e4572e',
      'status/success': '#2f8f5b', 'status/success-soft': '#e3f1e8', 'status/error': '#b3261e', 'status/error-soft': '#f8e1de',
    },
    dark: {
      'bg/canvas': '#121117', 'bg/surface': '#1c1b22', 'bg/sunken': '#25232b', 'bg/accent-soft': '#3a221b', 'bg/inverse': '#f3eee4',
      'text/primary': '#f3eee4', 'text/secondary': '#b4aea3', 'text/tertiary': '#8c867b', 'text/on-inverse': '#1a1a1f',
      'text/accent': '#ff8a65', 'border/default': '#302e37', 'border/strong': '#f3eee4', 'accent/muhr': '#ff6b42',
      'status/success': '#4cc38a', 'status/success-soft': '#173323', 'status/error': '#ff7a70', 'status/error-soft': '#3b1816',
    },
  } as const

  it.each(['light', 'dark'] as const)('uses the %s palette of the kit for every role of it', (name) => {
    for (const [role, token] of Object.entries(KIT_ROLES)) {
      expect(PALETTES[name][token], `${role} -> --app-${token}`).toBe(KIT[name][role as keyof typeof KIT_ROLES])
    }
  })

  it.each(['light', 'dark'] as const)('makes the main button ink, not the accent, in the %s palette (the CTA rule of the kit)', (name) => {
    const palette = PALETTES[name]

    expect(palette.primary).toBe(palette.inverse)
    expect(palette['on-primary']).toBe(palette['on-inverse'])
    expect(palette.primary).not.toBe(palette.accent)
    expect(palette.primary).not.toBe(palette.muhr)
    // the old fills sit on the kit's paper
    expect(palette.fill).toBe(palette.sunken)
    expect(palette.track).toBe(palette.sunken)
    expect(palette['nav-active']).toBe(palette['accent-soft'])
  })

  it('keeps the dark page a deep ink with a cool cast, not black, and lighter cards on it', () => {
    const { page = '#000000', surface = '#000000' } = PALETTES.dark
    const [r, g, b] = hex(page)

    expect(page).not.toBe('#000000')
    expect(b).toBeGreaterThan(r) // a blue cast
    expect(b).toBeGreaterThan(g)
    expect(luminance(hex(surface))).toBeGreaterThan(luminance(hex(page)))
  })

  it.each(Object.keys(PALETTES))('reaches 4.5:1 for every text pair in the %s palette', (name) => {
    const palette = PALETTES[name as keyof typeof PALETTES]
    const failures = TEXT_PAIRS.flatMap(([fg, bg]) => {
      const ratio = contrast(palette[fg] ?? '#000000', palette[bg] ?? '#000000')
      return ratio < 4.5 ? [`${fg} on ${bg}: ${ratio.toFixed(2)}:1`] : []
    })

    expect(failures).toEqual([])
  })

  it.each(Object.keys(PALETTES))('reaches 3:1 for icons, dots and frames in the %s palette', (name) => {
    const palette = PALETTES[name as keyof typeof PALETTES]
    const failures = MARK_PAIRS.flatMap(([fg, bg]) => {
      const ratio = contrast(palette[fg] ?? '#000000', palette[bg] ?? '#000000')
      return ratio < 3 ? [`${fg} on ${bg}: ${ratio.toFixed(2)}:1`] : []
    })

    expect(failures).toEqual([])
  })

  it('measures why warning is not a text colour: the bare amber on a card is under 4.5:1 in the light palette, its ink is not', () => {
    const { warning = '', surface = '', 'warning-ink': ink = '' } = PALETTES.light

    expect(contrast(warning, surface)).toBeLessThan(4.5)
    expect(contrast(ink, surface)).toBeGreaterThan(4.5)
  })

  it('lets the Telegram theme paint nothing: every legacy --tg-* colour is an alias of a token of the palette', () => {
    const legacy = [...css.matchAll(/^\s+(--tg-[a-z-]+):\s*([^;]+);/gm)].map((m) => [m[1] ?? '', (m[2] ?? '').trim()] as const)

    expect(legacy.length).toBe(14)
    for (const [name, value] of legacy) expect(value, name).toMatch(/^var\(--app-[a-z-]+\)$/)
    for (const [name] of legacy) expect(css, name).toContain(`--color-${name.slice(2)}: var(${name});`)
  })

  const colourOf = (selector: string): string[] =>
    rulesOf(selector).flatMap((body) => [...body.matchAll(/(?:^|[\s;])color:\s*([^;]+);/g)].map((match) => (match[1] ?? '').trim()))

  it('puts the ink on the chips and the danger button, on-primary on the main button, the body text on the secondary one', () => {
    expect(colourOf('.btn-primary')).toEqual(['var(--app-on-primary)'])
    // the old alias `btn-tinted` is gone: one name of the secondary button
    expect(css).not.toContain('btn-tinted')
    expect(colourOf('.btn-secondary')).toEqual(['var(--app-text)'])
    expect(colourOf('.chip-ink')).toEqual(['var(--app-on-inverse)'])
    expect(colourOf('.chip-accent')).toEqual(['var(--app-accent-ink)'])
    expect(colourOf('.chip-success')).toEqual(['var(--app-success-ink)'])
    expect(colourOf('.chip-warning')).toEqual(['var(--app-warning-ink)'])
    expect(colourOf('.chip-danger')).toEqual(['var(--app-danger-ink)'])
    expect(colourOf('.btn-danger')).toEqual(['var(--app-danger-ink)'])
  })

  it('has a Tailwind colour for every token of the palette', () => {
    const RENAMED: Record<string, string> = { text: 'body', 'text-tertiary': 'tertiary', 'border-strong': 'strong' }
    for (const name of Object.keys(PALETTES.light)) {
      const tailwind = RENAMED[name] ?? name
      expect(css, name).toContain(`--color-${tailwind}: var(--app-${name});`)
    }
  })

  it('is flat: no shadow under a card, a list, a sheet, a toast or the main button', () => {
    for (const selector of ['.card', '.list', '.sheet', '.toast', '.btn-primary']) {
      for (const body of rulesOf(selector)) expect(body, selector).not.toMatch(/box-shadow:\s*0\s+-?\d+px\s+\d+px/)
    }
    expect(css).not.toMatch(/linear-gradient\(/)
  })
})

/**
 * Fonts (docs/14 §4.2): files of the repository, no CDN; every face swaps (text is never invisible while a font loads).
 */
describe('style.css: fonts', () => {
  const faces = [...css.matchAll(/@font-face\s*\{([^}]*)\}/g)].map((m) => m[1] ?? '')

  const fileOf = (body: string): string => /url\('\.\/assets\/fonts\/([^']+)'\)/.exec(body)?.[1] ?? ''

  it('declares Onest (400-600) and Unbounded (500-600), each in latin, latin-ext and cyrillic, nothing else', () => {
    const families = faces.map((body) => /font-family:\s*'([^']+)'/.exec(body)?.[1])

    expect(families).toHaveLength(6)
    for (const [family, file, weight] of [
      ['Onest', 'onest', '400 600'],
      ['Unbounded', 'unbounded', '500 600'],
    ] as const) {
      const own = faces.filter((body) => body.includes(`font-family: '${family}'`))
      expect(own, family).toHaveLength(3)
      for (const body of own) {
        expect(body).toMatch(new RegExp(`font-weight:\\s*${weight};`))
        expect(body).toMatch(/font-style:\s*normal;/)
      }
      expect(own.map((body) => new RegExp(`(${file}-[a-z-]+)-wght-normal\\.woff2`).exec(fileOf(body))?.[1]).sort()).toEqual(
        [`${file}-cyrillic`, `${file}-latin`, `${file}-latin-ext`],
      )
    }
    expect(css).not.toMatch(/'Inter'|IBM Plex/)
  })

  it('loads every face from assets/fonts, as woff2, with font-display: swap and a unicode-range', () => {
    for (const body of faces) {
      expect(body).toMatch(/src:\s*url\('\.\/assets\/fonts\/[a-z0-9-]+\.woff2'\)\s*format\('woff2'\)/)
      expect(body).toMatch(/font-display:\s*swap/)
      expect(body).toMatch(/unicode-range:/)
    }
    expect(css).not.toMatch(/fonts\.googleapis|fonts\.gstatic|@import\s+url/)
  })

  it('puts the Uzbek apostrophes ʻ (U+02BB) and ʼ (U+02BC) in the latin faces, and the Russian letters in the cyrillic ones', () => {
    for (const file of ['onest', 'unbounded']) {
      const latin = faces.find((body) => body.includes(`${file}-latin-wght-normal`)) ?? ''
      const cyrillic = faces.find((body) => body.includes(`${file}-cyrillic-wght-normal`)) ?? ''

      expect(latin).toMatch(/U\+02BB-02BC/)
      expect(latin).toMatch(/U\+0000-00FF/)
      expect(cyrillic).toMatch(/U\+0400-045F/)
    }
  })

  it('has the glyphs of ʻ and ʼ in the latin files themselves (the cmap of the woff2)', () => {
    for (const file of ['onest-latin-wght-normal.woff2', 'unbounded-latin-wght-normal.woff2']) {
      const codes = woff2CodePoints(readFileSync(join(srcRoot, 'assets', 'fonts', file)))
      expect(codes.has(0x02bb), file).toBe(true)
      expect(codes.has(0x02bc), file).toBe(true)
      expect(codes.has(0x0410), file).toBe(false) // the cyrillic is in its own file
    }
    expect(woff2CodePoints(readFileSync(join(srcRoot, 'assets', 'fonts', 'onest-cyrillic-wght-normal.woff2'))).has(0x0416)).toBe(true)
  })

  it('makes Onest the text of the app, Unbounded the display face and the face of numbers, transcription in the system monospace', () => {
    expect(css).toMatch(/--font-sans:\s*'Onest',/)
    expect(css).toMatch(/--font-display:\s*'Unbounded',/)
    expect(css).toMatch(/--font-mono:\s*'Unbounded',/)
    expect(rulesOf('.font-mono').some((body) => /font-variant-numeric:\s*tabular-nums/.test(body))).toBe(true)
    expect(css).toMatch(/--default-font-family:\s*var\(--font-sans\);/)
    expect(css).toMatch(/--font-ipa:\s*(?!'Unbounded')/)
  })

  it.each(['t-display-l', 't-display-m', 't-title-m', 't-word-xl', 't-numeric-l', 't-body-l', 't-body-m', 't-button', 't-label-s', 't-caption'])(
    'has the text style %s of the kit',
    (name) => {
      expect(css).toMatch(new RegExp(`@utility ${name} \\{`))
    },
  )

  it('has every font file it names, and its licence next to it', () => {
    const dir = join(srcRoot, 'assets', 'fonts')
    const files = readdirSync(dir)

    for (const body of faces) {
      const file = /url\('\.\/assets\/fonts\/([^']+)'\)/.exec(body)?.[1] ?? ''
      expect(files, file).toContain(file)
    }
    expect(files).toEqual(expect.arrayContaining(['OFL-Onest.txt', 'OFL-Unbounded.txt']))
    expect(files.filter((file) => file.endsWith('.woff2')).sort()).toEqual(
      faces.map((body) => /url\('\.\/assets\/fonts\/([^']+)'\)/.exec(body)?.[1] ?? '').sort(),
    )
  })
})

/** The Karvon map (docs/05 §5b.2): everything a finger has to hit is 44 px high, the stars are 12 px. */
describe('the Karvon map', () => {
  const view = sources.get('views/GameMapView.vue') ?? ''

  /** The `<button …>` tag of the template that carries `testid`. */
  const buttonTag = (testid: string): string => new RegExp(`<button[^>]*data-testid="${testid}"[^>]*>`).exec(view)?.[0] ?? ''

  it('reads the screen', () => {
    expect(view).toContain('data-testid="game-map-view"')
  })

  it.each(['active-run', 'boss-pro'])('makes the target %s at least 44 px high', (testid) => {
    expect(buttonTag(testid), testid).toMatch(/\bmin-h-11\b/)
  })

  it('makes a stop row 44 px high', () => {
    expect(view).toMatch(/<button[^>]*class="[^"]*\bmin-h-11\b[^"]*"[^>]*:data-testid="`stop-/s)
  })

  it('draws the stars 12 px', () => {
    const stars = [...view.matchAll(/name="star"[^>]*:size="(\d+)"/g)].map((match) => match[1])
    expect(stars.length).toBeGreaterThan(0)
    expect(new Set(stars)).toEqual(new Set(['12']))
  })

  it('has no fixed width that would break 320 px', () => {
    expect(view).not.toMatch(/\bw-\[\d{3,}px\]|\bmin-w-\[\d{3,}px\]/)
  })
})

/** The caravan of the day and its entries (docs/05 §5b.4): every target is 44 px, nothing is a fixed width. */
describe('the caravan of the day', () => {
  const daily = sources.get('views/GameDailyView.vue') ?? ''
  const map = sources.get('views/GameMapView.vue') ?? ''
  const todayView = sources.get('views/TodayView.vue') ?? ''

  it('reads the screen', () => {
    expect(daily).toContain('data-testid="game-daily-view"')
  })

  it('has no button under 44 px and no fixed size on a button', () => {
    const buttons = daily.match(/<button\b[^>]*>/g) ?? []
    expect(buttons.length).toBeGreaterThanOrEqual(4)
    for (const button of buttons) expect(button).not.toMatch(/\b(?:size|h|min-h)-(?:[1-9]|10)\b/)
  })

  it('makes the card on the map and the entry on "Today" 44 px high', () => {
    expect(/<button[^>]*data-testid="daily-card"[^>]*>/s.exec(map)?.[0]).toMatch(/\bmin-h-11\b/)
    // a row of the kit's ListRow on "Today": 60 px
    expect(/<RouterLink[^>]*data-testid="game-entry"[^>]*>/s.exec(todayView)?.[0]).toMatch(/\bmin-h-(?:11|\[60px\])/)
  })

  it('has no fixed width that would break 320 px', () => {
    expect(daily).not.toMatch(/\bw-\[\d{3,}px\]|\bmin-w-\[\d{3,}px\]/)
  })
})

/** "My caravan" and the boosters (docs/05 §5b.5): every control is 44 px at least on a 320 px screen. Sources are read (no layout in tests). */
describe('My caravan and the boosters: touch targets', () => {
  const view = sources.get('views/GameCaravanView.vue') ?? ''
  const card = sources.get('components/game/ShopItemCard.vue') ?? ''
  const row = sources.get('components/game/BoosterRow.vue') ?? ''

  it('reads the screen and its components', () => {
    expect(view).toContain('data-testid="game-caravan-view"')
    expect(card).toContain('data-testid')
    expect(row).toContain('data-testid="boosters"')
  })

  it('makes the category buttons and the ledger rows 44 px high', () => {
    expect(view).toMatch(/<button[^>]*role="tab"[^>]*class="[^"]*\bmin-h-11\b/s)
    expect(view).toMatch(/class="list-row[^"]*\bmin-h-11\b/)
  })

  it('makes every button of the card 44 px high (btn-sm is 36 px, min-h-11 lifts it)', () => {
    const buttons = card.match(/<button\b[^>]*>/g) ?? []
    expect(buttons.length).toBe(4)
    for (const button of buttons) expect(button).toMatch(/\bmin-h-11\b/)
  })

  it('makes the booster button 48 px high (an icon with its name under it) and the "My caravan" button of the map 44 px', () => {
    expect(row).toMatch(/<button[^>]*class="[^"]*\bmin-h-12\b/s)
    expect(sources.get('views/GameMapView.vue')).toMatch(/<button[^>]*class="[^"]*\bmin-h-11\b[^"]*"[^>]*data-testid="caravan-btn"/s)
  })

  it('has no fixed width that would break 320 px, and no control under 44 px on the screen', () => {
    for (const code of [view, card, row]) {
      expect(code).not.toMatch(/\bw-\[\d{3,}px\]|\bmin-w-\[\d{3,}px\]/)
    }
    for (const button of view.match(/<button\b[^>]*>/g) ?? []) expect(button).not.toMatch(/\b(?:size|h|min-h)-(?:[1-9]|10)\b/)
  })
})

/** The boss of a city (docs/05 §5b.8): the screen and the card of the city - 44 px targets, nothing is a fixed width. */
describe('the boss of a city', () => {
  const boss = sources.get('views/GameBossView.vue') ?? ''
  const card = sources.get('components/game/CityCard.vue') ?? ''
  const map = sources.get('views/GameMapView.vue') ?? ''

  it('reads the screen and the card', () => {
    expect(boss).toContain('data-testid="game-boss-view"')
    expect(card).toContain('data-testid="city-card"')
  })

  it('has no button under 44 px on the screen and on the card: only buttons of the design system', () => {
    for (const code of [boss, card]) {
      const buttons = code.match(/<button\b[^>]*>/g) ?? []
      expect(buttons.length).toBeGreaterThanOrEqual(1)
      for (const button of buttons) expect(button).not.toMatch(/\b(?:size|h|min-h)-(?:[1-9]|10)\b/)
    }
  })

  it('makes the chip of a boss on the map a 44 px target', () => {
    expect(/<button[^>]*:data-testid="`boss-open-/s.exec(map)?.[0]).toMatch(/\bmin-h-11\b/)
  })

  it('has no fixed width that would break 320 px', () => {
    for (const code of [boss, card]) expect(code).not.toMatch(/\bw-\[\d{3,}px\]|\bmin-w-\[\d{3,}px\]/)
  })
})

describe('motion of the lesson (docs/14 §6.2)', () => {
  const reduced = css.match(/@media \(prefers-reduced-motion: reduce\)\s*\{([\s\S]*?\})\s*\}/)?.[1] ?? ''

  it.each(['.grade-pulse', '.combo-pop', '.card-swap-enter-active', '.card-swap-leave-active', '.combo-enter-active'])(
    'switches %s off with prefers-reduced-motion',
    (selector) => {
      expect(reduced).toContain(selector)
    },
  )

  it('animates only transform and opacity', () => {
    const keyframes = [...css.matchAll(/@keyframes (grade-pulse|combo-pop)\s*\{([\s\S]*?\n {2}\})/g)]
    expect(keyframes).toHaveLength(2)
    for (const [, name, body] of keyframes) {
      const properties = [...(body ?? '').matchAll(/([a-z-]+)\s*:/g)].map((m) => m[1])
      expect(properties.every((p) => p === 'transform' || p === 'opacity'), name).toBe(true)
    }
  })
})
