import { createApp } from 'vue'
import { createPinia } from 'pinia'
import WebApp from '@twa-dev/sdk'
import App from './App.vue'
import router from './router'
import { initLocale } from './i18n'
import { useTelegram } from './composables/useTelegram'
import { initTelegramTheme } from './composables/telegramTheme'
import { startTimingMarks } from './composables/useTimingMarks'
import './style.css'

// The timing marks of this open (docs/04 A.21) start first: the offsets are from the navigation start, the open id and the
// platform are taken now. Nothing is sent unless `/auth` says this open is in the sample.
startTimingMarks()

// Initialize Telegram Web App. If opened outside Telegram these are no-ops
// (the landing view will detect the missing initData and show a hint).
try {
  WebApp.ready()
  WebApp.expand()
  // A swipe down must not close the app in the middle of a lesson (kit TELEGRAM.md); Bot API 7.7+.
  if (WebApp.isVersionAtLeast('7.7')) WebApp.disableVerticalSwipes()
} catch {
  // running outside Telegram (dev, Playwright) — ignore
}

// The app's own palette: <html data-theme> from Telegram's colorScheme (outside Telegram: prefers-color-scheme), the header and
// window background in the page colour (white lesson screens: `useChromeSurface`); follows `themeChanged` (docs/14 §4.1). The first
// data-theme was already set before the first paint by the start script (src/boot/theme-boot.js); this takes over from it.
initTelegramTheme()

// Interface language follows Telegram's language_code (uz* -> uz, otherwise ru).
initLocale(useTelegram().languageCode())

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
