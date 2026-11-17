import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TimedBar from '../TimedBar.vue'
import { setLocale } from '@/i18n'

const NOW = Date.parse('2026-10-30T10:00:00Z')

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

const render = (props: Partial<InstanceType<typeof TimedBar>['$props']> = {}) =>
  mount(TimedBar, { props: { deadline: NOW + 60_000, seconds: 60, score: 0, penalty: { seq: 0, ms: 0 }, ...props } })

const seconds = (wrapper: ReturnType<typeof render>) => wrapper.get('[data-testid=timed-seconds]').text()

describe('TimedBar', () => {
  it('counts the seconds down from the deadline by the local clock', async () => {
    const wrapper = render()
    expect(seconds(wrapper)).toBe('60')

    await vi.advanceTimersByTimeAsync(12_300)
    expect(seconds(wrapper)).toBe('48')
    expect(wrapper.get('[data-testid=timed-bar]').attributes('data-warning')).toBe('false')
  })

  it('shows the score and, in the last ten seconds, the warning', async () => {
    const wrapper = render({ score: 7, deadline: NOW + 10_000 })
    await vi.advanceTimersByTimeAsync(300)

    expect(wrapper.get('[data-testid=timed-score]').text()).toBe('Пар: 7')
    expect(wrapper.get('[data-testid=timed-bar]').attributes('data-warning')).toBe('true')
  })

  it('says expired once at zero, and again for a new deadline', async () => {
    const wrapper = render({ deadline: NOW + 1_000 })

    await vi.advanceTimersByTimeAsync(1_500)
    await vi.advanceTimersByTimeAsync(1_000)
    expect(wrapper.emitted('expired')).toHaveLength(1)
    expect(seconds(wrapper)).toBe('0')

    await wrapper.setProps({ deadline: Date.now() + 500 })
    await vi.advanceTimersByTimeAsync(1_000)
    expect(wrapper.emitted('expired')).toHaveLength(2)
  })

  it('does not run a clock before the start', async () => {
    const wrapper = render({ deadline: null })
    await vi.advanceTimersByTimeAsync(5_000)

    expect(seconds(wrapper)).toBe('60')
    expect(wrapper.emitted('expired')).toBeUndefined()
  })

  it('flashes "−2 с" for a wrong match, then lets it go', async () => {
    const wrapper = render()
    expect(wrapper.find('[data-testid=timed-penalty]').exists()).toBe(false)

    await wrapper.setProps({ penalty: { seq: 1, ms: 2000 } })
    expect(wrapper.get('[data-testid=timed-penalty]').text()).toBe('−2 с')

    await vi.advanceTimersByTimeAsync(1_100)
    expect(wrapper.find('[data-testid=timed-penalty]').exists()).toBe(false)
  })

  it('recounts when the page comes back to the front', async () => {
    const wrapper = render({ deadline: NOW + 30_000 })
    vi.setSystemTime(NOW + 25_000) // timers slept
    document.dispatchEvent(new Event('visibilitychange'))
    await wrapper.vm.$nextTick()

    expect(seconds(wrapper)).toBe('5')
  })
})
