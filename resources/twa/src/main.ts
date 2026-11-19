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
} catch {
  // running outside Telegram (dev, Playwright) — ignore
}

// Theme parameters -> CSS variables, <html data-theme>, header colour; follows `themeChanged` (docs/14 §4.1).
initTelegramTheme()

// Interface language follows Telegram's language_code (uz* -> uz, otherwise ru).
initLocale(useTelegram().languageCode())

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
