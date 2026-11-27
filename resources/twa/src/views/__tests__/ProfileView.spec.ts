import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import ProfileView from '../ProfileView.vue'
import { api } from '@/api'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import { profile as gameProfile } from '@/api/__tests__/gameShopFixtures'
import type { Entitlement, LearnerSettings, MeResponse } from '@/api/types'

const mocks = vi.hoisted(() => ({
  ensure: vi.fn(),
  learner: null as { id: number; first_name: string | null } | null,
  hideBack: vi.fn(),
  showBack: vi.fn(),
}))

vi.mock('@/api', () => ({
  api: { me: { get: vi.fn() }, gameShop: { profile: vi.fn() }, billing: { catalog: vi.fn(), offerViewed: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ensure: mocks.ensure,
    get learner() {
      return mocks.learner
    },
  }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: mocks.showBack, webApp: { BackButton: { hide: mocks.hideBack } } }),
}))

const me = (entitlement: Partial<Entitlement> = {}, contact: string | null = '@lexiflow_help'): MeResponse => ({
  learner: { id: 1, first_name: 'Асадбек', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, ...entitlement },
  paywall: { mode: 'purchase', contact },
  settings: settingsFixture(),
})

const gameOpen = (): MeResponse => ({ ...me(), game: { available: true, limits: null, pro_promise: 'none', coins: 0, tickets: 0 } })

const EMOJIS = [
  { code: 'fire', emoji: '🔥' },
  { code: 'star', emoji: '⭐' },
]

/** The settings of `/me` with the set of the emoji of Pro: `locked` as the server says it for Free. */
const emojiSettings = (choice: string | null, locked: boolean): LearnerSettings =>
  settingsFixture({ pro_emoji_choice: choice, options: { ...settingsFixture().options, pro_emojis: EMOJIS, pro_emoji_locked: locked } })

/** A `/me` with the emoji next to the name (`learner.pro_emoji`, only while Pro lasts) and the settings of the set. */
const withEmoji = (base: MeResponse, proEmoji: string | null, settings: LearnerSettings): MeResponse => ({
  ...base,
  learner: { ...base.learner, pro_emoji: proEmoji },
  settings,
})

const PRO = { plan: 'pro', source: 'subscription', new_words_per_day: null } as const

async function render() {
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['profile', 'billing', 'settings', 'referrals', 'achievements', 'friends', 'game-caravan'].map((name) => ({
      path: name === 'game-caravan' ? '/game/caravan' : `/${name}`,
      name,
      component: stub,
    })),
  })
  await router.push('/profile')
  const wrapper = mount(ProfileView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('ProfileView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mocks.ensure.mockResolvedValue(undefined)
    mocks.learner = { id: 1, first_name: 'Асадбек' }
    vi.mocked(api.me.get).mockResolvedValue(me())
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(() => {
    setLocale('ru')
    vi.restoreAllMocks()
  })

  describe('Free learner', () => {
    it('shows the brand row, the title and the big tinted card: the initial, the name, the Free chip and the daily limit of /me', async () => {
      const w = await render()

      expect(mocks.ensure).toHaveBeenCalledTimes(1)
      expect(api.me.get).toHaveBeenCalledTimes(1)
      expect(w.find('[data-testid=brand-row]').exists()).toBe(true)
      expect(w.get('h1').text()).toBe('Профиль')
      expect(w.get('[data-testid=profile-header]').classes()).toEqual(expect.arrayContaining(['bg-highlight', 'rounded-hero']))
      expect(w.get('[data-testid=profile-name]').text()).toBe('Асадбек')
      expect(w.get('[data-testid=profile-avatar]').text()).toBe('А')
      expect(w.get('[data-testid=profile-chip]').text()).toBe('Free')
      expect(w.get('[data-testid=profile-chip]').attributes('data-plan')).toBe('free')
      expect(w.get('[data-testid=profile-plan]').text()).toBe('20 новых слов в день')
    })

    it('takes the limit from /me, never a number of its own (Free = 10 today)', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ new_words_per_day: 10 }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('10 новых слов в день')
    })

    it('has one language control in the brand row, and the language row of the account group', async () => {
      const w = await render()

      expect(w.findAll('[data-testid=lang-btn]')).toHaveLength(1)
      expect(w.findAll('[data-testid=row-language]')).toHaveLength(1)
    })

    it('has the bilingual "Til · Язык" row with the current language by its own name, right before "Настройки"', async () => {
      const w = await render()

      const row = w.get('[data-testid=row-language]')
      expect(row.text()).toContain('Til · Язык')
      expect(row.text()).toContain('🇷🇺 Русский')
      expect(row.element.tagName).toBe('BUTTON')
      expect(row.get('.icon-tile svg').attributes('aria-hidden')).toBe('true')
      expect(row.element.nextElementSibling).toBe(w.get('[data-testid=row-settings]').element)
    })

    it('keeps the row bilingual in Uzbek too and shows the Uzbek name', async () => {
      setLocale('uz')
      vi.mocked(api.me.get).mockResolvedValue({ ...me(), settings: settingsFixture({ interface_locale: 'uz' }) })
      const w = await render()

      expect(w.get('[data-testid=row-language]').text()).toContain('Til · Язык')
      expect(w.get('[data-testid=row-language]').text()).toContain("🇺🇿 O'zbekcha")
    })

    it('opens the language sheet from the row and closes it', async () => {
      const w = await render()

      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)
      await w.get('[data-testid=row-language]').trigger('click')
      expect(w.get('[data-testid=language-title]').text()).toBe('Til · Язык')
      expect(w.get('[data-testid=language-ru]').attributes('aria-checked')).toBe('true')

      await w.get('[data-testid=sheet-x]').trigger('click')
      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)
    })

    it('opens the sheet "How it works" from its own row, and closes it', async () => {
      const w = await render()

      expect(w.find('[data-testid=intro-sheet]').exists()).toBe(false)
      expect(w.get('[data-testid=row-intro]').text()).toBe('Как это работает')
      await w.get('[data-testid=row-intro]').trigger('click')
      expect(w.get('[data-testid=intro-title]').text()).toBe('Занятие — короткий раунд')

      await w.get('[data-testid=sheet-x]').trigger('click')
      expect(w.find('[data-testid=intro-sheet]').exists()).toBe(false)
    })

    it('has no spinner and no error once loaded', async () => {
      const w = await render()

      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.find('[data-testid=error]').exists()).toBe(false)
    })

    it('offers Pro: the pitch card with a primary button to the subscription', async () => {
      const w = await render()

      const cta = w.get('[data-testid=profile-pro-cta]')
      expect(w.find('[data-testid=profile-pro-card]').exists()).toBe(true)
      expect(cta.text()).toBe('Подробнее о Pro')
      expect(cta.element.tagName).toBe('A')
      expect(cta.attributes('href')).toBe('/billing')
      expect(cta.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary']))
    })

    it('inflects the daily limit by the number', async () => {
      for (const [count, text] of [
        [1, '1 новое слово в день'],
        [3, '3 новых слова в день'],
        [5, '5 новых слов в день'],
      ] as const) {
        setActivePinia(createPinia())
        vi.mocked(api.me.get).mockResolvedValue(me({ new_words_per_day: count }))

        const w = await render()

        expect(w.get('[data-testid=profile-plan]').text()).toBe(text)
      }
    })

    it('falls back to a plain "free plan" when the server sends no limit', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ new_words_per_day: null }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Бесплатный тариф')
    })
  })

  describe('Pro learner', () => {
    it('shows the end date, the Pro chip and no pitch card', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, until: '2026-12-01T12:00:00+00:00' }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro до 1 декабря 2026 г.')
      expect(w.get('[data-testid=profile-chip]').text()).toBe('Pro')
      expect(w.get('[data-testid=profile-chip]').attributes('data-plan')).toBe('pro')
      expect(w.find('[data-testid=profile-pro-cta]').exists()).toBe(false)
      expect(w.find('[data-testid=profile-pro-card]').exists()).toBe(false)
      expect(w.get('[data-testid=row-billing]').text()).toContain('Pro')
    })

    it('says "Trial Pro until ..." for the welcome trial, with the Pro chip', async () => {
      vi.mocked(api.me.get).mockResolvedValue(
        me({ ...PRO, until: '2026-11-23T12:00:00+00:00', renewal: { auto: false, provider: 'trial', ends_at: '2026-11-23T12:00:00+00:00', reminder_sent: false } }),
      )

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Пробный Pro до 23 ноября 2026 г.')
      expect(w.get('[data-testid=profile-chip]').text()).toBe('Pro')
    })

    it('says "Pro until ..." and not the trial for a paid subscription', async () => {
      vi.mocked(api.me.get).mockResolvedValue(
        me({ ...PRO, until: '2026-12-01T12:00:00+00:00', renewal: { auto: false, provider: 'payme', ends_at: '2026-12-01T12:00:00+00:00', reminder_sent: false } }),
      )

      expect((await render()).get('[data-testid=profile-plan]').text()).toBe('Pro до 1 декабря 2026 г.')
    })

    it('prefers the end of the own subscription over the entitlement end', async () => {
      vi.mocked(api.me.get).mockResolvedValue(
        me({
          ...PRO,
          until: '2026-12-01T12:00:00+00:00',
          renewal: { auto: true, provider: 'telegram_stars', ends_at: '2027-01-15T12:00:00+00:00', reminder_sent: false },
        }),
      )

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro до 15 января 2027 г.')
    })

    it('says "without a term" for a perpetual Pro', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, until: null }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro без срока')
    })

    it('says "from the center" for a Pro the learning center pays for', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, source: 'sponsor:organization:9', until: null }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro от центра')
    })
  })

  describe('rows', () => {
    it('links the five entries to their screens', async () => {
      const w = await render()

      const hrefs: Record<string, string> = {
        'row-achievements': '/achievements',
        'row-referrals': '/referrals',
        'row-friends': '/friends',
        'row-billing': '/billing',
        'row-settings': '/settings',
      }
      for (const [id, href] of Object.entries(hrefs)) {
        const row = w.get(`[data-testid=${id}]`)
        expect(row.element.tagName, id).toBe('A')
        expect(row.attributes('href'), id).toBe(href)
        expect(row.get('.icon-tile svg').attributes('aria-hidden'), id).toBe('true')
      }
    })

    it('labels them and explains the invitation reward', async () => {
      const w = await render()

      expect(w.get('[data-testid=row-achievements]').text()).toBe('Достижения')
      expect(w.get('[data-testid=row-referrals]').text()).toContain('Пригласить друга')
      expect(w.get('[data-testid=row-referrals]').text()).toContain('Приглашайте друзей — получайте Pro')
      expect(w.get('[data-testid=row-friends]').text()).toBe('Друзья и соревнования')
      expect(w.get('[data-testid=row-billing]').text()).toBe('ПодпискаFree')
      expect(w.get('[data-testid=row-settings]').text()).toBe('Настройки')
    })

    it('does not promise Pro to both sides any more', async () => {
      const w = await render()

      expect(w.get('[data-testid=row-referrals]').text()).not.toContain('Вам и другу')
    })

    it('groups "My results" and "My account" as in the mock-up, then what the mock-up does not have, then the Pro offer', async () => {
      const w = await render()

      expect(w.findAll('h2').map(heading => heading.text())).toEqual([
        'Асадбек',
        'Мои результаты',
        'Мой аккаунт',
        'Ещё',
        'Учитесь без дневного лимита',
      ])
    })

    it('puts achievements, readiness and friends in "My results", language, settings and subscription in "My account"', async () => {
      vi.mocked(api.me.get).mockResolvedValue({ ...me(), settings: settingsFixture({ active_course: { slug: 'a1', title: 'A1' } }) })
      const w = await render()

      const ids = (group: string) => w.findAll(`[data-testid=${group}] [data-testid^=row-]`).map((row) => row.attributes('data-testid'))
      expect(ids('group-results')).toEqual(['row-achievements', 'row-readiness', 'row-friends'])
      expect(ids('group-account')).toEqual(['row-language', 'row-settings', 'row-billing'])
      expect(ids('group-more')).toEqual(['row-referrals', 'row-intro'])
      for (const group of ['group-results', 'group-account', 'group-more']) {
        const plate = w.get(`[data-testid=${group}]`)
        expect(plate.classes(), group).toContain('bg-highlight')
        expect(plate.get('h2').classes(), group).toContain('t-title-m')
      }
    })

    it('keeps below the groups what the mock-up does not have: invitations, "How it works", the Pro card, the support', async () => {
      const w = await render()

      const html = w.html()
      const account = html.indexOf('data-testid="group-account"')
      for (const id of ['row-referrals', 'row-intro', 'profile-pro-card', 'profile-support']) {
        expect(html.indexOf(`data-testid="${id}"`), id).toBeGreaterThan(account)
      }
    })
  })

  describe('header', () => {
    it('uses the first letter, capitalised, of a trimmed name', async () => {
      mocks.learner = { id: 1, first_name: '  дилноза ' }

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('дилноза')
      expect(w.get('[data-testid=profile-avatar]').text()).toBe('Д')
    })

    it('keeps an emoji initial whole', async () => {
      mocks.learner = { id: 1, first_name: '🦊 Fox' }

      const w = await render()

      expect(w.get('[data-testid=profile-avatar]').text()).toBe('🦊')
    })

    it('without a name shows "Ученик" and the user icon instead of a letter', async () => {
      mocks.learner = { id: 1, first_name: null }

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('Ученик')
      const avatar = w.get('[data-testid=profile-avatar]')
      expect(avatar.element.tagName.toLowerCase()).toBe('svg')
      expect(avatar.text()).toBe('')
    })

    it('treats a blank name as no name', async () => {
      mocks.learner = { id: 1, first_name: '   ' }

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('Ученик')
    })

    it('copes with a missing learner', async () => {
      mocks.learner = null

      const w = await render()

      expect(w.get('[data-testid=profile-name]').text()).toBe('Ученик')
    })
  })

  describe('support contact', () => {
    it('links a Telegram handle', async () => {
      const w = await render()

      const link = w.get('[data-testid=profile-support-link]')
      expect(w.get('[data-testid=profile-support]').text()).toBe('Нужна помощь? Напишите: @lexiflow_help')
      expect(link.attributes('href')).toBe('https://t.me/lexiflow_help')
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener noreferrer')
    })

    it('shows a contact that is not a link as plain text', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me({}, 'javascript:alert(1)'))

      const w = await render()

      expect(w.find('[data-testid=profile-support-link]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-support-text]').text()).toBe('javascript:alert(1)')
    })

    it.each([null, '', '   '])('is left out when the contact is %j', async (contact) => {
      vi.mocked(api.me.get).mockResolvedValue(me({}, contact))

      const w = await render()

      expect(w.find('[data-testid=profile-support]').exists()).toBe(false)
    })
  })

  describe('navigation', () => {
    it('is a tab root: the system Back button is hidden, never shown', async () => {
      await render()

      expect(mocks.hideBack).toHaveBeenCalledTimes(1)
      expect(mocks.showBack).not.toHaveBeenCalled()
    })
  })

  describe('loading and errors', () => {
    it('shows a skeleton until the plan has arrived', async () => {
      let resolve: (value: MeResponse) => void = () => {}
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>((r) => (resolve = r)))

      const w = await render()

      expect(w.find('[data-testid=spinner]').exists()).toBe(true)
      expect(w.find('[data-testid=profile-name]').exists()).toBe(false)

      resolve(me())
      await flushPromises()

      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.find('[data-testid=profile-name]').exists()).toBe(true)
    })

    it('shows the plan it already has without a skeleton, and refreshes it', async () => {
      const billing = useBillingStore()
      billing.apply(me({ ...PRO, until: null }))
      billing.invalidate() // the stored plan is older than 30 s: it is shown, and asked for again
      vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>(() => {}))

      const w = await render()

      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro без срока')
      expect(api.me.get).toHaveBeenCalledTimes(1)
    })

    it('does not ask /me again while the plan it has is fresh', async () => {
      useBillingStore().apply(me({ ...PRO, until: null }))

      const w = await render()

      expect(api.me.get).not.toHaveBeenCalled()
      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro без срока')
    })

    it('shows an error with a retry when /me fails, then recovers', async () => {
      vi.mocked(api.me.get)
        .mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'Network down'))
        .mockResolvedValueOnce(me())

      const w = await render()

      expect(w.find('[data-testid=profile-name]').exists()).toBe(false)
      expect(w.get('[data-testid=error]').text()).toContain('Что-то пошло не так.')
      expect(w.get('[data-testid=error]').text()).not.toContain('Network down')

      await w.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(api.me.get).toHaveBeenCalledTimes(2)
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-name]').text()).toBe('Асадбек')
    })

    it('shows the localized reason when the authentication fails, and retries it', async () => {
      mocks.ensure.mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'boom'))

      const w = await render()

      expect(w.get('[data-testid=error]').text()).toContain('Нет связи с сервером. Попробуйте ещё раз.')
      expect(api.me.get).not.toHaveBeenCalled()

      await w.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(mocks.ensure).toHaveBeenCalledTimes(2)
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.find('[data-testid=profile-plan]').exists()).toBe(true)
    })

    it('does not call it a failure while another screen request is still in flight', async () => {
      const billing = useBillingStore()
      billing.loading = true // e.g. the Today screen asked for /me a moment ago

      const w = await render()

      expect(api.me.get).not.toHaveBeenCalled()
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.find('[data-testid=spinner]').exists()).toBe(true)

      billing.apply(me())
      billing.loading = false
      await flushPromises()

      expect(w.find('[data-testid=profile-name]').exists()).toBe(true)
    })

    it('turns that in-flight request into an error when it fails', async () => {
      const billing = useBillingStore()
      billing.loading = true

      const w = await render()
      billing.loading = false
      await flushPromises()

      expect(w.find('[data-testid=error]').exists()).toBe(true)
    })
  })

  describe('Uzbek', () => {
    it('speaks Uzbek: plan lines, rows and support', async () => {
      setLocale('uz')

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe("kuniga 20 ta yangi so'z")
      expect(w.get('[data-testid=profile-pro-cta]').text()).toBe('Pro haqida batafsil')
      expect(w.get('[data-testid=row-achievements]').text()).toBe('Yutuqlar')
      expect(w.get('[data-testid=row-friends]').text()).toBe("Do'stlar va musobaqalar")
      expect(w.get('[data-testid=row-billing]').text()).toBe('ObunaFree')
      expect(w.get('[data-testid=row-settings]').text()).toBe('Sozlamalar')
      expect(w.get('[data-testid=profile-support]').text()).toBe('Savolingiz bormi? Bizga yozing: @lexiflow_help')
    })

    it('puts the Uzbek date in the Pro line', async () => {
      setLocale('uz')
      vi.mocked(api.me.get).mockResolvedValue(me({ ...PRO, until: '2026-12-01T12:00:00+00:00' }))

      const w = await render()

      expect(w.get('[data-testid=profile-plan]').text()).toBe('Pro 1-dekabr, 2026 gacha amal qiladi')
    })
  })

  describe('My caravan card (docs/05 §5b.6)', () => {
    it('asks for nothing and shows no card while the game is closed', async () => {
      const w = await render()

      expect(api.gameShop.profile).not.toHaveBeenCalled()
      expect(w.find('[data-testid=profile-caravan]').exists()).toBe(false)
    })

    it('shows what is worn - frame round the avatar, camel, carpet, tent - and leads to the caravan', async () => {
      vi.mocked(api.me.get).mockResolvedValue(gameOpen())
      vi.mocked(api.gameShop.profile).mockResolvedValue(
        gameProfile({
          equipped: {
            camel: { key: 'camel_golden', asset: 'camel_golden' },
            tent: { key: 'tent_silk', asset: 'tent_silk' },
            frame: { key: 'frame_ornament', asset: 'frame_ornament' },
          },
        }),
      )

      const w = await render()

      expect(api.gameShop.profile).toHaveBeenCalledTimes(1)
      expect(w.get('[data-testid=profile-caravan]').text()).toContain('Мой караван')
      expect(w.get('[data-testid=caravan-frame-image]').attributes('data-asset')).toBe('frame_ornament')
      expect(w.get('[data-testid=caravan-camel] img').attributes('data-asset')).toBe('camel_golden')
      expect(w.get('[data-testid=caravan-tent] img').attributes('data-asset')).toBe('tent_silk')
      // a slot that is empty draws no picture and says so to a screen reader
      expect(w.find('[data-testid=caravan-carpet] img').exists()).toBe(false)
      expect(w.get('[data-testid=caravan-carpet]').text()).toContain('не надето')
      expect(w.get('[data-testid=profile-caravan-open]').attributes('href')).toBe('/game/caravan?from=profile')
    })

    it('draws no frame when none is worn', async () => {
      vi.mocked(api.me.get).mockResolvedValue(gameOpen())
      vi.mocked(api.gameShop.profile).mockResolvedValue(gameProfile({ equipped: {} }))

      const w = await render()

      expect(w.find('[data-testid=profile-caravan]').exists()).toBe(true)
      expect(w.find('[data-testid=caravan-frame-image]').exists()).toBe(false)
    })

    it('hides the card when the profile cannot be read, and the profile still works', async () => {
      vi.mocked(api.me.get).mockResolvedValue(gameOpen())
      vi.mocked(api.gameShop.profile).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))

      const w = await render()

      expect(w.find('[data-testid=profile-caravan]').exists()).toBe(false)
      expect(w.get('[data-testid=profile-name]').text()).toBe('Асадбек')
    })

    it('has Uzbek words', async () => {
      setLocale('uz')
      vi.mocked(api.me.get).mockResolvedValue(gameOpen())
      vi.mocked(api.gameShop.profile).mockResolvedValue(gameProfile())

      const w = await render()

      expect(w.get('[data-testid=profile-caravan]').text()).toContain('Mening karvonim')
      expect(w.get('[data-testid=profile-caravan-open]').text()).toBe('Karvonni ochish')
    })
  })

  describe('the emoji of Pro (docs/31 §5а)', () => {
    it('shows the emoji of /me next to the name of a Pro learner', async () => {
      vi.mocked(api.me.get).mockResolvedValue(withEmoji(me({ ...PRO, until: null }), '🔥', emojiSettings('fire', false)))

      const w = await render()

      expect(w.get('[data-testid=profile-emoji]').text()).toBe('🔥')
      expect(w.get('[data-testid=profile-name]').text()).toBe('Асадбек')
    })

    it.each([
      ['null', null],
      ['an empty string', ''],
    ])('shows no emoji when /me sends %s (no Pro, or none chosen)', async (_label, value) => {
      vi.mocked(api.me.get).mockResolvedValue(withEmoji(me(), value, emojiSettings('fire', true)))

      const w = await render()

      expect(w.find('[data-testid=profile-emoji]').exists()).toBe(false)
    })

    it('shows no emoji for an older server that sends no such field', async () => {
      const w = await render()

      expect(w.find('[data-testid=profile-emoji]').exists()).toBe(false)
    })

    it('Pro: the row "Эмодзи Pro" in "My account" shows the symbol chosen and leads to the picker of the Settings', async () => {
      vi.mocked(api.me.get).mockResolvedValue(withEmoji(me({ ...PRO, until: null }), '⭐', emojiSettings('star', false)))

      const w = await render()

      const row = w.get('[data-testid=group-account] [data-testid=row-pro-emoji]')
      expect(row.attributes('data-locked')).toBe('false')
      expect(row.text()).toBe('Эмодзи Pro⭐')
      expect(row.element.tagName).toBe('A')
      expect(row.attributes('href')).toBe('/settings')
    })

    it('Pro without a choice: "не выбран"', async () => {
      vi.mocked(api.me.get).mockResolvedValue(withEmoji(me({ ...PRO, until: null }), null, emojiSettings(null, false)))

      const w = await render()

      expect(w.get('[data-testid=row-pro-emoji]').text()).toBe('Эмодзи Proне выбран')
    })

    it('Free: a lock and "в Pro"; a tap opens the paywall and leaves the screen where it is', async () => {
      vi.mocked(api.me.get).mockResolvedValue(withEmoji(me(), null, emojiSettings('fire', true)))

      const w = await render()

      const row = w.get('[data-testid=row-pro-emoji]')
      expect(row.attributes('data-locked')).toBe('true')
      expect(row.element.tagName).toBe('BUTTON')
      expect(row.get('[data-testid=pro-emoji-locked]').text()).toBe('в Pro')
      expect(row.find('[data-testid=pro-emoji-locked] svg').exists()).toBe(true)
      expect(row.text()).not.toContain('🔥') // the choice is kept, but it is not shown without Pro
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)

      await row.trigger('click')
      await flushPromises()

      expect(w.find('[data-testid=paywall]').exists()).toBe(true)
      await w.get('[data-testid=paywall-x]').trigger('click')
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    })

    it('has no row when the server sends no set of emoji', async () => {
      const w = await render()

      expect(w.find('[data-testid=row-pro-emoji]').exists()).toBe(false)
    })

    it('speaks Uzbek', async () => {
      setLocale('uz')
      vi.mocked(api.me.get).mockResolvedValue(withEmoji(me(), null, emojiSettings(null, true)))

      const w = await render()

      expect(w.get('[data-testid=row-pro-emoji]').text()).toContain('Pro emojisi')
      expect(w.get('[data-testid=pro-emoji-locked]').text()).toBe("Pro'da")
    })
  })

  it('leads to the readiness of the active course, and has no row without one', async () => {
    vi.mocked(api.me.get).mockResolvedValue({ ...me(), settings: settingsFixture({ active_course: { slug: 'english-a1-a2', title: 'A1-A2' } }) })
    const withCourse = await render()
    const row = withCourse.get('[data-testid=row-readiness]')
    expect(row.text()).toContain('Готовность к уровню')
    expect(row.attributes('href')).toBe('/courses/english-a1-a2/readiness?source=profile')

    vi.mocked(api.me.get).mockResolvedValue({ ...me(), settings: settingsFixture({ active_course: null }) })
    setActivePinia(createPinia())
    expect((await render()).find('[data-testid=row-readiness]').exists()).toBe(false)
  })
})
