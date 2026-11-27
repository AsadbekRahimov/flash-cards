import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import PageHeader from '../PageHeader.vue'
import { BRAND } from '@/brand'
import { setLocale } from '@/i18n'

/** The language sheet itself is tested in LanguageSheet.spec.ts: here it is a stub that says it is open and can close. */
const LanguageSheetStub = defineComponent({
  name: 'LanguageSheet',
  emits: ['close'],
  setup(_props, { emit }) {
    return () => h('section', { 'data-testid': 'language-sheet' }, [h('button', { 'data-testid': 'sheet-close', onClick: () => emit('close') })])
  },
})

const stubs = { LanguageSheet: LanguageSheetStub }

describe('PageHeader', () => {
  afterEach(() => setLocale('ru'))

  it('renders the title as the screen heading, 22px Unbounded semibold by default', () => {
    const w = mount(PageHeader, { props: { title: 'Настройки' }, global: { stubs } })

    const h1 = w.get('h1')
    expect(h1.text()).toBe('Настройки')
    expect(h1.classes()).toEqual(expect.arrayContaining(['font-display', 'text-[22px]', 'font-semibold']))
    expect(h1.classes()).not.toContain('t-display-l')
    expect(w.find('p').exists()).toBe(false)
    expect(w.text()).toBe('Настройки')
  })

  it('is Display/L of the kit (Unbounded 600, 30 / 36, -2 %) for a tab root (`large`)', () => {
    const w = mount(PageHeader, { props: { title: 'Курсы', large: true }, global: { stubs } })

    expect(w.get('h1').classes()).toEqual(['t-display-l'])
    expect(w.get('h1').classes()).not.toContain('text-[22px]')
  })

  it('sets the title in the sans face of the app, never a serif', () => {
    for (const large of [false, true]) {
      const w = mount(PageHeader, { props: { title: 'A', large }, global: { stubs } })
      expect(w.html()).not.toMatch(/font-serif/)
    }
  })

  it('shows the subtitle under the title, in the secondary colour', () => {
    const w = mount(PageHeader, { props: { title: 'Практика', subtitle: 'На словах, которые вы уже учили: 120' }, global: { stubs } })

    const subtitle = w.get('[data-testid=subtitle]')
    expect(subtitle.text()).toBe('На словах, которые вы уже учили: 120')
    expect(subtitle.classes()).toContain('text-muted')
    expect(w.html().indexOf('<h1')).toBeLessThan(w.html().indexOf('data-testid="subtitle"'))
  })

  it('shows the eyebrow above the title', () => {
    const w = mount(PageHeader, {
      props: { title: 'Добрый день, Дилноза', eyebrow: 'Пятница, 20 ноября · Английский B1', large: true },
      global: { stubs },
    })

    const eyebrow = w.get('[data-testid=eyebrow]')
    expect(eyebrow.text()).toBe('Пятница, 20 ноября · Английский B1')
    expect(eyebrow.classes()).toContain('text-muted')
    expect(w.html().indexOf('data-testid="eyebrow"')).toBeLessThan(w.html().indexOf('<h1'))
    expect(w.find('[data-testid=subtitle]').exists()).toBe(false)
  })

  it('renders the trailing slot on the right, only when given', () => {
    const without = mount(PageHeader, { props: { title: 'A' }, global: { stubs } })
    expect(without.find('[data-testid=chip]').exists()).toBe(false)

    const w = mount(PageHeader, {
      props: { title: 'A' },
      slots: { trailing: '<span class="chip" data-testid="chip">Pro</span>' },
      global: { stubs },
    })
    expect(w.get('[data-testid=chip]').text()).toBe('Pro')
  })

  it('passes attributes to the root', () => {
    const w = mount(PageHeader, { props: { title: 'A' }, attrs: { 'data-testid': 'header' }, global: { stubs } })

    expect(w.attributes('data-testid')).toBe('header')
  })

  describe('the brand row of a tab root', () => {
    it('is not there unless asked for: an inner screen keeps its plain heading', () => {
      const w = mount(PageHeader, { props: { title: 'Настройки', large: true }, global: { stubs } })

      expect(w.find('[data-testid=brand-row]').exists()).toBe(false)
      expect(w.find('[data-testid=lang-btn]').exists()).toBe(false)
      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)
    })

    it('shows the logo and the product name of brand.ts above the title', () => {
      const w = mount(PageHeader, { props: { title: 'Привет, Азиз!', large: true, brand: true }, global: { stubs } })

      expect(w.get('[data-testid=brand-name]').text()).toBe(BRAND)
      expect(BRAND).toBe('Muhrla')
      expect(w.get('[data-testid=brand-logo]').attributes('aria-hidden')).toBe('true')
      expect(w.get('[data-testid=brand-logo]').classes()).toContain('text-accent')
      expect(w.html().indexOf('data-testid="brand-row"')).toBeLessThan(w.html().indexOf('<h1'))
      expect(w.get('h1').text()).toBe('Привет, Азиз!')
    })

    it.each([
      ['ru', 'RU'],
      ['uz', 'UZ'],
    ] as const)('names the interface language on the pill: %s -> %s', (locale, label) => {
      setLocale(locale)
      const w = mount(PageHeader, { props: { title: 'A', brand: true }, global: { stubs } })

      const pill = w.get('[data-testid=lang-btn]')
      expect(pill.text()).toBe(label)
      expect(pill.element.tagName).toBe('BUTTON')
      expect(pill.attributes('type')).toBe('button')
      // bilingual: readable in the language the learner cannot read
      expect(pill.attributes('aria-label')).toBe('Til · Язык')
      expect(pill.attributes('aria-haspopup')).toBe('dialog')
      // a 36 px pill with a 44 px touch area
      expect(pill.classes()).toEqual(expect.arrayContaining(['tap-target', 'h-9', 'rounded-full', 'bg-sunken']))
    })

    it('opens the language sheet on the pill, and lets it close', async () => {
      const w = mount(PageHeader, { props: { title: 'A', brand: true }, global: { stubs } })
      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)

      await w.get('[data-testid=lang-btn]').trigger('click')
      expect(w.find('[data-testid=language-sheet]').exists()).toBe(true)

      await w.get('[data-testid=sheet-close]').trigger('click')
      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)
    })

    it('follows a switch of the language at once', async () => {
      const w = mount(PageHeader, { props: { title: 'A', brand: true }, global: { stubs } })
      expect(w.get('[data-testid=lang-btn]').text()).toBe('RU')

      setLocale('uz')
      await w.vm.$nextTick()

      expect(w.get('[data-testid=lang-btn]').text()).toBe('UZ')
    })
  })
})
