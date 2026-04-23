/// <reference types="vite/client" />
/// <reference types="vitest/globals" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

declare global {
  interface Window {
    Telegram?: {
      WebApp?: unknown
    }
  }
}

export {}
