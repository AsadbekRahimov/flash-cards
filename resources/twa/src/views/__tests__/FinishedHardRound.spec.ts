import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import FinishedView from '../FinishedView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import type { HardRound, SessionSummary } from '@/api/types'

vi.mock('@/api', () => ({
  api: { achievements: { list: vi.fn() }, league: { monthMe: vi.fn() }, me: { get: vi.fn() }, today: { get: vi.fn() }, training: { open: vi.fn(), finish: vi.fn() } },
}))

const sound = vi.hoisted(() => ({ playSound: vi.fn() }))
vi.mock('@/composables/useFeedbackSound', () => ({ playSound: sound.playSound }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ close: vi.fn(), notificationHaptic: vi.fn() }) }))

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'landing', component: { template: '<div />' } },
    { path: '/today', name: 'today', component: { template: '<div />' } },
    { path: '/league/month', name: 'league-month', component: { template: '<div />' } },
    { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
    { path: '/achievements', name: 'achievements', component: { template: '<div />' } },
    { path: '/courses', name: 'courses', component: { template: '<div />' } },
    { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
  ],
})

const offer = (overrides: Partial<HardRound> = {}): HardRound => ({ available: true, reason: null, words: 9, open_session_id: null, resets_at: null, ...overrides })

const summary = (overrides: Partial<SessionSummary> = {}): SessionSummary => ({
  reviewed: 5,
  correct: 4,
  new_words: 0,
  goal: { target: 20, done: 12, met: false },
  streak: { current: 3, best: 7, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
  ...overrides,
})

function reduceMotion(on: boolean): void {
  vi.stubGlobal('matchMedia', (query: string) => ({ matches: on && query.includes('reduce'), media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
}

function render(reason: string | null, value: SessionSummary | null, mode: 'daily_review' | 'hard_review' = 'daily_review') {
  const training = useTrainingStore()
  training.mode = mode
  training.summary = value
  return mount(FinishedView, { props: { reviewed: value?.reviewed ?? 0, nextInDays: 1, reason }, global: { plugins: [router] } })
}

describe('FinishedView: the round of hard words', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    reduceMotion(false)
    vi.mocked(api.achievements.list).mockRejectedValue(new Error('offline'))
    vi.mocked(api.league.monthMe).mockRejectedValue(new Error('offline'))
    vi.mocked(api.me.get).mockRejectedValue(new Error('offline'))
  })
  afterEach(() => {
    setLocale('ru')
    vi.unstubAllGlobals()
  })

  describe('the final of the round itself', () => {
    it('says "You won X of N" with confetti and the sound when all are won', () => {
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 10 }, practice_entry: null }), 'hard_review')

      expect(w.get('[data-testid=hard-round-title]').text()).toBe('Вы победили 10 из 10 трудных слов')
      expect(w.get('[data-testid=hard-round-note]').text()).toBe('Все трудные слова побеждены. Так держать!')
      expect(w.find('[data-testid=confetti]').exists()).toBe(true)
      expect(sound.playSound).toHaveBeenCalledWith('done')
    })

    it('celebrates 80 % too', () => {
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 8 } }), 'hard_review')

      expect(w.get('[data-testid=hard-round-title]').text()).toBe('Вы победили 8 из 10 трудных слов')
      expect(w.get('[data-testid=hard-round-note]').text()).toBe('Эти слова ещё вернутся — так и задумано')
      expect(w.find('[data-testid=confetti]').exists()).toBe(true)
      expect(sound.playSound).toHaveBeenCalledWith('done')
    })

    it('is calm below 80 %: no confetti, no sound, no shame', () => {
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 5 } }), 'hard_review')

      expect(w.get('[data-testid=hard-round-title]').text()).toBe('Вы победили 5 из 10 трудных слов')
      expect(w.get('[data-testid=hard-round-note]').text()).toBe('Эти слова ещё вернутся — так и задумано')
      expect(w.find('[data-testid=confetti]').exists()).toBe(false)
      expect(sound.playSound).not.toHaveBeenCalled()
    })

    it('does not say "won 0 of 10" when nothing was won', () => {
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 0 } }), 'hard_review')

      expect(w.get('[data-testid=hard-round-title]').text()).toBe('Раунд трудных слов пройден')
      expect(w.get('[data-testid=hard-round-note]').text()).toBe('Эти слова ещё вернутся — так и задумано')
      expect(w.find('[data-testid=confetti]').exists()).toBe(false)
    })

    it('has the usual results below, and neither "One more round", nor the practice, nor an offer', () => {
      const training = useTrainingStore()
      training.canContinue = true
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 9 }, league_points: 12, hard_round_offer: offer() }), 'hard_review')

      expect(w.find('[data-testid=stats]').exists()).toBe(true)
      expect(w.find('[data-testid=points-earned]').exists()).toBe(true)
      expect(w.find('[data-testid=streak]').exists()).toBe(true)
      expect(w.find('[data-testid=next-round-btn]').exists()).toBe(false)
      expect(w.find('[data-testid=practice-btn]').exists()).toBe(false)
      expect(w.find('[data-testid=hard-round-offer-btn]').exists()).toBe(false)
      expect(w.get('[data-testid=home-btn]').classes()).toContain('btn-primary')
    })

    it('falls back to the progress of the store when the summary did not come', () => {
      const training = useTrainingStore()
      training.progress = { done: 7, total: 10 }
      const w = render('hard_round_complete', null, 'hard_review')

      expect(w.get('[data-testid=hard-round-title]').text()).toBe('Вы победили 7 из 10 трудных слов')
    })

    it('speaks Uzbek', () => {
      setLocale('uz')
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 6 } }), 'hard_review')

      expect(w.get('[data-testid=hard-round-title]').text()).toBe("10 ta qiyin so'zdan 6 tasini yengdingiz")
    })
  })

  describe('the invitation after an ordinary round', () => {
    it('shows one line "Review N hard words" before the practice, and opens the round with source=finish', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 77, mode: 'hard_review', lesson: null, total_words: 9 })
      const w = render('sprint_complete', summary({ hard_round_offer: offer({ words: 9 }), practice_entry: { course: 'english-a1', lesson_id: null } }))

      const btn = w.get('[data-testid=hard-round-offer-btn]')
      expect(btn.text()).toBe('Разобрать 9 трудных слов')
      expect(w.findAll('[data-testid=hard-round-offer-btn]')).toHaveLength(1)
      const order = w.findAll('.btn').map((b) => b.attributes('data-testid'))
      expect(order.indexOf('hard-round-offer-btn')).toBeLessThan(order.indexOf('practice-btn'))

      await router.push('/today')
      await btn.trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'hard_review', source: 'finish' })
      expect(router.currentRoute.value.fullPath).toBe('/training/77')
    })

    it.each([
      ['not available', offer({ available: false, reason: 'played_today' })],
      ['no words', offer({ words: 0 })],
      ['absent', null],
    ])('shows nothing when the offer is %s', (_name, value) => {
      const w = render('sprint_complete', summary({ hard_round_offer: value }))

      expect(w.find('[data-testid=hard-round-offer-btn]').exists()).toBe(false)
    })

    it('shows no offer on an older server', () => {
      expect(render('sprint_complete', summary()).find('[data-testid=hard-round-offer-btn]').exists()).toBe(false)
    })

    it('tells the refusal (played in another tab) on the screen and takes the line away', async () => {
      vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('conflict', 409, 'hard_round_unavailable', 'x', undefined, { reason: 'played_today', resets_at: null }))
      vi.mocked(api.today.get).mockResolvedValue({ hard_round: offer({ available: false, reason: 'played_today' }) } as never)
      const w = render('sprint_complete', summary({ hard_round_offer: offer() }))

      await w.get('[data-testid=hard-round-offer-btn]').trigger('click')
      await flushPromises()

      expect(w.get('[data-testid=open-error]').text()).toContain('на сегодня уже пройден')
      expect(w.find('[data-testid=hard-round-offer-btn]').exists()).toBe(false)
      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(useTodayStore().data?.hard_round?.available).toBe(false)
    })

    it('keeps the ordinary final: "One more round" stays', () => {
      const training = useTrainingStore()
      training.canContinue = true
      const w = render('sprint_complete', summary({ hard_round_offer: offer() }))

      expect(w.find('[data-testid=next-round-btn]').exists()).toBe(true)
      expect(w.find('[data-testid=hard-round-title]').exists()).toBe(false)
    })
  })

  describe('the corrected mistake', () => {
    const corrected = (answer: string | null = null, translation = 'занимать') => ({ word_id: 7, word: 'borrow', translation, mistaken_at: '2026-11-13T07:30:00+00:00', answer })

    it('tells the word failed before, with its translation muted after it, and answered right today, under the note of the round', () => {
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 9 }, corrected: corrected() }), 'hard_review')

      expect(w.get('[data-testid=hard-round-corrected]').text()).toBe('Раньше вы ошибались в слове «borrow» (занимать) — сегодня ответили верно.')
      const translation = w.get('[data-testid=hard-round-corrected-translation]')
      expect(translation.text()).toBe('(занимать)')
      expect(translation.classes()).toContain('text-tg-hint')
      expect(w.find('[data-testid=hard-round-corrected-answer]').exists()).toBe(false)
      expect(w.get('header').text()).toContain('Эти слова ещё вернутся — так и задумано')
    })

    it('adds what was typed then as a muted second line', () => {
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 10 }, corrected: corrected('borow') }), 'hard_review')

      const answer = w.get('[data-testid=hard-round-corrected-answer]')
      expect(answer.text()).toBe('Тогда: borow')
      expect(answer.classes()).toContain('text-tg-hint')
    })

    it('shows the text as text, in Uzbek too', () => {
      setLocale('uz')
      const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 9 }, corrected: corrected('<b>x</b>') }), 'hard_review')

      expect(w.get('[data-testid=hard-round-corrected-line]').text()).toBe("Avval «borrow» (занимать) so'zida xato qilgan edingiz — bugun to'g'ri javob berdingiz.")
      expect(w.get('[data-testid=hard-round-corrected-answer]').text()).toBe("O'shanda: <b>x</b>")
      expect(w.find('[data-testid=hard-round-corrected-answer] b').exists()).toBe(false)
    })

    it('leaves the translation out when there is none, the sentence unchanged', () => {
      for (const translation of ['', '   ']) {
        const w = render('hard_round_complete', summary({ hard_round: { total: 10, won: 9 }, corrected: corrected(null, translation) }), 'hard_review')

        expect(w.get('[data-testid=hard-round-corrected-line]').text()).toBe('Раньше вы ошибались в слове «borrow» — сегодня ответили верно.')
        expect(w.find('[data-testid=hard-round-corrected-translation]').exists()).toBe(false)
        w.unmount()
      }
    })

    it('renders nothing without it: null, absent (an older server), or another mode', () => {
      for (const value of [summary({ hard_round: { total: 10, won: 9 }, corrected: null }), summary({ hard_round: { total: 10, won: 9 } })]) {
        const w = render('hard_round_complete', value, 'hard_review')
        expect(w.find('[data-testid=hard-round-corrected]').exists()).toBe(false)
        w.unmount()
      }
      const daily = render('sprint_complete', summary({ corrected: corrected() }))
      expect(daily.find('[data-testid=hard-round-corrected]').exists()).toBe(false)
    })
  })
})
