import { afterEach, describe, expect, it } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { getLocale, initLocale, resolveLocale, setLocale, t, tn } from '../index'
import { ru } from '../ru'
import { uz } from '../uz'
import { ApiClientError } from '@/api/client'
import { errorMessage, KNOWN_ERROR_CODES } from '../errors'

afterEach(() => {
  setLocale('ru')
  document.documentElement.lang = ''
})

describe('resolveLocale', () => {
  it.each([
    ['uz', 'uz'],
    ['uz-UZ', 'uz'],
    ['UZ', 'uz'],
    ['uz_Latn', 'uz'],
    ['ru', 'ru'],
    ['ru-RU', 'ru'],
    ['en', 'ru'],
    ['de-AT', 'ru'],
    ['', 'ru'],
    [null, 'ru'],
    [undefined, 'ru'],
  ])('%s -> %s', (code, expected) => {
    expect(resolveLocale(code)).toBe(expected)
  })

  it('initLocale applies the locale and sets <html lang>', () => {
    expect(initLocale('uz-UZ')).toBe('uz')
    expect(getLocale()).toBe('uz')
    expect(document.documentElement.lang).toBe('uz')

    expect(initLocale(undefined)).toBe('ru')
    expect(getLocale()).toBe('ru')
    expect(document.documentElement.lang).toBe('ru')
  })
})

describe('t', () => {
  it('returns the string for the active locale', () => {
    expect(t('card.grade.hard')).toBe('Не помню')
    setLocale('uz')
    expect(t('card.grade.hard')).toBe('Eslay olmadim')
  })

  it('substitutes {name} params and leaves unknown placeholders intact', () => {
    expect(t('training.lesson_header', { stage: 2, lesson: 5 })).toBe('Stage 2 · Lesson 5')
    expect(t('training.lesson_header', { stage: 2 })).toBe('Stage 2 · Lesson {lesson}')
    expect(t('result.rank', { rank: 1, total: 7 })).toBe('Место 1 из 7')
    setLocale('uz')
    expect(t('result.rank', { rank: 1, total: 7 })).toBe("7 ishtirokchi ichida 1-o'rin")
  })
})

describe('tn (plural forms)', () => {
  it.each([
    [0, 'Вы повторили 0 слов.'],
    [1, 'Вы повторили 1 слово.'],
    [2, 'Вы повторили 2 слова.'],
    [4, 'Вы повторили 4 слова.'],
    [5, 'Вы повторили 5 слов.'],
    [11, 'Вы повторили 11 слов.'],
    [12, 'Вы повторили 12 слов.'],
    [21, 'Вы повторили 21 слово.'],
    [22, 'Вы повторили 22 слова.'],
    [25, 'Вы повторили 25 слов.'],
    [101, 'Вы повторили 101 слово.'],
    [111, 'Вы повторили 111 слов.'],
  ])('ru %i', (count, expected) => {
    expect(tn('finished.reviewed', count)).toBe(expected)
  })

  it.each([
    [1, 'Через 1 день'],
    [2, 'Через 2 дня'],
    [5, 'Через 5 дней'],
    [11, 'Через 11 дней'],
    [21, 'Через 21 день'],
    [22, 'Через 22 дня'],
  ])('ru days %i', (count, expected) => {
    expect(tn('finished.next_in_days', count)).toBe(expected)
  })

  it.each([1, 2, 5, 11, 21, 22, 25])('uz %i has no noun inflection', (count) => {
    setLocale('uz')
    expect(tn('finished.reviewed', count)).toBe(`Siz ${count} ta so'zni takrorladingiz.`)
    expect(tn('finished.next_in_days', count)).toBe(`${count} kundan keyin`)
  })
})

describe('dictionaries', () => {
  it('uz has exactly the same keys as ru', () => {
    expect(Object.keys(uz).sort()).toEqual(Object.keys(ru).sort())
  })

  it('keeps string / plural kind and placeholders aligned between locales', () => {
    const placeholders = (s: string): string[] => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1] ?? '').sort()
    const texts = (v: string | Record<string, string | undefined>): string[] =>
      typeof v === 'string' ? [v] : Object.values(v).filter((x): x is string => typeof x === 'string')

    for (const key of Object.keys(ru) as (keyof typeof ru)[]) {
      const r = ru[key] as string | Record<string, string | undefined>
      const u = uz[key] as string | Record<string, string | undefined>
      expect(typeof u, key).toBe(typeof r)
      const ruPlaceholders = placeholders(texts(r)[0] ?? '')
      for (const text of [...texts(r), ...texts(u)]) {
        expect(placeholders(text), key).toEqual(ruPlaceholders)
      }
    }
  })

  it('ru plurals define one/few/many and uz plurals define one/other', () => {
    for (const key of Object.keys(ru) as (keyof typeof ru)[]) {
      const r = ru[key]
      if (typeof r === 'string') continue
      expect(Object.keys(r), key).toEqual(expect.arrayContaining(['one', 'few', 'many', 'other']))
      expect(Object.keys(uz[key] as object), key).toEqual(expect.arrayContaining(['one', 'other']))
    }
  })

  it('has no empty strings and uz is Latin script with the ASCII apostrophe', () => {
    for (const [key, value] of Object.entries(uz)) {
      const all = typeof value === 'string' ? [value] : Object.values(value)
      for (const text of all) {
        expect(text.trim(), key).not.toBe('')
        expect(/\p{Script=Cyrillic}/u.test(text), `Cyrillic in uz: ${key}`).toBe(false)
        expect(/[‘’ʻʼ`´]/.test(text), `non-ASCII apostrophe in uz: ${key}`).toBe(false)
      }
    }
  })
})

describe('errorMessage', () => {
  const err = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string) =>
    new ApiClientError(kind, status, code, 'English backend message')

  it('explains every known backend error.code in both languages', () => {
    for (const locale of ['ru', 'uz'] as const) {
      setLocale(locale)
      const generic = t('error.generic')
      for (const code of KNOWN_ERROR_CODES) {
        const text = errorMessage(err('conflict', 409, code), 'training')
        expect(text, `${locale}:${code}`).not.toContain('English backend message')
        expect(text, `${locale}:${code}`).not.toBe(generic)
        expect(text, `${locale}:${code}`).not.toMatch(/^error\./)
      }
    }
  })

  it('explains a 401 missing_token (TwaAuth) in both languages', () => {
    expect(KNOWN_ERROR_CODES).toContain('missing_token')
    expect(errorMessage(err('unknown', 401, 'missing_token'), 'training')).toBe(
      'Вы не авторизованы. Откройте приложение заново из Telegram.',
    )
    setLocale('uz')
    expect(errorMessage(err('unknown', 401, 'missing_token'), 'exam')).toBe(
      'Siz tizimga kirmagansiz. Ilovani Telegram orqali qaytadan oching.',
    )
  })

  it('never shows the English server message', () => {
    expect(errorMessage(err('unknown', 418, 'something_new'), 'exam')).toBe('Что-то пошло не так.')
    expect(errorMessage(err('server', 500, 'internal'), 'exam')).toBe('Сервер временно недоступен. Попробуйте позже.')
    expect(errorMessage(err('rate_limited', 429, 'x'), 'exam')).toBe(t('error.rate_limited'))
  })

  it('uses context-specific texts for gone / forbidden', () => {
    expect(errorMessage(err('gone', 410, 'session_closed'), 'training')).toBe('Эта тренировка уже закрыта: она завершена или время сессии истекло.')
    expect(errorMessage(err('gone', 410, 'session_closed'), 'exam')).toBe('Экзамен был завершён учителем.')
    expect(errorMessage(err('forbidden', 403, 'forbidden'), 'training')).toBe('У вас нет доступа к этой тренировке.')
    expect(errorMessage(err('forbidden', 403, 'forbidden'), 'exam')).toBe('У вас нет доступа к этому экзамену.')
    setLocale('uz')
    expect(errorMessage(err('forbidden', 403, 'forbidden'), 'exam')).toBe("Sizda bu imtihonga kirish huquqi yo'q.")
  })

  it('prefers the specific code over the generic 403 text (group_inactive)', () => {
    expect(errorMessage(err('forbidden', 403, 'group_inactive'), 'training')).toBe(
      'Группа не активирована. Обратитесь к учителю.',
    )
  })

  it('handles network, outside-Telegram and unexpected errors', () => {
    expect(errorMessage(err('network', 0, 'unknown'), 'exam')).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(errorMessage(new Error('opened_outside_telegram'), 'exam')).toBe('Откройте приложение через Telegram.')
    expect(errorMessage(new Error('boom'), 'exam')).toBe('Непредвиденная ошибка.')
    expect(errorMessage('string', 'exam')).toBe('Непредвиденная ошибка.')
  })
})

describe('no hard-coded Russian outside the dictionaries', () => {
  // vitest runs with cwd = resources/twa
  const srcRoot = join(process.cwd(), 'src')

  function sourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
      const full = join(dir, name)
      if (statSync(full).isDirectory()) {
        return name === 'i18n' || name === '__tests__' ? [] : sourceFiles(full)
      }
      return /\.(vue|ts)$/.test(name) ? [full] : []
    })
  }

  const stripComments = (code: string): string =>
    code
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/<!--[\s\S]*?-->/g, '')
      .replace(/(^|\s)\/\/.*$/gm, '$1')

  it('keeps Cyrillic text in src/i18n only (comments excluded)', () => {
    const files = sourceFiles(srcRoot)
    expect(files.length).toBeGreaterThan(10)

    for (const file of files) {
      const code = stripComments(readFileSync(file, 'utf8'))
      expect(/\p{Script=Cyrillic}/u.test(code), `hard-coded Cyrillic in ${file}`).toBe(false)
    }
  })
})
