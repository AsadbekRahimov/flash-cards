// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { gunzipSync, gzipSync } from 'node:zlib'
// @ts-expect-error plain ESM script without type declarations
import { precompress } from '../precompress.mjs'

describe('precompress', () => {
  let dir: string
  const big = 'const lexiflow = "flash cards";\n'.repeat(100)

  beforeEach(() => {
    dir = mkdtempSync(join(tmpdir(), 'twa-precompress-'))
    mkdirSync(join(dir, 'assets'))
  })

  afterEach(() => {
    rmSync(dir, { recursive: true, force: true })
  })

  it('creates a smaller .gz for large compressible files, including nested ones', async () => {
    writeFileSync(join(dir, 'index.html'), big)
    writeFileSync(join(dir, 'assets', 'app-abc.js'), big)

    const summary = await precompress(dir)

    expect(summary.compressed).toBe(2)
    expect(existsSync(join(dir, 'index.html.gz'))).toBe(true)
    const gz = readFileSync(join(dir, 'assets', 'app-abc.js.gz'))
    expect(gz.length).toBeLessThan(big.length)
    expect(gunzipSync(gz).toString()).toBe(big)
    expect(summary.gzippedBytes).toBeLessThan(summary.originalBytes)
  })

  it('skips small files and non-compressible extensions', async () => {
    writeFileSync(join(dir, 'tiny.js'), 'x'.repeat(100))
    writeFileSync(join(dir, 'assets', 'font.woff2'), big)
    writeFileSync(join(dir, 'assets', 'logo.png'), big)

    const summary = await precompress(dir)

    expect(summary.compressed).toBe(0)
    expect(summary.skipped).toBe(1)
    expect(existsSync(join(dir, 'tiny.js.gz'))).toBe(false)
    expect(existsSync(join(dir, 'assets', 'font.woff2.gz'))).toBe(false)
    expect(existsSync(join(dir, 'assets', 'logo.png.gz'))).toBe(false)
  })

  it('does not keep a .gz that is not smaller than the original', async () => {
    const random = Buffer.from(Array.from({ length: 2048 }, (_, i) => (i * 2654435761) % 251))
    // Already-compressed payload with a compressible extension: gzip cannot shrink it.
    writeFileSync(join(dir, 'blob.json'), gzipSync(random, { level: 9 }))
    writeFileSync(join(dir, 'blob.json.gz'), 'stale')

    await precompress(dir)

    expect(existsSync(join(dir, 'blob.json.gz'))).toBe(false)
  })

  it('is idempotent and never compresses .gz files themselves', async () => {
    writeFileSync(join(dir, 'style.css'), big)

    await precompress(dir)
    const first = readFileSync(join(dir, 'style.css.gz'))
    const summary = await precompress(dir)

    expect(summary.compressed).toBe(1)
    expect(readFileSync(join(dir, 'style.css.gz')).equals(first)).toBe(true)
    expect(existsSync(join(dir, 'style.css.gz.gz'))).toBe(false)
  })

  it('refreshes a stale .gz when the source changes', async () => {
    writeFileSync(join(dir, 'app.js'), big)
    await precompress(dir)

    const updated = big + 'console.log("changed");\n'
    writeFileSync(join(dir, 'app.js'), updated)
    await precompress(dir)

    expect(gunzipSync(readFileSync(join(dir, 'app.js.gz'))).toString()).toBe(updated)
  })
})
