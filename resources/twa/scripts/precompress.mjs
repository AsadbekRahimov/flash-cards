// Pre-compress the built TWA with gzip (level 9) so nginx can serve the
// `.gz` siblings via `gzip_static on` without spending CPU on every request.
// Uses only built-in node:zlib — no extra dependencies. Idempotent: running
// it twice yields the same files. See docs/11 §13 and docs/DEVOPS_GUIDE.md.
import { constants, gzipSync } from 'node:zlib'
import { existsSync } from 'node:fs'
import { readdir, readFile, rm, stat, writeFile } from 'node:fs/promises'
import { extname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const COMPRESSIBLE_EXTENSIONS = ['.js', '.css', '.html', '.svg', '.json', '.webmanifest', '.txt']
export const MIN_SIZE = 512

async function* walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) {
      yield* walk(path)
    } else if (entry.isFile()) {
      yield path
    }
  }
}

/**
 * Create `<file>.gz` next to every compressible file in `dir`.
 * A `.gz` is kept only when it is smaller than the original; a stale `.gz`
 * (from an earlier run) that no longer pays off is removed so nginx never
 * serves an outdated or larger variant.
 *
 * @param {string} dir
 * @param {{ minSize?: number, extensions?: string[] }} [options]
 * @returns {Promise<{ compressed: number, skipped: number, originalBytes: number, gzippedBytes: number }>}
 */
export async function precompress(dir, options = {}) {
  const minSize = options.minSize ?? MIN_SIZE
  const extensions = options.extensions ?? COMPRESSIBLE_EXTENSIONS
  const summary = { compressed: 0, skipped: 0, originalBytes: 0, gzippedBytes: 0 }

  for await (const file of walk(dir)) {
    if (!extensions.includes(extname(file).toLowerCase())) {
      continue
    }

    const gzPath = `${file}.gz`
    const { size } = await stat(file)

    if (size < minSize) {
      summary.skipped++
      await rm(gzPath, { force: true })
      continue
    }

    const gzipped = gzipSync(await readFile(file), { level: constants.Z_BEST_COMPRESSION })

    if (gzipped.length >= size) {
      summary.skipped++
      await rm(gzPath, { force: true })
      continue
    }

    await writeFile(gzPath, gzipped)
    summary.compressed++
    summary.originalBytes += size
    summary.gzippedBytes += gzipped.length
  }

  return summary
}

function formatKb(bytes) {
  return `${(bytes / 1024).toFixed(1)} KB`
}

// CLI: `node scripts/precompress.mjs [dir]` (default: ../../public/twa).
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const target = resolve(process.argv[2] ?? fileURLToPath(new URL('../../../public/twa', import.meta.url)))

  if (!existsSync(target)) {
    console.error(`precompress: directory not found: ${target}`)
    process.exit(1)
  }

  const { compressed, skipped, originalBytes, gzippedBytes } = await precompress(target)
  const ratio = originalBytes > 0 ? Math.round((1 - gzippedBytes / originalBytes) * 100) : 0

  console.log(
    `precompress: ${compressed} file(s) gzipped (${formatKb(originalBytes)} -> ${formatKb(gzippedBytes)}, -${ratio}%), ${skipped} skipped`,
  )
}
