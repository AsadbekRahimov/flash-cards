/// <reference types="vite/client" />
/// <reference types="vitest/globals" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

declare global {
  interface ImportMetaEnv {
    /** The product name (docs/36 §5б); vite.config.ts defaults it to Muhrla. */
    readonly VITE_BRAND?: string
  }

  interface Window {
    Telegram?: {
      WebApp?: unknown
    }
  }
}

export {}
