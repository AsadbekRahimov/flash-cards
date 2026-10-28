import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import GoalRing from '../GoalRing.vue'

const ring = (done: number, target: number, met = false) => mount(GoalRing, { props: { done, target, met } })

/** The part of the circumference that is NOT drawn, 0..1 (0 = a full ring). */
function hidden(w: ReturnType<typeof ring>): number {
  const arc = w.get('[data-testid=goal-ring-arc]')
  return Number(arc.attributes('stroke-dashoffset')) / Number(arc.attributes('stroke-dasharray'))
}

describe('GoalRing', () => {
  it('draws the share of the goal that is done and shows the numbers in the middle', () => {
    const w = ring(8, 20)

    expect(hidden(w)).toBeCloseTo(0.6, 3)
    expect(w.get('[data-testid=goal-done]').text()).toBe('8')
    expect(w.text()).toContain('/ 20')
    expect(w.get('[data-testid=goal-ring]').attributes('data-met')).toBe('false')
  })

  it('draws a full ring for a met goal, also when more than the target was done or the target is odd', () => {
    expect(hidden(ring(25, 20, true))).toBeCloseTo(0, 3)
    expect(hidden(ring(7, 7, true))).toBeCloseTo(0, 3)
    expect(ring(25, 20, true).get('[data-testid=goal-ring]').attributes('data-met')).toBe('true')
  })

  it('never draws more than the full ring', () => {
    expect(hidden(ring(30, 20))).toBeCloseTo(0, 3)
  })

  it('draws no arc while nothing is done or there is no target: a round cap would leave a dot', () => {
    expect(ring(0, 20).find('[data-testid=goal-ring-arc]').exists()).toBe(false)
    expect(ring(3, 0).find('[data-testid=goal-ring-arc]').exists()).toBe(false)
    expect(ring(0, 20).get('[data-testid=goal-done]').text()).toBe('0')
  })

  it('uses the theme tokens only: the accent arc turns green when met, the track is the track colour', () => {
    const open = ring(8, 20)
    expect(open.get('[data-testid=goal-ring-arc]').classes()).toContain('stroke-accent')
    expect(open.get('[data-testid=goal-ring-arc]').classes()).not.toContain('stroke-success')
    expect(open.get('circle').classes()).toContain('stroke-track')

    expect(ring(20, 20, true).get('[data-testid=goal-ring-arc]').classes()).toContain('stroke-success')
  })

  it('is 88 px wide', () => {
    expect(ring(8, 20).get('[data-testid=goal-ring]').classes()).toContain('size-22')
  })
})
