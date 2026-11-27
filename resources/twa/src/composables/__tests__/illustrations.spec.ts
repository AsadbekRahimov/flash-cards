import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  ACHIEVEMENT_ARTS,
  ACHIEVEMENT_ART_BY_KEY,
  ILLUSTRATIONS_VERSION,
  MASCOT_POSES,
  MASCOT_SIZES,
  TOPIC_COURSES,
  TOPIC_MODULES,
  achievementArtImage,
  achievementImage,
  illustrationsBase,
  mascotImage,
  topicImage,
  topicImageSet,
} from '../illustrations'

// vitest runs with cwd = resources/twa; Vite copies public/ as it is into the build, under the base
const publicRoot = join(process.cwd(), 'public')

/** The file on disk an URL of the app points at (the base of Vite stripped). */
function fileOf(url: string): string {
  expect(url.startsWith(illustrationsBase())).toBe(true)
  return join(publicRoot, 'assets', 'img', ILLUSTRATIONS_VERSION, url.slice(illustrationsBase().length))
}

/** Width and height of a WebP file (VP8 / VP8L / VP8X headers). */
function webpSize(file: string): { width: number; height: number } {
  const b = readFileSync(file)
  expect(b.toString('latin1', 0, 4)).toBe('RIFF')
  expect(b.toString('latin1', 8, 12)).toBe('WEBP')
  const chunk = b.toString('latin1', 12, 16)
  if (chunk === 'VP8X') return { width: 1 + b.readUIntLE(24, 3), height: 1 + b.readUIntLE(27, 3) }
  if (chunk === 'VP8L') {
    const bits = b.readUInt32LE(21)
    return { width: (bits & 0x3fff) + 1, height: ((bits >> 14) & 0x3fff) + 1 }
  }
  return { width: b.readUInt16LE(26) & 0x3fff, height: b.readUInt16LE(28) & 0x3fff }
}

describe('topicImage', () => {
  it('has both files of every module of the six platform courses on disk, 120 and 240 px', () => {
    expect(TOPIC_COURSES).toEqual(['english-a1-a2', 'english-b1', 'english-b2', 'english-c1', 'english-c2', 'english-phrases'])
    for (const course of TOPIC_COURSES) {
      for (let stage = 1; stage <= TOPIC_MODULES; stage++) {
        for (const size of [120, 240] as const) {
          const url = topicImage(course, stage, size)
          expect(url, `${course} ${stage} ${size}`).not.toBeNull()
          const file = fileOf(url ?? '')
          expect(existsSync(file), file).toBe(true)
          expect(webpSize(file), file).toEqual({ width: size, height: size })
        }
      }
    }
  })

  it('builds the URL from the slug and the two-digit module number, under the base of the app and the version folder', () => {
    expect(topicImage('english-a1-a2', 3)).toBe(`${import.meta.env.BASE_URL}assets/img/m1/topics/english-a1-a2/03-240.webp`)
    expect(topicImage('english-phrases', 20, 120)).toBe(`${import.meta.env.BASE_URL}assets/img/m1/topics/english-phrases/20-120.webp`)
  })

  it.each([
    ['an unknown course', 'ielts-core', 1],
    ['a deck', 'my-words', 1],
    ['no slug', null, 1],
    ['an undefined slug', undefined, 1],
    ['no module', 'english-b1', null],
    ['module 0', 'english-b1', 0],
    ['module 21', 'english-b1', 21],
    ['a fraction', 'english-b1', 2.5],
  ])('is null for %s', (_, course, stage) => {
    expect(topicImage(course, stage)).toBeNull()
    expect(topicImageSet(course, stage)).toBeNull()
  })

  it('gives an <img> both sizes as a srcset', () => {
    expect(topicImageSet('english-c1', 7)).toEqual({
      src: topicImage('english-c1', 7, 120),
      srcset: `${topicImage('english-c1', 7, 120)} 120w, ${topicImage('english-c1', 7, 240)} 240w`,
    })
  })

  it('keeps the pictures out of the JS bundle: public files, no import of them from the sources', () => {
    const source = readFileSync(join(process.cwd(), 'src', 'composables', 'illustrations.ts'), 'utf8')
    expect(source).not.toMatch(/import\s.*\.webp|import\.meta\.glob/)
  })
})

describe('the mascot and the achievements', () => {
  it('has every pose on disk, 440 px high, with the size the component uses for width / height', () => {
    expect(MASCOT_POSES).toEqual(['hello', 'thinking', 'celebrate', 'freeze', 'trophy', 'reading'])
    for (const pose of MASCOT_POSES) {
      const file = fileOf(mascotImage(pose))
      expect(existsSync(file), file).toBe(true)
      expect(webpSize(file), pose).toEqual(MASCOT_SIZES[pose])
    }
  })

  it('has every achievement of the kit on disk, coloured and locked, 256 px', () => {
    for (const art of ACHIEVEMENT_ARTS) {
      for (const locked of [false, true]) {
        const file = fileOf(achievementArtImage(art, locked))
        expect(existsSync(file), file).toBe(true)
        expect(webpSize(file)).toEqual({ width: 256, height: 256 })
      }
    }
  })

  it('maps the keys of the app whose meaning the kit has a picture for, and nothing else', () => {
    expect(ACHIEVEMENT_ART_BY_KEY).toEqual({ first_session: 'first-lesson', streak_7: 'streak-7', words_100: 'words-100', league_top1: 'league' })
    expect(achievementImage('streak_7')).toBe(achievementArtImage('streak-7'))
    expect(achievementImage('streak_7', true)).toBe(achievementArtImage('streak-7', true))
    expect(achievementImage('streak_30')).toBeNull()
    expect(achievementImage('game_first_boss')).toBeNull()
  })

  it('adds nothing but the pictures the app names to the folder (no stray files go into the build)', () => {
    const root = join(publicRoot, 'assets', 'img', ILLUSTRATIONS_VERSION)
    const count = (dir: string): number =>
      readdirSync(dir).reduce((sum, name) => sum + (statSync(join(dir, name)).isDirectory() ? count(join(dir, name)) : 1), 0)
    expect(count(root)).toBe(TOPIC_COURSES.length * TOPIC_MODULES * 2 + MASCOT_POSES.length + ACHIEVEMENT_ARTS.length * 2)
  })
})
