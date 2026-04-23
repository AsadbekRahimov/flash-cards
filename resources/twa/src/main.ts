import { createApp } from 'vue'
import { createPinia } from 'pinia'
import WebApp from '@twa-dev/sdk'
import App from './App.vue'
import router from './router'
import './style.css'

// Initialize Telegram Web App. If opened outside Telegram these are no-ops
// (the landing view will detect the missing initData and show a hint).
try {
  WebApp.ready()
  WebApp.expand()
  applyTelegramTheme()
} catch {
  // running outside Telegram (dev, Playwright) — ignore
}

/**
 * Copy Telegram theme colors into CSS variables so Tailwind `tg-*`
 * utilities and component classes pick them up automatically.
 */
function applyTelegramTheme(): void {
  const params = WebApp.themeParams
  const root = document.documentElement
  const map: Record<string, string | undefined> = {
    '--tg-bg': params.bg_color,
    '--tg-text': params.text_color,
    '--tg-hint': params.hint_color,
    '--tg-link': params.link_color,
    '--tg-button': params.button_color,
    '--tg-button-text': params.button_text_color,
    '--tg-secondary-bg': params.secondary_bg_color,
  }
  for (const [name, value] of Object.entries(map)) {
    if (value) root.style.setProperty(name, value)
  }
}

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
