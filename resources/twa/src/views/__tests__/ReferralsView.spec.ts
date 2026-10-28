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
  shareUrl: vi.fn(),
  haptic: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { referrals: { get: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: state.ensure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    shareUrl: state.shareUrl,
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
  reward_days: 7,
  activation: { days: 3, reviews_per_day: 10, window_days: 14 },
  counts: { invited: 3, activated: 2, rewarded: 1, capped: 0 },
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
    useToast().hide()
  })
  afterEach(() => {
    setLocale('ru')
    useToast().hide()
  })

  it('shows the hero, the three steps, the link, the counts and the invited learners', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Пригласить друга')
    expect(text(wrapper, 'hero-title')).toBe('Вам и другу — 7 дн. Pro')
    expect(text(wrapper, 'link')).toBe(LINK)
    expect(text(wrapper, 'count-invited')).toBe('3')
    expect(text(wrapper, 'count-activated')).toBe('2')
    expect(text(wrapper, 'count-rewarded')).toBe('1')

    const rows = wrapper.findAll('[data-testid=referral-row]')
    expect(rows).toHaveLength(3)
    expect(rows[0]?.text()).toContain('Dilnoza')
    expect(rows[0]?.text()).toContain('Pro получен')
    expect(rows[1]?.text()).toContain('Друг')
    expect(rows[1]?.text()).toContain('занимается')
    expect(rows[2]?.text()).toContain('ещё не активен')
    expect(wrapper.find('[data-testid=list-empty]').exists()).toBe(false)
  })

  describe('the rule', () => {
    it('is three numbered steps in the order of events, with the numbers of the server', async () => {
      const { wrapper } = await render()

      const rule = wrapper.get('[data-testid=rule]')
      expect(rule.element.tagName).toBe('OL')
      const steps = rule.findAll('li')
      expect(steps.map((li) => li.find('span:last-child').text())).toEqual([
        'Отправьте другу ссылку',
        'Друг занимается 3 дн. (от 10 карточек в день) в течение 14 дней',
        'Вы оба получаете 7 дн. Pro',
      ])
    })

    it('puts the numbers in accent circles', async () => {
      const { wrapper } = await render()

      const circles = wrapper.findAll('[data-testid=rule] li > span:first-child')
      expect(circles.map((c) => c.text())).toEqual(['1', '2', '3'])
      for (const circle of circles) {
        expect(circle.classes()).toEqual(expect.arrayContaining(['rounded-full', 'bg-accent-soft', 'text-accent']))
        expect(circle.attributes('aria-hidden')).toBe('true')
      }
    })

    it('follows the reward and the activation of the server', async () => {
      const { wrapper } = await render(response({ reward_days: 30, activation: { days: 5, reviews_per_day: 15, window_days: 21 } }))

      expect(text(wrapper, 'hero-title')).toBe('Вам и другу — 30 дн. Pro')
      expect(text(wrapper, 'rule')).toContain('Друг занимается 5 дн. (от 15 карточек в день) в течение 21 дней')
      expect(text(wrapper, 'rule')).toContain('Вы оба получаете 30 дн. Pro')
    })

    it('has a pink gift tile in the hero', async () => {
      const { wrapper } = await render()

      const tile = wrapper.get('[data-testid=hero] .icon-tile')
      expect(tile.classes()).toContain('tile-pink')
      expect(tile.find('svg').exists()).toBe(true)
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

    it('opens the Telegram share dialog with the link and the prefilled text', async () => {
      const { wrapper } = await render()

      await wrapper.get('[data-testid=share-btn]').trigger('click')

      expect(state.shareUrl).toHaveBeenCalledWith(LINK, 'Учу английский в LexiFlow — присоединяйся, получим Pro вместе!')
      expect(state.haptic).toHaveBeenCalled()
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
      const { wrapper } = await render(response({ link: null, referrals: [], counts: { invited: 0, activated: 0, rewarded: 0, capped: 0 } }))

      expect(text(wrapper, 'code')).toBe('ABCD2345')
      expect(text(wrapper, 'no-link')).toContain('/start ref_ABCD2345')
      expect(wrapper.find('[data-testid=share-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=copy-btn]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=link]').exists()).toBe(false)
    })
  })

  describe('the counts', () => {
    it('are three tiles: came, studying, rewarded', async () => {
      const { wrapper } = await render()

      const counts = wrapper.get('[data-testid=counts]')
      expect(counts.classes()).toEqual(expect.arrayContaining(['grid', 'grid-cols-3']))
      const tiles = counts.findAll('.card')
      expect(tiles).toHaveLength(3)
      expect(tiles.map((tile) => tile.text())).toEqual(['3Пришли', '2Занимаются', '1Награждены'])
    })

    it('explains the reached reward limit only when some referrals were capped', async () => {
      const plain = await render()
      expect(plain.wrapper.find('[data-testid=capped-hint]').exists()).toBe(false)

      const { wrapper } = await render(response({ counts: { invited: 3, activated: 3, rewarded: 1, capped: 2 } }))
      expect(text(wrapper, 'capped-hint')).toContain('Лимит наград достигнут')
      expect(text(wrapper, 'capped-hint')).toContain('2')
    })
  })

  describe('the invited learners', () => {
    it('are rows with an initial avatar, the date and a status chip: waiting neutral, studying accent, rewarded success', async () => {
      const { wrapper } = await render()

      const rows = wrapper.findAll('[data-testid=referral-row]')
      const chips = rows.map((row) => row.get('[data-testid=referral-status]'))
      expect(chips.map((c) => c.text())).toEqual(['Pro получен', 'занимается', 'ещё не активен'])
      expect(chips[0]?.classes()).toContain('chip-success')
      expect(chips[1]?.classes()).toContain('chip-accent')
      expect(chips[2]?.classes()).toContain('chip-neutral')

      expect(rows[0]?.text()).toContain(shortDate('2026-10-20T10:00:00+00:00'))
      expect(rows[1]?.text()).toContain(shortDate('2026-10-21T10:00:00+00:00'))
      expect(rows[0]?.get('[aria-hidden=true]').text()).toBe('D')
      expect(rows[1]?.get('[aria-hidden=true]').text()).toBe('Д')
    })

    it('say so when nobody has come yet, with an icon', async () => {
      const { wrapper } = await render(response({ referrals: [], counts: { invited: 0, activated: 0, rewarded: 0, capped: 0 } }))

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
      expect(link.find('.icon-tile.tile-blue').exists()).toBe(true)
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
    expect(text(wrapper, 'hero-title')).toBe("Sizga va do'stingizga — 7 kunlik Pro")
    expect(text(wrapper, 'rule')).toContain("Do'stingizga havolani yuboring")
    expect(text(wrapper, 'rule')).toContain("Do'stingiz 14 kun ichida 3 kun shug'ullanadi (kuniga 10 ta kartochkadan)")
    expect(text(wrapper, 'rule')).toContain('Ikkalangiz ham 7 kunlik Pro olasiz')
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
    const { wrapper } = await render(response({ counts: { invited: 3, activated: 3, rewarded: 1, capped: 2 } }))

    expect(wrapper.html()).not.toMatch(/\b(?:text|bg|border)-(?:red|green|gray|black|white)\b|\b(?:text|bg|border)-(?:red|green|gray|blue)-\d{2,3}\b/)
  })
})
