import { createApp } from 'vue'
import { createPinia } from 'pinia'
import WebApp from '@twa-dev/sdk'
import App from './App.vue'
import router from './router'
import { initLocale } from './i18n'
import { useTelegram } from './composables/useTelegram'
import { initTelegramTheme } from './composables/telegramTheme'
import './style.css'

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
