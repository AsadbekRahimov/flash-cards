import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LessonSkeleton from '../LessonSkeleton.vue'
import LessonWord from '../LessonWord.vue'

describe('LessonWord', () => {
  const sizeOf = (text: string, props: { phrase?: boolean; compact?: boolean } = {}) =>
    mount(LessonWord, { props: { text, ...props } }).get('h2').classes()

  it('is the Word/XL of the kit for a short word: Unbounded 600, 44 px, wrapping by words; attributes go to the heading', () => {
    const w = mount(LessonWord, { props: { text: 'tea' }, attrs: { lang: 'en', 'data-testid': 'word' } })
    const heading = w.get('[data-testid=word]')
    expect(heading.element.tagName).toBe('H2')
    expect(heading.text()).toBe('tea')
    expect(heading.attributes('lang')).toBe('en')
    expect(heading.classes()).toEqual(expect.arrayContaining(['font-display', 'font-semibold', 'text-[44px]', 'leading-[50px]', 'break-words', 'max-w-full']))
    // a step down on a short window
    expect(heading.classes()).toContain('short:text-[38px]')
  })

  it('steps the size down with the length, so that a long word or phrase fits the line of 320 px', () => {
    expect(sizeOf('environment')).toContain('text-[36px]')
    expect(sizeOf('responsibilities')).toContain('text-[30px]')
    expect(sizeOf('to take something for granted')).toContain('text-[26px]')
    expect(sizeOf('No worries', { phrase: true })).toContain('text-[30px]')
    expect(sizeOf('Easy come, easy go, they say', { phrase: true })).toContain('text-[24px]')
    expect(sizeOf('x'.repeat(100), { phrase: true })).toContain('text-[18px]')
  })

  it('is one step smaller once the answer is in (compact)', () => {
    expect(sizeOf('tea', { compact: true })).toContain('text-[36px]')
    expect(sizeOf('tea', { compact: true })).not.toContain('text-[44px]')
    expect(sizeOf('x'.repeat(100), { phrase: true, compact: true })).toContain('text-[17px]')
  })
})

describe('LessonSkeleton', () => {
  it('is a status with the skeleton of a question: the mascot place (not on a short window), the word and four answers', () => {
    const w = mount(LessonSkeleton)
    const root = w.get('[data-testid=spinner]')
    expect(root.attributes('role')).toBe('status')
    expect(root.attributes('aria-busy')).toBe('true')
    expect(root.get('.sr-only').text()).toBe('Загрузка…')
    expect(w.find('.skeleton.rounded-full').classes()).toContain('short:hidden')
    expect(w.findAll('.skeleton.h-\\[60px\\]')).toHaveLength(4)
  })

  it('draws as many answers as asked, none for 0', () => {
    expect(mount(LessonSkeleton, { props: { options: 3 } }).findAll('.skeleton.h-\\[60px\\]')).toHaveLength(3)
    expect(mount(LessonSkeleton, { props: { options: 0 } }).findAll('.skeleton.h-\\[60px\\]')).toHaveLength(0)
  })
})
