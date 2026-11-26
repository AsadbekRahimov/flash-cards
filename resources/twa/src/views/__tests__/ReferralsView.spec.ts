import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import ReferralsView from '../ReferralsView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import { setLocale } from '@/i18n'
import type { ReferralsResponse } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  back: undefined as undefined | (() => void),
  hideBack: vi.fn(),
  haptic: vi.fn(),
  share: vi.fn(),
  pending: { value: false },
}))

vi.mock('@/api', () => ({ api: { referrals: { get: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useShare', async () => {
  const { ref } = await import('vue')
  const pending = ref(false)
  state.pending = pending
  return { useShare: () => ({ pending, share: state.share }) }
})

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    haptic: state.haptic,
    showBackButton: (handler: () => void) => {
      state.back = handler
      return state.hideBack
    },
  }),
}))

const LINK = 'https://t.me/LexiFlowBot?start=ref_ABCD2345'

const response = (overrides: Partial<ReferralsResponse> = {}): ReferralsResponse => ({
  code: 'ABCD2345',
  link: LINK,
  activation: { days: 1, reviews_per_day: 10, window_days: 14 },
  milestone_days: 7,
  counts: { invited: 3, activated: 2, counted: 1 },
  milestones: [
    { friends: 3, days: 7, reached_at: null, rewarded: false },
    { friends: 6, days: 7, reached_at: null, rewarded: false },
  ],
  next: { friends: 3, remaining: 2 },
  referrals: [
    { first_name: 'Dilnoza', status: 'rewarded', linked_at: '2026-10-20T10:00:00+00:00' },
    { first_name: null, status: 'activated', linked_at: '2026-10-21T10:00:00+00:00' },
    { first_name: 'Bek', status: 'pending', linked_at: '2026-10-22T10:00:00+00:00' },
  ],
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
      { path: '/referrals', name: 'referrals', component: ReferralsView },
      { path: '/friends', name: 'friends', component: { template: '<div />' } },
    ],
  })

async function render(data: ReferralsResponse = response()) {
  vi.mocked(api.referrals.get).mockResolvedValue(data)
  const router = makeRouter()
  await router.push('/referrals')
  await router.isReady()
  const wrapper = mount(ReferralsView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = Awaited<ReturnType<typeof render>>['wrapper']

const text = (w: Wrapper, id: string): string => w.get(`[data-testid=${id}]`).text()

function setClipboard(value: unknown): void {
  Object.defineProperty(navigator, 'clipboard', { value, configurable: true })
}

/** The old way of copying (a selected field): what the browser answers to `execCommand('copy')`. */
function setExecCommand(result: boolean | undefined): void {
  Object.defineProperty(document, 'execCommand', { value: result === undefined ? undefined : () => result, configurable: true, writable: true })
}

/** Local date of an ISO instant as the screen writes it ("20 окт."). */
const shortDate = (iso: string): string => new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short' }).format(new Date(iso))

describe('ReferralsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.back = undefined
    state.ensure.mockResolvedValue(undefined)
    state.pending.value = false
    state.share.mockResolvedValue('sent')
    useToast().hide()
  })
  afterEach(() => {
    setLocale('ru')
    useToast().hide()
  })

  it('shows the hero, the progress, the link, the counts and the invited learners', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Пригласить друга')
    expect(text(wrapper, 'hero-title')).toBe('Приглашайте друзей — получайте Pro')
    expect(text(wrapper, 'link')).toBe(LINK)
    expect(text(wrapper, 'count-invited')).toBe('3')
    expect(text(wrapper, 'count-activated')).toBe('2')
    expect(text(wrapper, 'count-counted')).toBe('1')
    expect(text(wrapper, 'friend-gets')).toBe('Друг получает пробный Pro при регистрации')

    const rows = wrapper.findAll('[data-testid=referral-row]')
    expect(rows).toHaveLength(3)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.text()).toContain('засчитан')
    expect(rows[1]?.text()).toContain('Друг')
    expect(rows[1]?.text()).toContain('занимается')
    expect(rows[2]?.text()).toContain('ещё не начал')
    expect(wrapper.find('[data-testid=list-empty]').exists()).toBe(false)
  })

  describe('the progress', () => {
    const filled = (w: Wrapper): string[] => w.findAll('[data-testid=progress-dot]').map((d) => d.attributes('data-filled') ?? '')
    const marks = (w: Wrapper): number[] =>
      w.findAll('[data-testid=progress-dot]').flatMap((d, i) => (d.attributes('data-milestone') === 'true' ? [i + 1] : []))
    const milestone = (counted: number, reached: number[], rewarded: number[]): Partial<ReferralsResponse> => ({
      counts: { invited: 6, activated: counted, counted },
      milestones: [3, 6].map((friends) => ({
        friends,
        days: 7,
        reached_at: reached.includes(friends) ? '2026-11-10T09:00:00+00:00' : null,
        rewarded: rewarded.includes(friends),
      })),
      next: counted >= 6 ? null : { friends: counted >= 3 ? 6 : 3, remaining: (counted >= 3 ? 6 : 3) - counted },
    })

    it('is six friends with a mark on the 3rd and the 6th, none counted yet', async () => {
      const { wrapper } = await render(response(milestone(0, [], [])))

      expect(filled(wrapper)).toEqual(['false', 'false', 'false', 'false', 'false', 'false'])
      expect(marks(wrapper)).toEqual([3, 6])
      expect(text(wrapper, 'milestone-3')).toBe('3 друга → +7 дн. Pro')
      expect(text(wrapper, 'milestone-6')).toBe('6 друзей → +7 дн. Pro')
      expect(text(wrapper, 'next-step')).toBe('Ещё 3 друга — и +7 дн. Pro ваши')
      expect(wrapper.find('[data-testid=milestone-done]').exists()).toBe(false)
    })

    it('fills the counted friends and says how many are left to the next reward', async () => {
      const { wrapper } = await render(response(milestone(2, [], [])))

      expect(filled(wrapper)).toEqual(['true', 'true', 'false', 'false', 'false', 'false'])
      expect(text(wrapper, 'next-step')).toBe('Ещё 1 друг — и +7 дн. Pro ваши')
    })

    it('marks the reached milestone as received once the third friend is counted', async () => {
      const { wrapper } = await render(response(milestone(3, [3], [3])))

      expect(filled(wrapper).filter((f) => f === 'true')).toHaveLength(3)
      expect(text(wrapper, 'milestone-3')).toContain('получено')
      expect(wrapper.find('[data-testid=milestone-6] [data-testid=milestone-done]').exists()).toBe(false)
      expect(text(wrapper, 'next-step')).toBe('Ещё 3 друга — и +7 дн. Pro ваши')
    })

    it('says the reward is being paid while it is reached but not yet given', async () => {
      const { wrapper } = await render(response(milestone(3, [3], [])))

      expect(text(wrapper, 'milestone-3')).toContain('начисляется')
      expect(text(wrapper, 'milestone-3')).not.toContain('получено')
    })

    it('counts five of six', async () => {
      const { wrapper } = await render(response(milestone(5, [3], [3])))

      expect(filled(wrapper).filter((f) => f === 'true')).toHaveLength(5)
      expect(text(wrapper, 'next-step')).toBe('Ещё 1 друг — и +7 дн. Pro ваши')
    })

    it('thanks the learner when all rewards are received (next is null)', async () => {
      const { wrapper } = await render(response(milestone(6, [3, 6], [3, 6])))

      expect(filled(wrapper).every((f) => f === 'true')).toBe(true)
      expect(wrapper.findAll('[data-testid=milestone-done]')).toHaveLength(2)
      expect(text(wrapper, 'next-step')).toBe('Все награды получены. Спасибо, что зовёте друзей')
    })

    it('takes the numbers from the milestones, not from the code', async () => {
      const { wrapper } = await render(
        response({
          counts: { invited: 1, activated: 1, counted: 1 },
          milestones: [{ friends: 2, days: 10, reached_at: null, rewarded: false }, { friends: 5, days: 20, reached_at: null, rewarded: false }],
          next: { friends: 2, remaining: 1 },
        }),
      )

      expect(wrapper.findAll('[data-testid=progress-dot]')).toHaveLength(5)
      expect(marks(wrapper)).toEqual([2, 5])
      expect(text(wrapper, 'milestone-2')).toBe('2 друга → +10 дн. Pro')
      expect(text(wrapper, 'next-step')).toBe('Ещё 1 друг — и +10 дн. Pro ваши')
    })

    it('has no dots for a ladder too long for one line, but keeps the milestones', async () => {
      const { wrapper } = await render(
        response({ milestones: [{ friends: 20, days: 7, reached_at: null, rewarded: false }], next: { friends: 20, remaining: 19 } }),
      )

      expect(wrapper.find('[data-testid=progress-dots]').exists()).toBe(false)
      expect(text(wrapper, 'milestone-20')).toBe('20 друзей → +7 дн. Pro')
    })

    it('has no horizontal overflow classes on the row of dots: fixed 32 px dots, six of them', async () => {
      const { wrapper } = await render()

      for (const dot of wrapper.findAll('[data-testid=progress-dot]')) expect(dot.classes()).toContain('size-8')
    })
  })

  describe('the rule', () => {
    it('is three numbered steps in the order of events, with the numbers of the server', async () => {
      const { wrapper } = await render()

      const rule = wrapper.get('[data-testid=rule]')
      expect(rule.element.tagName).toBe('OL')
      const steps = rule.findAll('li')
      expect(steps.map((li) => li.find('span:last-child').text())).toEqual([
        'Отправьте другу ссылку',
        'Друг проходит настройку в боте и оценивает 10 карточек за один день — в течение 14 дн. после перехода по ссылке',
        'Pro бесплатно: за 3 друга — +7 дн., за 6 друзей — ещё +7 дн.',
      ])
    })

    it('promises Pro to nobody but the inviter: no "both" in the rule', async () => {
      const { wrapper } = await render()

      expect(wrapper.text()).not.toMatch(/Вам и другу|оба получаете|Pro получен/)
    })

    it('puts the numbers in white circles on the tinted hero', async () => {
      const { wrapper } = await render()

      const circles = wrapper.findAll('[data-testid=rule] li > span:first-child')
      expect(circles.map((c) => c.text())).toEqual(['1', '2', '3'])
      for (const circle of circles) {
        // the number is text in the ink of the accent (docs/14 §4.1) on a white circle: the soft accent would melt into `highlight`
        expect(circle.classes()).toEqual(expect.arrayContaining(['rounded-full', 'bg-surface', 'text-accent-ink']))
        expect(circle.attributes('aria-hidden')).toBe('true')
      }
    })

    it('follows the activation of the server: several days are told with days', async () => {
      const { wrapper } = await render(response({ activation: { days: 5, reviews_per_day: 15, window_days: 21 } }))

      expect(text(wrapper, 'rule')).toContain('Друг проходит настройку в боте и занимается 5 дн. (от 15 карточек в день) в течение 21 дн. после перехода по ссылке')
    })

    it('is the tinted card of the screen with no coloured tile: the title alone, bold', async () => {
      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=hero]').classes()).toEqual(expect.arrayContaining(['rounded-hero', 'bg-highlight']))
      expect(wrapper.find('[data-testid=hero] .icon-tile').exists()).toBe(false)
      expect(wrapper.get('[data-testid=hero-title]').classes()).toContain('font-bold')
    })
  })

  describe('the link', () => {
    it('is in a read-only field with a primary "Поделиться" and a tinted "Скопировать ссылку"', async () => {
      const { wrapper } = await render()

      const link = wrapper.get('[data-testid=link]')
      expect(link.classes()).toEqual(expect.arrayContaining(['field', 'font-mono', 'break-all']))

      const share = wrapper.get('[data-testid=share-btn]')
      expect(share.text()).toBe('Поделиться')
      expect(share.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary']))
      const copy = wrapper.get('[data-testid=copy-btn]')
      expect(copy.text()).toBe('Скопировать ссылку')
      expect(copy.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
    })

    it('is the only primary button of the screen', async () => {
      const { wrapper } = await render()

      expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    })

    it('shares the invitation through the bot, with the link and the prefilled text as the fallback', async () => {
      const { wrapper } = await render()

      await wrapper.get('[data-testid=share-btn]').trigger('click')

      expect(state.share).toHaveBeenCalledWith('invite', null, {
        link: LINK,
        text: 'Учу английский в Muhrla. Заходи по ссылке — получишь Pro бесплатно на пробный период',
      })
      expect(state.haptic).toHaveBeenCalled()
    })

    it('holds the button with a spinner while the share is on its way', async () => {
      const { wrapper } = await render()

      state.pending.value = true
      await flushPromises()
      const share = wrapper.get('[data-testid=share-btn]')
      expect(share.attributes('disabled')).toBeDefined()
      expect(share.attributes('aria-busy')).toBe('true')
      expect(share.find('[data-testid=share-busy]').exists()).toBe(true)
      expect(share.text()).toBe('Поделиться')

      state.pending.value = false
      await flushPromises()
      expect(wrapper.get('[data-testid=share-btn]').attributes('disabled')).toBeUndefined()
      expect(wrapper.find('[data-testid=share-busy]').exists()).toBe(false)
    })

    it('copies the link and says "Скопировано" in a toast', async () => {
      const writeText = vi.fn().mockResolvedValue(undefined)
      setClipboard({ writeText })
      const { wrapper } = await render()

      await wrapper.get('[data-testid=copy-btn]').trigger('click')
      await flushPromises()

      expect(writeText).toHaveBeenCalledWith(LINK)
      expect(useToast().toast.value?.text).toBe('Скопировано')
      expect(text(wrapper, 'copy-btn')).toBe('Скопировать ссылку')
    })

    it('copies the old way and says "Скопировано" when the clipboard API is missing', async () => {
      setClipboard(undefined)
      setExecCommand(true)
      const { wrapper } = await render()

      await wrapper.get('[data-testid=copy-btn]').trigger('click')
      await flushPromises()

      expect(useToast().toast.value?.text).toBe('Скопировано')
    })

    it('tells that it could not copy when nothing works: the link is on the screen to select and copy by hand', async () => {
      setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('NotAllowedError')) })
      setExecCommand(false)
      const { wrapper } = await render()

      await wrapper.get('[data-testid=copy-btn]').trigger('click')
      await flushPromises()

      expect(useToast().toast.value?.text).toBe('Не удалось скопировать. Выделите ссылку и скопируйте вручную.')
      expect(wrapper.get('[data-testid=link]').text()).toBe(LINK)
    })

    it('shows the code and an explanation while the bot username is not configured', async () => {
      const { wrapper } = await render(response({ link: null, referrals: [], counts: { invited: 0, activated: 0, counted: 0 } }))

      expect(text(wrapper, 'code')).toBe('ABCD2345')
      expect(text(wrapper, 'no-link')).toContain('/start ref_ABCD2345')
      expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=copy-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=link]').exists()).toBe(false)
    })
  })

  describe('the counts', () => {
    it('are three tiles: came, studying, counted', async () => {
      const { wrapper } = await render()

      const counts = wrapper.get('[data-testid=counts]')
      expect(counts.classes()).toEqual(expect.arrayContaining(['grid', 'grid-cols-3']))
      const tiles = counts.findAll('.card')
      expect(tiles).toHaveLength(3)
      expect(tiles.map((tile) => tile.text())).toEqual(['3Пришли', '2Занимаются', '1Засчитаны'])
    })

    it('has no block about a reward limit any more', async () => {
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=capped-hint]').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Лимит наград')
    })
  })

  describe('the invited learners', () => {
    it('are rows with an initial avatar, the date and a status chip: waiting neutral, studying accent, counted success', async () => {
      const { wrapper } = await render()

      const rows = wrapper.findAll('[data-testid=referral-row]')
      const chips = rows.map((row) => row.get('[data-testid=referral-status]'))
      expect(chips.map((c) => c.text())).toEqual(['засчитан', 'занимается', 'ещё не начал'])
      expect(chips[0]?.classes()).toContain('chip-success')
      expect(chips[1]?.classes()).toContain('chip-accent')
      expect(chips[2]?.classes()).toContain('chip-neutral')

      expect(rows[0]?.text()).toContain(shortDate('2026-10-20T10:00:00+00:00'))
      expect(rows[1]?.text()).toContain(shortDate('2026-10-21T10:00:00+00:00'))
      expect(rows[0]?.get('[aria-hidden=true]').text()).toBe('D')
      expect(rows[1]?.get('[aria-hidden=true]').text()).toBe('Д')
    })

    it('say so when nobody has come yet, with an icon', async () => {
      const { wrapper } = await render(response({ referrals: [], counts: { invited: 0, activated: 0, counted: 0 } }))

      expect(text(wrapper, 'list-empty')).toBe('Пока никто не пришёл по вашей ссылке.')
      expect(wrapper.find('[data-testid=list-empty] svg').exists()).toBe(true)
      expect(wrapper.find('[data-testid=referral-row]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=list]').exists()).toBe(false)
    })

    it('are in a group with a title', async () => {
      const { wrapper } = await render()

      expect(text(wrapper, 'list')).toContain('Приглашённые')
    })
  })

  describe('the friends link', () => {
    it('is a row at the bottom of the screen, not a button on top', async () => {
      const { wrapper } = await render()

      const link = wrapper.get('[data-testid=friends-link]')
      expect(link.element.tagName).toBe('A')
      expect(link.attributes('href')).toBe('/friends')
      expect(link.text()).toBe('Друзья и вызовы')
      expect(link.classes()).toContain('list-row')
      expect(link.find('.icon-tile').exists()).toBe(false)
      expect(link.classes()).not.toContain('btn')

      const after = (other: string) =>
        Boolean(wrapper.get(`[data-testid=${other}]`).element.compareDocumentPosition(link.element) & Node.DOCUMENT_POSITION_FOLLOWING)
      expect(after('hero')).toBe(true)
      expect(after('counts')).toBe(true)
      expect(after('list')).toBe(true)
    })

    it('opens the friends screen', async () => {
      const { wrapper, router } = await render()

      await wrapper.get('[data-testid=friends-link]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('friends')
    })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe("Do'stni taklif qilish")
    expect(text(wrapper, 'hero-title')).toBe("Do'stlarni taklif qiling — Pro oling")
    expect(text(wrapper, 'milestone-3')).toBe("3 ta do'st → +7 kun Pro")
    expect(text(wrapper, 'next-step')).toBe("Yana 2 ta do'st — va +7 kun Pro sizniki")
    expect(text(wrapper, 'rule')).toContain("Do'stingizga havolani yuboring")
    expect(text(wrapper, 'rule')).toContain("bir kunda 10 ta kartochkani baholaydi")
    expect(text(wrapper, 'rule')).toContain("Bepul Pro: 3 ta do'st uchun — +7 kun, 6 ta do'st uchun — yana +7 kun")
    expect(text(wrapper, 'friend-gets')).toBe("Do'stingiz ro'yxatdan o'tgach, Pro tarifidan sinov muddati davomida foydalanadi")
    expect(text(wrapper, 'share-btn')).toBe('Ulashish')
    expect(text(wrapper, 'copy-btn')).toBe('Havolani nusxalash')
    expect(text(wrapper, 'friends-link')).toBe("Do'stlar va musobaqalar")
  })

  describe('the Back button', () => {
    it('goes back to the Profile and is removed on leaving', async () => {
      const { wrapper, router } = await render()

      state.back?.()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('profile')

      wrapper.unmount()
      expect(state.hideBack).toHaveBeenCalled()
    })

    it('goes back to Today when the learner came from the invitation card there', async () => {
      vi.mocked(api.referrals.get).mockResolvedValue(response())
      const router = makeRouter()
      await router.push('/referrals?from=today')
      await router.isReady()
      mount(ReferralsView, { global: { plugins: [router] } })
      await flushPromises()

      state.back?.()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('today')
    })
  })

  describe('loading and errors', () => {
    it('shows a skeleton while loading, under the title', async () => {
      vi.mocked(api.referrals.get).mockReturnValue(new Promise(() => {}))
      const router = makeRouter()
      await router.push('/referrals')
      const wrapper = mount(ReferralsView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
      expect(wrapper.get('h1').text()).toBe('Пригласить друга')
      expect(wrapper.find('[data-testid=hero]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=friends-link]').exists()).toBe(false)
    })

    it('shows the error with a retry', async () => {
      vi.mocked(api.referrals.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
      vi.mocked(api.referrals.get).mockResolvedValue(response())
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=link]').exists()).toBe(true)
    })

    it('shows the error when the sign-in fails and loads nothing', async () => {
      state.ensure.mockRejectedValue(new ApiClientError('network', 0, 'network', 'x'))
      const { wrapper } = await render()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
      expect(api.referrals.get).not.toHaveBeenCalled()
    })
  })

  it('uses only the tokens of the design system: no raw palette colours', async () => {
    const { wrapper } = await render(response())

    expect(wrapper.html()).not.toMatch(/\b(?:text|bg|border)-(?:red|green|gray|black|white)\b|\b(?:text|bg|border)-(?:red|green|gray|blue)-\d{2,3}\b/)
  })
})
