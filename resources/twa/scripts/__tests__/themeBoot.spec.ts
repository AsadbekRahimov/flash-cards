// @vitest-environment node
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import type { IndexHtmlTransformContext, ResolvedConfig, Rollup } from 'vite'
import { CYRILLIC_FONT_SOURCE, THEME_BOOT_PLACEHOLDER, THEME_BOOT_SOURCE, findBootAssets, themeBoot } from '../themeBoot'

// vitest runs with cwd = resources/twa
const root = process.cwd()
const indexHtml = readFileSync(join(root, 'index.html'), 'utf8')

type Handler = (html: string, ctx: Partial<IndexHtmlTransformContext>) => string

function plugin(command: 'build' | 'serve') {
  const p = themeBoot()
  ;(p.configResolved as (config: ResolvedConfig) => void)({ root, base: '/twa/', command } as ResolvedConfig)
  const hook = p.transformIndexHtml as { order: string; handler: Handler }
  return { p, hook }
}

function asset(fileName: string, extra: Partial<Rollup.OutputAsset> = {}): Rollup.OutputAsset {
  return { type: 'asset', fileName, name: undefined, names: [], originalFileName: null, originalFileNames: [], source: '', needsCodeReference: false, ...extra }
}

const bundle = (): Rollup.OutputBundle => ({
  'assets/theme-boot-AbC123.js': asset('assets/theme-boot-AbC123.js', { names: ['theme-boot.js'] }),
  'assets/source-serif-4-cyrillic-wght-normal-XyZ789.woff2': asset('assets/source-serif-4-cyrillic-wght-normal-XyZ789.woff2', {
    originalFileNames: [CYRILLIC_FONT_SOURCE],
  }),
  'assets/source-serif-4-latin-wght-normal-Q1.woff2': asset('assets/source-serif-4-latin-wght-normal-Q1.woff2'),
})

describe('index.html', () => {
  it('runs the start script in <head> right after telegram-web-app.js, before the bundle', () => {
    const telegram = indexHtml.indexOf('<script src="https://telegram.org/js/telegram-web-app.js"></script>')
    const boot = indexHtml.indexOf(THEME_BOOT_PLACEHOLDER)
    const head = indexHtml.indexOf('</head>')
    const bundleScript = indexHtml.indexOf('<script type="module"')

    expect(telegram).toBeGreaterThan(-1)
    expect(boot).toBeGreaterThan(telegram)
    expect(boot).toBeLessThan(head)
    expect(boot).toBeLessThan(bundleScript)
    // nothing but comments and whitespace between the Telegram script and the start script
    expect(indexHtml.slice(telegram, boot).replace(/<!--[\s\S]*?-->/g, '').split('\n').slice(1).join('').trim()).toBe('')
  })

  it('preloads only the latin font itself: the cyrillic one is up to the start script', () => {
    expect(indexHtml).toContain('href="/src/assets/fonts/source-serif-4-latin-wght-normal.woff2"')
    expect(indexHtml).not.toMatch(/<link[^>]*cyrillic/)
  })

  it('has no inline script (CSP: script-src \'self\' https://telegram.org)', () => {
    for (const [tag] of indexHtml.matchAll(/<script\b[^>]*>/g)) expect(tag).toMatch(/\bsrc="/)
  })
})

describe('themeBoot (vite plugin)', () => {
  it('build: puts the hashed start script and the hashed cyrillic font into index.html, after everything else (post)', () => {
    const { hook } = plugin('build')

    const html = hook.handler(indexHtml, { bundle: bundle() })

    expect(hook.order).toBe('post')
    expect(html).not.toContain(THEME_BOOT_PLACEHOLDER)
    expect(html).toContain(
      '<script src="/twa/assets/theme-boot-AbC123.js" data-cyrillic-font="/twa/assets/source-serif-4-cyrillic-wght-normal-XyZ789.woff2"></script>',
    )
  })

  it('build: emits the minified start script as a hashed asset (assetFileNames: assets/[name]-[hash][extname])', async () => {
    const { p } = plugin('build')
    const emitFile = vi.fn(() => 'ref')

    await (p.buildStart as (this: unknown) => Promise<void>).call({ emitFile })

    expect(emitFile).toHaveBeenCalledTimes(1)
    const emitted = (emitFile.mock.calls[0] as unknown as [{ type: string; name: string; source: string }])[0]
    expect(emitted.type).toBe('asset')
    expect(emitted.name).toBe('theme-boot.js')
    expect(emitted.source).toContain('prefers-color-scheme: dark')
    expect(emitted.source).not.toContain('/*') // minified: the comments are gone
    expect(emitted.source.length).toBeLessThan(readFileSync(join(root, THEME_BOOT_SOURCE), 'utf8').length / 2)
  })

  it('dev: points at the source files under the base', async () => {
    const { p, hook } = plugin('serve')
    const emitFile = vi.fn()
    await (p.buildStart as (this: unknown) => Promise<void>).call({ emitFile })

    const html = hook.handler(indexHtml, {})

    expect(emitFile).not.toHaveBeenCalled()
    expect(html).toContain(`<script src="/twa/${THEME_BOOT_SOURCE}" data-cyrillic-font="/twa/${CYRILLIC_FONT_SOURCE}"></script>`)
  })

  it('refuses an index.html without the place for the script', () => {
    expect(() => plugin('serve').hook.handler('<html><head></head></html>', {})).toThrow(/theme-boot/)
  })

  it('finds the font by its file name when the bundle does not say its source, and fails loudly when a file is missing', () => {
    const b = bundle()
    b['assets/source-serif-4-cyrillic-wght-normal-XyZ789.woff2'] = asset('assets/source-serif-4-cyrillic-wght-normal-XyZ789.woff2')
    expect(findBootAssets(b).font).toBe('assets/source-serif-4-cyrillic-wght-normal-XyZ789.woff2')

    const noFont = bundle()
    delete noFont['assets/source-serif-4-cyrillic-wght-normal-XyZ789.woff2']
    expect(() => findBootAssets(noFont)).toThrow(/cyrillic/)

    const noBoot = bundle()
    delete noBoot['assets/theme-boot-AbC123.js']
    expect(() => findBootAssets(noBoot)).toThrow(/theme-boot\.js/)
  })
})
