import { describe, expect, it, vi, type Mock } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { defineComponent, h, nextTick, ref, type Ref } from 'vue'
import { mount } from '@vue/test-utils'
import { CHROME_COLORS, chromeTone, initTelegramTheme, type ThemeHost } from '../telegramTheme'
import { useChromeSurface } from '../useChromeSurface'

interface StubHost extends ThemeHost {
  setHeaderColor: Mock<(color: 'bg_color' | 'secondary_bg_color' | `#${string}`) => void>
  onEvent: Mock<(event: 'themeChanged', handler: () => void) => void>
}

/** A Telegram client of the given Bot API version, in the given scheme. */
function telegram(colorScheme: 'light' | 'dark', version = 9): StubHost {
  const host = {
    colorScheme,
    platform: 'android',
    isVersionAtLeast: (wanted: string) => version >= Number(wanted),
    setHeaderColor: vi.fn(),
    setBackgroundColor: vi.fn(),
    onEvent: vi.fn(),
  } as StubHost
  initTelegramTheme(host, document.createElement('div'), null)
  return host
}

/** A screen that is white while `white` is true (the default: always). */
function screen(white?: Ref<boolean>) {
  return mount(
    defineComponent({
      setup() {
        useChromeSurface(white)
        return () => h('section')
      },
    }),
  )
}

describe('useChromeSurface', () => {
  it('paints the header and the window background white on a white screen, back to the page colour when it goes away', () => {
    const host = telegram('light')

    const w = screen()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.surface)
    expect(host.setBackgroundColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.surface)

    w.unmount()
    expect(chromeTone()).toBe('page')
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.page)
    expect(host.setBackgroundColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.page)
  })

  it('follows a screen that turns to the page colour (the summary) and back', async () => {
    const host = telegram('dark')
    const white = ref(true)
    const w = screen(white)
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.surface)

    white.value = false
    await nextTick()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.page)

    white.value = true
    await nextTick()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.surface)

    w.unmount()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.page)
  })

  it('does not paint at all while the screen is not white', () => {
    const host = telegram('light')
    host.setHeaderColor.mockClear()

    const w = screen(ref(false))
    expect(host.setHeaderColor).not.toHaveBeenCalled()

    w.unmount()
    expect(host.setHeaderColor).not.toHaveBeenCalled()
    expect(chromeTone()).toBe('page')
  })

  it('switches to the surface of the new palette when the Telegram theme changes on the open screen', () => {
    const host = telegram('light')
    const w = screen()

    host.colorScheme = 'dark'
    ;(host.onEvent.mock.calls[0]?.[1] as () => void)()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.surface)

    w.unmount()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.dark.page)
  })

  it("gives a 6.1-6.8 client the keyword of Telegram's card colour for the header and the hex background", () => {
    const host = telegram('light', 6.1)

    const w = screen()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith('bg_color')
    expect(host.setBackgroundColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.surface)

    w.unmount()
    expect(host.setHeaderColor).toHaveBeenLastCalledWith('secondary_bg_color')
  })

  it('keeps the white while the next white screen is already there (the order of mount and unmount does not matter)', () => {
    const host = telegram('light')
    const first = screen()
    const second = screen()

    first.unmount()
    expect(chromeTone()).toBe('surface')
    expect(host.setHeaderColor).toHaveBeenLastCalledWith(CHROME_COLORS.light.surface)

    second.unmount()
    expect(chromeTone()).toBe('page')
  })
})

describe('the white screens use it', () => {
  // vitest runs with cwd = resources/twa
  const views = readdirSync(join(process.cwd(), 'src', 'views'))
    .filter((file) => file.endsWith('.vue'))
    .map((file) => ({ file, code: readFileSync(join(process.cwd(), 'src', 'views', file), 'utf8') }))

  it.each(['BattleView.vue', 'GameRunView.vue'])('%s', (file) => {
    expect(views.find((view) => view.file === file)?.code).toMatch(/\buseChromeSurface\(/)
  })

  // The lesson, the exam and the practice stand on the canvas of the kit Muhrla (04-urok-vopros): the chrome keeps the page colour.
  it.each(['TrainingView.vue', 'ExamView.vue', 'PracticeSessionView.vue'])('%s stands on the canvas and leaves the chrome alone', (file) => {
    const code = views.find((view) => view.file === file)?.code ?? ''
    expect(code).not.toMatch(/\buseChromeSurface\(/)
    expect(code).toContain('bg-page')
  })

  it('every screen whose own background is white (a static or bound `bg-surface` on the whole screen) uses it', () => {
    const white = views.filter(({ code }) => /flex-1 flex-col bg-surface"|[?:] 'bg-surface'/.test(code))
    expect(white.length).toBeGreaterThanOrEqual(2)
    for (const { file, code } of white) expect(code, file).toMatch(/\buseChromeSurface\(/)
  })
})
