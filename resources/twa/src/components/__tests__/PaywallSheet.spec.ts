import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PaywallSheet from '../PaywallSheet.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTodayStore } from '@/stores/today'
import type { MeResponse, Paywall } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: { me: { get: vi.fn() }, promo: { redeem: vi.fn() }, today: { get: vi.fn() } },
}))

function me(overrides: { plan?: 'free' | 'pro'; contact?: string | null; limit?: number | null; until?: string | null } = {}): MeResponse {
  const plan = overrides.plan ?? 'free'
  const paywall: Paywall = { mode: 'coming_soon', contact: overrides.contact ?? null }
  return {
    learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
    groups: [],
    stats: { words_learned: 0, words_due_today: 0 },
    entitlement: {
      plan,
      source: plan === 'pro' ? 'subscription' : 'free',
      until: overrides.until ?? null,
      new_words_per_day: 'limit' in overrides ? (overrides.limit ?? null) : plan === 'free' ? 20 : null,
    },
    paywall,
    settings: settingsFixture(),
  }
}

async function render(response: MeResponse = me(), props: { reason?: 'quota' | 'info' | 'decks' } = {}) {
  vi.mocked(api.me.get).mockResolvedValue(response)
  const wrapper = mount(PaywallSheet, { props })
  await flushPromises()
  return wrapper
}

describe('PaywallSheet', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('says Pro is coming and what Free gives, with the limit from the server', async () => {
    const w = await render()

    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro скоро')
    expect(w.get('[data-testid=paywall-lead]').text()).toBe('Pro скоро откроется.')
    expect(w.get('[data-testid=paywall-free]').text()).toBe('Сейчас у вас тариф Free: 20 новых слов в день, повторения — без лимита.')
  })

  it('explains the deck limits when a deck write was refused', async () => {
    const w = await render(me(), { reason: 'decks' })

    expect(w.get('[data-testid=paywall-lead]').text()).toBe(
      'Лимит колод тарифа Free: 1 колода до 50 слов. В Pro — до 20 колод по 2000 слов. Pro скоро откроется.',
    )
  })

  it('reads the limit from /me, not from a constant', async () => {
    const w = await render(me({ limit: 7 }))

    expect(w.get('[data-testid=paywall-free]').text()).toContain('7 новых слов в день')
  })

  it('explains the exhausted quota when opened at the end of the new words', async () => {
    const w = await render(me(), { reason: 'quota' })

    expect(w.get('[data-testid=paywall-lead]').text()).toBe(
      'Лимит новых слов на сегодня исчерпан — повторения доступны без ограничений. Pro скоро откроется.',
    )
  })

  it('falls back to a generic Free description while /me is unavailable', async () => {
    vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))
    const w = mount(PaywallSheet)
    await flushPromises()

    expect(w.get('[data-testid=paywall-free]').text()).toContain('ограничены дневным лимитом')
    expect(w.find('[data-testid=paywall-contact]').exists()).toBe(false)
    expect(w.find('[data-testid=promo]').exists()).toBe(true)
  })

  describe('support contact', () => {
    it('is not mentioned when the owner has not configured one', async () => {
      const w = await render(me({ contact: null }))

      expect(w.find('[data-testid=paywall-contact]').exists()).toBe(false)
      expect(w.text()).not.toContain('Учитесь в центре')
    })

    it.each([
      ['@lexiflow_help', 'https://t.me/lexiflow_help'],
      ['help@lexiflow.uz', 'mailto:help@lexiflow.uz'],
      ['https://lexiflow.uz/support', 'https://lexiflow.uz/support'],
    ])('links %s', async (contact, href) => {
      const w = await render(me({ contact }))

      expect(w.get('[data-testid=paywall-contact]').text()).toContain('Учитесь в центре? Попросите администратора центра подключить LexiFlow Pro:')
      const link = w.get('[data-testid=paywall-contact-link]')
      expect(link.attributes('href')).toBe(href)
      expect(link.text()).toBe(contact)
      expect(link.attributes('rel')).toContain('noopener')
    })

    it('shows an unrecognised contact as plain text without a link', async () => {
      const w = await render(me({ contact: '+998 90 123 45 67' }))

      expect(w.find('[data-testid=paywall-contact-link]').exists()).toBe(false)
      expect(w.get('[data-testid=paywall-contact-text]').text()).toBe('+998 90 123 45 67')
    })

    it('never renders a contact as HTML or as a javascript: link', async () => {
      const w = await render(me({ contact: 'javascript:alert(1)' }))
      expect(w.find('a').exists()).toBe(false)
      expect(w.get('[data-testid=paywall-contact-text]').text()).toBe('javascript:alert(1)')

      const html = await render(me({ contact: '<img src=x onerror=alert(1)>' }))
      expect(html.find('img').exists()).toBe(false)
      expect(html.get('[data-testid=paywall-contact-text]').text()).toBe('<img src=x onerror=alert(1)>')
    })
  })

  describe('promo code', () => {
    async function redeem(w: Awaited<ReturnType<typeof render>>, code = 'PRO30') {
      await w.get('[data-testid=promo-toggle]').trigger('click')
      await w.get('[data-testid=promo-input]').setValue(code)
      await w.get('form').trigger('submit')
      await flushPromises()
    }

    it('embeds the promo field', async () => {
      const w = await render()

      expect(w.get('[data-testid=promo-toggle]').text()).toBe('Есть промокод?')
    })

    it('redeems a code, shows the new plan, refreshes /today and tells the parent', async () => {
      const pro = me({ plan: 'pro', until: '2026-12-31T12:00:00+00:00' })
      vi.mocked(api.promo.redeem).mockResolvedValue(pro)
      vi.mocked(api.today.get).mockResolvedValue({} as never)
      const w = await render(me({ contact: '@lexiflow_help' }))

      await redeem(w)

      expect(api.promo.redeem).toHaveBeenCalledWith('PRO30')
      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(w.emitted('redeemed')).toHaveLength(1)
      expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro')
      expect(w.get('[data-testid=promo-success]').text()).toMatch(/^Pro активен до .*2026/)
      // The "Pro is coming" copy and the contact for centers are gone once Pro is active.
      expect(w.find('[data-testid=paywall-lead]').exists()).toBe(false)
      expect(w.find('[data-testid=paywall-free]').exists()).toBe(false)
      expect(w.find('[data-testid=paywall-contact]').exists()).toBe(false)
    })

    it('keeps the sheet and shows the error for a rejected code, without refreshing anything', async () => {
      vi.mocked(api.promo.redeem).mockRejectedValue(new ApiClientError('not_found', 404, 'promo_not_found', 'x'))
      const w = await render()

      await redeem(w, 'NOPE')

      expect(w.get('[data-testid=promo-error]').text()).toBe('Промокод не найден. Проверьте, нет ли опечатки.')
      expect(api.today.get).not.toHaveBeenCalled()
      expect(w.emitted('redeemed')).toBeUndefined()
      expect(w.find('[data-testid=paywall-lead]').exists()).toBe(true)
    })

    it('puts the refreshed plan into the Today store', async () => {
      vi.mocked(api.promo.redeem).mockResolvedValue(me({ plan: 'pro' }))
      vi.mocked(api.today.get).mockResolvedValue({ new_words_remaining: null, entitlement: { plan: 'pro', source: 'subscription', until: null } } as never)
      const w = await render()

      await redeem(w)

      expect(useTodayStore().data?.entitlement.plan).toBe('pro')
    })
  })

  it('closes with the button and by tapping the backdrop, but not by tapping the sheet', async () => {
    const w = await render()

    await w.get('[data-testid=paywall]').trigger('click')
    expect(w.emitted('close')).toBeUndefined()

    await w.get('[data-testid=paywall-close]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)

    await w.get('[data-testid=paywall-backdrop]').trigger('click')
    expect(w.emitted('close')).toHaveLength(2)
  })

  describe('repeat words', () => {
    const withDue = (dueCount: number | null) => {
      if (dueCount !== null) useTodayStore().data = { due_count: dueCount } as never
    }

    it('offers a review when cards are due and tells the parent', async () => {
      withDue(5)
      const w = await render(me(), { reason: 'quota' })

      expect(w.get('[data-testid=paywall-review]').text()).toBe('Повторить слова')
      await w.get('[data-testid=paywall-review]').trigger('click')

      expect(w.emitted('review')).toHaveLength(1)
      expect(w.emitted('close')).toBeUndefined()
    })

    it.each([[0], [null]])('is not offered when nothing is due (%s)', async (due) => {
      withDue(due)
      const w = await render()

      expect(w.find('[data-testid=paywall-review]').exists()).toBe(false)
    })

    it('goes away once a promo code made the learner Pro', async () => {
      withDue(5)
      vi.mocked(api.promo.redeem).mockResolvedValue(me({ plan: 'pro' }))
      vi.mocked(api.today.get).mockResolvedValue({ due_count: 5 } as never)
      const w = await render()

      await w.get('[data-testid=promo-toggle]').trigger('click')
      await w.get('[data-testid=promo-input]').setValue('PRO30')
      await w.get('form').trigger('submit')
      await flushPromises()

      expect(w.find('[data-testid=paywall-review]').exists()).toBe(false)
    })

    it('renders in Uzbek', async () => {
      setLocale('uz')
      withDue(2)
      const w = await render()

      expect(w.get('[data-testid=paywall-review]').text()).toBe("So'zlarni takrorlash")
    })
  })

  describe('accessibility', () => {
    it('moves the focus into the dialog and closes on Escape', async () => {
      const w = mount(PaywallSheet, { attachTo: document.body })
      vi.mocked(api.me.get).mockResolvedValue(me())
      await flushPromises()

      expect(document.activeElement).toBe(w.get('[data-testid=paywall]').element)

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
      expect(w.emitted('close')).toBeUndefined()
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
      expect(w.emitted('close')).toHaveLength(1)

      w.unmount()
    })

    it('stops listening for Escape and gives the focus back when it is gone', async () => {
      const opener = document.createElement('button')
      document.body.appendChild(opener)
      opener.focus()
      const w = mount(PaywallSheet, { attachTo: document.body })
      await flushPromises()
      expect(document.activeElement).not.toBe(opener)

      w.unmount()

      expect(document.activeElement).toBe(opener)
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
      expect(w.emitted('close')).toBeUndefined()
      opener.remove()
    })
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const w = await render(me({ contact: '@lexiflow_help' }), { reason: 'quota' })

    expect(w.get('[data-testid=paywall-title]').text()).toBe('Pro tez orada')
    expect(w.get('[data-testid=paywall-free]').text()).toBe("Hozir sizda Free tarif: kuniga 20 ta yangi so'z, takrorlash cheklovsiz.")
    expect(w.get('[data-testid=paywall-contact]').text()).toContain("Markazda o'qiysizmi?")
    expect(w.get('[data-testid=paywall-close]').text()).toBe('Tushunarli')
  })
})
