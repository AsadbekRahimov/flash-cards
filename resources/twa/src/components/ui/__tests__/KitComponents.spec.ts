import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppButton from '../AppButton.vue'
import AppChip from '../AppChip.vue'
import AppIcon from '../AppIcon.vue'
import IconButton from '../IconButton.vue'
import PlanStep from '../PlanStep.vue'
import SegmentProgress from '../SegmentProgress.vue'
import AchievementBadge from '../AchievementBadge.vue'
import TopicArt from '../TopicArt.vue'
import MascotArt from '../MascotArt.vue'
import { achievementArtImage, mascotImage, topicImage } from '@/composables/illustrations'

describe('AppButton', () => {
  it('is the ink .btn by default, with the arrow of the kit on request', async () => {
    const w = mount(AppButton, { props: { icon: true, block: true }, slots: { default: 'Начать урок' } })
    const button = w.get('button')

    expect(button.attributes('type')).toBe('button')
    expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-block']))
    expect(button.text()).toBe('Начать урок')
    expect(w.findComponent(AppIcon).props('name')).toBe('arrow-right')
    await button.trigger('click')
    expect(w.emitted('click')).toHaveLength(1)
  })

  it.each(['secondary', 'ghost'] as const)('has the %s variant', (variant) => {
    expect(mount(AppButton, { props: { variant } }).get('button').classes()).toContain(`btn-${variant}`)
  })

  it('shows three dots while loading, is busy and takes no press', async () => {
    const w = mount(AppButton, { props: { loading: true }, slots: { default: 'Сохранить' } })

    expect(w.get('button').attributes('aria-busy')).toBe('true')
    expect(w.get('[data-testid=btn-dots]').findAll('span:not(.sr-only)')).toHaveLength(3)
    expect(w.text()).not.toContain('Сохранить')
    await w.get('button').trigger('click')
    expect(w.emitted('click')).toBeUndefined()
  })

  it('is disabled with aria-disabled and takes no press', async () => {
    const w = mount(AppButton, { props: { disabled: true } })
    expect(w.get('button').attributes('aria-disabled')).toBe('true')
    expect(w.get('button').attributes('disabled')).toBeDefined()
  })
})

describe('IconButton', () => {
  it('is a 44 px circle with a 20 px icon and its name in aria-label', async () => {
    const w = mount(IconButton, { props: { icon: 'speaker-wave', label: 'Прослушать слово' } })
    const button = w.get('button')

    expect(button.classes()).toContain('icon-btn')
    expect(button.attributes('aria-label')).toBe('Прослушать слово')
    expect(w.findComponent(AppIcon).props()).toMatchObject({ name: 'speaker-wave', size: 20 })
    await button.trigger('click')
    expect(w.emitted('click')).toHaveLength(1)
  })

  it('takes no press while disabled', async () => {
    const w = mount(IconButton, { props: { icon: 'x-mark', label: 'x', disabled: true } })
    await w.get('button').trigger('click')
    expect(w.emitted('click')).toBeUndefined()
  })
})

describe('AppChip', () => {
  it.each(['neutral', 'accent', 'success', 'ink'] as const)('has the %s tone', (tone) => {
    const chip = mount(AppChip, { props: { tone }, slots: { default: 'A1' } }).get('span')
    expect(chip.classes()).toEqual(['chip', `chip-${tone}`])
    expect(chip.text()).toBe('A1')
  })
})

describe('PlanStep', () => {
  it('draws a done step with a check on the success circle', () => {
    const w = mount(PlanStep, { props: { state: 'done', number: 1, title: 'Урок', meta: '5 новых слов' } })
    const circle = w.get('[data-testid=plan-step-circle]')
    expect(circle.classes()).toEqual(expect.arrayContaining(['bg-success', 'text-on-primary', 'size-7']))
    expect(w.findComponent(AppIcon).props('name')).toBe('check')
    expect(w.text()).toContain('5 новых слов')
  })

  it('draws the current step with its number on the muhr circle, an upcoming one outlined and muted', () => {
    const current = mount(PlanStep, { props: { state: 'current', number: 2, title: 'Тренировка' } })
    expect(current.get('[data-testid=plan-step-circle]').classes()).toEqual(expect.arrayContaining(['bg-muhr', 'text-on-muhr']))
    expect(current.get('[data-testid=plan-step-circle]').text()).toBe('2')

    const upcoming = mount(PlanStep, { props: { state: 'upcoming', number: 3, title: 'Игра' } })
    expect(upcoming.get('[data-testid=plan-step-circle]').text()).toBe('3')
    expect(upcoming.get('[data-testid=plan-step-circle]').classes()).toContain('text-muted')
    expect(upcoming.findComponent(AppIcon).exists()).toBe(false)
  })
})

describe('SegmentProgress', () => {
  it('marks the passed segments in ink, the current one in muhr, the rest as to do', () => {
    const w = mount(SegmentProgress, { props: { total: 5, current: 2, label: 'Прогресс урока' } })
    expect(w.findAll('.segment').map((s) => s.attributes('data-state'))).toEqual(['done', 'done', 'current', 'todo', 'todo'])
    expect(w.get('[data-testid=segments]').attributes()).toMatchObject({ role: 'progressbar', 'aria-valuenow': '2', 'aria-valuemax': '5' })
  })

  it('clamps the current segment and is decoration without a label', () => {
    const w = mount(SegmentProgress, { props: { total: 3, current: 9 } })
    expect(w.findAll('.segment').map((s) => s.attributes('data-state'))).toEqual(['done', 'done', 'done'])
    expect(w.get('[data-testid=segments]').attributes('aria-hidden')).toBe('true')
  })
})

describe('AchievementBadge', () => {
  it('shows the coloured art, the grey one when locked, and a bar in progress', () => {
    const unlocked = mount(AchievementBadge, { props: { art: 'streak-7', state: 'unlocked', title: '7 дней' } })
    expect(unlocked.get('img').attributes('src')).toBe(achievementArtImage('streak-7'))
    expect(unlocked.get('img').attributes('alt')).toBe('')

    const locked = mount(AchievementBadge, { props: { art: 'streak-7', state: 'locked', title: '7 дней' } })
    expect(locked.get('img').attributes('src')).toBe(achievementArtImage('streak-7', true))

    const progress = mount(AchievementBadge, { props: { art: 'words-100', state: 'progress', title: '100 слов', progress: 140 } })
    expect(progress.get('[data-testid=achievement-progress] .progress-fill').attributes('style')).toContain('width: 100%')
  })

  it('draws the fallback slot for an achievement without a picture of the kit', () => {
    const w = mount(AchievementBadge, { props: { state: 'unlocked', title: '30 дней' }, slots: { fallback: '<i data-testid="own" />' } })
    expect(w.find('img').exists()).toBe(false)
    expect(w.find('[data-testid=own]').exists()).toBe(true)
  })
})

describe('TopicArt', () => {
  it('is a lazy, decorative picture of the module with both sizes', () => {
    const img = mount(TopicArt, { props: { course: 'english-a1-a2', stage: 3, size: 56 } }).get('img')

    expect(img.attributes()).toMatchObject({ alt: '', loading: 'lazy', width: '56', height: '56', sizes: '56px' })
    expect(img.attributes('src')).toBe(topicImage('english-a1-a2', 3, 120))
    expect(img.attributes('srcset')).toBe(`${topicImage('english-a1-a2', 3, 120)} 120w, ${topicImage('english-a1-a2', 3, 240)} 240w`)
  })

  it('shows the fallback slot for a course without pictures, nothing without one', () => {
    const fallback = mount(TopicArt, { props: { course: 'my-deck', stage: 3 }, slots: { fallback: '<b data-testid="own">M</b>' } })
    expect(fallback.find('img').exists()).toBe(false)
    expect(fallback.find('[data-testid=own]').exists()).toBe(true)

    expect(mount(TopicArt, { props: { course: 'english-b2', stage: null } }).html()).toBe('<!--v-if-->')
  })
})

describe('MascotArt', () => {
  it('draws the pose at the given height, the width in its proportions', () => {
    const img = mount(MascotArt, { props: { pose: 'reading', height: 110 } }).get('img')

    expect(img.attributes('src')).toBe(mascotImage('reading'))
    expect(img.attributes()).toMatchObject({ alt: '', height: '110', width: String(Math.round((291 / 440) * 110)), 'data-pose': 'reading' })
  })
})
