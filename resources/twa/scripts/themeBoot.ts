import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { transformWithEsbuild, type Plugin, type Rollup } from 'vite'

/**
 * The start script of the Mini App (`src/boot/theme-boot.js`, docs/14 §4.1): a classic script that has to run in `<head>`, after
 * telegram-web-app.js and before the first paint. Vite bundles only `type="module"` scripts, so this plugin puts it in:
 *
 * - `index.html` holds the comment `<!-- theme-boot -->` where the tag goes;
 * - build: the file is minified and emitted as a hashed asset (`/twa/assets/theme-boot-<hash>.js`: long-cached like every other
 *   asset, `index.html` itself is never cached, so a new release brings a new name), same origin — CSP `script-src 'self'`;
 * - dev: the tag points at the source file, which the dev server serves as it is;
 * - `data-cyrillic-font` of the tag is the URL of the cyrillic Onest (hashed in the build), which the script preloads
 *   for a Russian interface.
 */

export const THEME_BOOT_PLACEHOLDER = '<!-- theme-boot -->'
export const THEME_BOOT_SOURCE = 'src/boot/theme-boot.js'
export const CYRILLIC_FONT_SOURCE = 'src/assets/fonts/onest-cyrillic-wght-normal.woff2'

const BOOT_NAME = 'theme-boot.js'

export function themeBootTag(src: string, cyrillicFont: string): string {
  return `<script src="${src}" data-cyrillic-font="${cyrillicFont}"></script>`
}

function assets(bundle: Rollup.OutputBundle): Rollup.OutputAsset[] {
  return Object.values(bundle).filter((chunk): chunk is Rollup.OutputAsset => chunk.type === 'asset')
}

/** The emitted file of the start script and of the cyrillic font in the bundle (by their source name; the font by its file name as a fallback). */
export function findBootAssets(bundle: Rollup.OutputBundle): { boot: string; font: string } {
  const all = assets(bundle)
  const boot = all.find((a) => a.names?.includes(BOOT_NAME) || a.name === BOOT_NAME)
  const font =
    all.find((a) => a.originalFileNames?.some((name) => name.replace(/\\/g, '/').endsWith(CYRILLIC_FONT_SOURCE))) ??
    all.find((a) => /(^|\/)onest-cyrillic-wght-normal-[\w-]+\.woff2$/.test(a.fileName))
  if (!boot || !font) {
    throw new Error(`theme-boot: ${!boot ? BOOT_NAME : CYRILLIC_FONT_SOURCE} is not in the bundle`)
  }
  return { boot: boot.fileName, font: font.fileName }
}

export function themeBoot(): Plugin {
  let root = process.cwd()
  let base = '/'
  let build = false

  return {
    name: 'lexiflow-theme-boot',
    configResolved(config) {
      root = config.root
      base = config.base
      build = config.command === 'build'
    },
    async buildStart() {
      if (!build) return
      const code = await readFile(resolve(root, THEME_BOOT_SOURCE), 'utf8')
      const minified = await transformWithEsbuild(code, BOOT_NAME, { minify: true, target: 'es2015' })
      this.emitFile({ type: 'asset', name: BOOT_NAME, source: minified.code })
    },
    transformIndexHtml: {
      order: 'post',
      handler(html, ctx) {
        if (!html.includes(THEME_BOOT_PLACEHOLDER)) {
          throw new Error(`theme-boot: index.html has no ${THEME_BOOT_PLACEHOLDER}`)
        }
        const urls = ctx.bundle
          ? (({ boot, font }) => ({ boot: base + boot, font: base + font }))(findBootAssets(ctx.bundle))
          : { boot: base + THEME_BOOT_SOURCE, font: base + CYRILLIC_FONT_SOURCE }
        return html.replace(THEME_BOOT_PLACEHOLDER, themeBootTag(urls.boot, urls.font))
      },
    },
  }
}
