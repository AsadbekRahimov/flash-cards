#!/usr/bin/env node
// Contact sheets of the pictures of the e2e (to look at 4-8 screens at once): a page with a grid and a caption under every picture
// (scenario, player, screen, width, theme), taken with the same Playwright and put next to the pictures. The light and the dark
// picture of one screen stand side by side.
//
//   node resources/twa/scripts/e2e/sheet.mjs <dir with the pictures> [--per 4] [--scale 1] [--width 390] [--only regexp] [--name sheet-x]
//
// Names of the pictures are `<scenario>--<player>--<screen>--<width>-<theme>[--pN].png` (see `Player.shot`); `--pN` is the Nth scroll of a
// page that is longer than the window.
import { mkdirSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { basename, join, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { loadPlaywright, parseArgs } from './kit.mjs'

const args = parseArgs(process.argv.slice(2))
const dir = resolve(String(process.argv[2] ?? ''))
if (!process.argv[2] || process.argv[2].startsWith('--')) {
  console.error('usage: node sheet.mjs <dir with the pictures> [--per 4] [--scale 1] [--width 390] [--only regexp] [--name sheet-x]')
  process.exit(2)
}
const per = Number(args.per ?? 4)
const scale = Number(args.scale ?? 1)
const only = args.only ? new RegExp(String(args.only)) : null
const width = args.width ? String(args.width) : null
// The files are `<name>-NN.png` / `.html`; the name starts with `sheet-` (such files are never taken for pictures).
const prefix = args.name ? String(args.name) : 'sheet'
if (!prefix.startsWith('sheet')) {
  console.error('--name has to start with "sheet"')
  process.exit(2)
}

/** `solo-pairs--Alice--pairs-task--390-light--p2.png` -> its parts; null for a file that is not a picture of the e2e. */
function parse(file) {
  if (!file.endsWith('.png') || file.startsWith('sheet-')) return null
  const parts = file.slice(0, -4).split('--')
  const page = /^p\d+$/.test(parts.at(-1) ?? '') ? parts.pop() : null
  const tail = /^(\d+)-(light|dark)$/.exec(parts.pop() ?? '')
  if (!tail || parts.length < 2) return null
  const label = parts.pop()
  const player = parts.pop()
  return { file, tag: parts.join('--'), player, label, page, width: tail[1], theme: tail[2], at: statSync(join(dir, file)).mtimeMs }
}

const pictures = readdirSync(dir)
  .map(parse)
  .filter((item) => item !== null && (width === null || item.width === width) && (only === null || only.test(item.file)))

// One screen = scenario + player + label + page + width; its light picture first, then the dark one.
const screens = new Map()
for (const picture of pictures) {
  const key = [picture.width, picture.tag, picture.player, picture.label, picture.page ?? ''].join('|')
  if (!screens.has(key)) screens.set(key, { ...picture, themes: {} })
  screens.get(key).themes[picture.theme] = picture.file
}
const ordered = [...screens.values()].sort((a, b) => Number(b.width) - Number(a.width) || a.at - b.at)
const cells = ordered.flatMap((screen) => ['light', 'dark'].filter((theme) => screen.themes[theme]).map((theme) => ({ screen, theme, file: screen.themes[theme] })))

const escape = (text) => text.replace(/[&<>"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[char])
const sheets = []
for (let i = 0; i < cells.length; i += per) sheets.push(cells.slice(i, i + per))

const { chromium } = loadPlaywright()
const browser = await chromium.launch()
mkdirSync(dir, { recursive: true })
const made = []
for (const [index, sheet] of sheets.entries()) {
  const cell = (item) => {
    const { screen, theme, file } = item
    const caption = `${screen.tag} · ${screen.player} · ${screen.label}${screen.page ? ` (${screen.page})` : ''} · ${screen.width} px · ${theme}`
    return `<figure><img src="${escape(file)}" style="width:${Math.round(Number(screen.width) * scale)}px"><figcaption>${escape(caption)}</figcaption></figure>`
  }
  const html = `<!doctype html><meta charset="utf-8"><style>
    body { margin: 0; padding: 10px; background: #6b6b6b; font: 13px/1.3 system-ui, sans-serif; color: #fff; }
    main { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
    figure { margin: 0; } img { display: block; outline: 1px solid #000; background: #fff; }
    figcaption { padding-top: 4px; max-width: 420px; }
  </style><main>${sheet.map(cell).join('')}</main>`
  const name = `${prefix}-${String(index + 1).padStart(2, '0')}`
  const htmlPath = join(dir, `${name}.html`)
  writeFileSync(htmlPath, html)
  const widest = Math.max(...sheet.map((item) => Number(item.screen.width))) * scale
  const page = await browser.newPage({ viewport: { width: Math.round((widest + 10) * Math.min(per, sheet.length) + 20), height: 900 } })
  await page.goto(pathToFileURL(htmlPath).href, { waitUntil: 'load' })
  await page.screenshot({ path: join(dir, `${name}.png`), fullPage: true })
  await page.close()
  made.push(`${name}.png: ${sheet.map((item) => `${item.screen.label}@${item.screen.width}/${item.theme}`).join(', ')}`)
}
await browser.close()
console.log(`${sheets.length} sheet(s) from ${pictures.length} pictures in ${basename(dir)}:\n${made.join('\n')}`)
