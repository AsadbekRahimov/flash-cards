import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '../index'
import { ApiClientError } from '@/api/client'
import { errorMessage, KNOWN_ERROR_CODES } from '../errors'

afterEach(() => {
  setLocale('ru')
})

/** 409 `lesson_review_unavailable` of `POST /training/sessions {mode: lesson_review}` (docs/04 A.3), with `details.reason` or without it. */
const refused = (reason: unknown) =>
  new ApiClientError('conflict', 409, 'lesson_review_unavailable', 'English backend message', undefined, reason === undefined ? null : { reason })

describe('the refusal of the review of a lesson (docs/06 §15)', () => {
  it('is a known code', () => {
    expect(KNOWN_ERROR_CODES).toContain('lesson_review_unavailable')
  })

  it('is told by its reason in Russian; an unknown or missing reason gets the general words', () => {
    expect(errorMessage(refused('no_new_words'), 'training')).toBe('Новых слов этого урока сегодня не было — закреплять пока нечего.')
    expect(errorMessage(refused('no_objective_exercise'), 'training')).toBe('Для слов этого урока пока нет подходящих заданий.')
    expect(errorMessage(refused('disabled'), 'training')).toBe('Тренировка по словам урока сейчас недоступна.')
    expect(errorMessage(refused('weird'), 'training')).toBe('Тренировка по словам урока сейчас недоступна.')
    expect(errorMessage(refused(undefined), 'training')).toBe('Тренировка по словам урока сейчас недоступна.')
  })

  it('is told by its reason in Uzbek', () => {
    setLocale('uz')
    expect(errorMessage(refused('no_new_words'), 'training')).toBe("Bugun bu darsda yangi so'zlar bo'lmadi — mustahkamlash uchun hozircha hech narsa yo'q.")
    expect(errorMessage(refused('no_objective_exercise'), 'training')).toBe("Bu dars so'zlari uchun hozircha mos topshiriqlar yo'q.")
    expect(errorMessage(refused('disabled'), 'training')).toBe("Dars so'zlari bo'yicha mashq hozir mavjud emas.")
    expect(errorMessage(refused(null), 'training')).toBe("Dars so'zlari bo'yicha mashq hozir mavjud emas.")
  })
})
