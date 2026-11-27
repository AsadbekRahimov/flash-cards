import { describe, expect, it } from 'vitest'
import { greetingFit, wordEm } from '../greetingFit'

/*
 * The greeting of "Today" beside the stamp (T16): a long word gets a smaller size, so the heading wraps between words only. The column is
 * 176 px on a 320 px phone and from 216 px on a wider one; the widths of Unbounded 600 were measured in the browser (per word, at 1 px):
 * «Привет,» 4.80, «Мухаммадали!» 9.68, «Абдурахмонбек!» 10.46 - the estimate must never be smaller than that.
 */
describe('greetingFit', () => {
  it('never estimates a measured word narrower than it is', () => {
    expect(wordEm('Привет,')).toBeGreaterThanOrEqual(4.8)
    expect(wordEm('Мухаммадали!')).toBeGreaterThanOrEqual(9.68)
    expect(wordEm('Абдурахмонбек!')).toBeGreaterThanOrEqual(10.46)
    expect(wordEm('Muhammadali!')).toBeGreaterThanOrEqual(9.08)
    expect(wordEm('Шшшшшш')).toBeGreaterThanOrEqual(7.03)
  })

  it('keeps the size of the kit for a short name, on both widths', () => {
    expect(greetingFit('Привет, Ali!')).toEqual({ narrow: 'l', wide: 'l' })
    expect(greetingFit('Salom, Aziz!')).toEqual({ narrow: 'l', wide: 'l' })
  })

  it('steps down by the longest word, more on a 320 px phone', () => {
    expect(greetingFit('Привет, Александра!')).toEqual({ narrow: 's', wide: 'm' })
    expect(greetingFit('Привет, Мухаммадали!')).toEqual({ narrow: 'xs', wide: 's' })
    expect(greetingFit('Привет, Абдурахмонбек!')).toEqual({ narrow: 'xxs', wide: 'xs' })
  })

  it('looks at the longest word only: a long name of short words keeps a big size', () => {
    expect(greetingFit('Привет, Анна Мария Ли!')).toEqual({ narrow: 'l', wide: 'l' })
  })
})
