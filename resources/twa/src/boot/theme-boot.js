/*
 * The start script of the Mini App (docs/14 §4.1, §4.2): a classic script, run in <head> right after telegram-web-app.js and
 * before the first paint, so a learner with a dark Telegram theme never sees a light frame while the bundle loads.
 *
 * - <html data-theme="light|dark">: inside Telegram (a platform other than "unknown") by Telegram.WebApp.colorScheme, outside
 *   it by prefers-color-scheme. The same choice as resolveColorScheme() of src/composables/telegramTheme.ts, which takes over
 *   once the bundle runs (theme changes, the Telegram header); telegramTheme.spec.ts runs both on the same cases.
 * - <meta name="theme-color">: the page colour of that palette (CHROME_COLORS.*.page, checked by the same test).
 * - The preload of the cyrillic Inter when the interface will be Russian: resolveLocale() of src/i18n gives "uz" for
 *   an uz* language_code and "ru" for anything else (no language_code included). The URL of the font comes from the
 *   data-cyrillic-font attribute of this script's tag (the hashed file name is put there by the build, vite.config.ts).
 *
 * Plain ES5 without modules: not processed by Vite as a module, minified and given a hashed name by the build. Anything that
 * fails leaves the page as it was (the bundle sets the theme anyway).
 */
;(function () {
  var app = null
  try {
    app = (window.Telegram && window.Telegram.WebApp) || null
  } catch (e) {
    app = null
  }

  try {
    var platform = app ? app.platform : ''
    var inside = typeof platform === 'string' && platform !== '' && platform !== 'unknown'
    var dark = inside
      ? app.colorScheme === 'dark'
      : typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches === true
    var scheme = dark ? 'dark' : 'light'
    document.documentElement.setAttribute('data-theme', scheme)
    var meta = document.querySelector('meta[name="theme-color"]')
    if (meta) meta.setAttribute('content', dark ? '#0f1420' : '#f4f6fb')
  } catch (e) {
    // keep the light default: the bundle sets the theme
  }

  try {
    var user = app && app.initDataUnsafe ? app.initDataUnsafe.user : null
    var code = user && typeof user.language_code === 'string' ? user.language_code : ''
    var uzbek = code.trim().toLowerCase().split(/[-_]/)[0] === 'uz'
    var tag = document.querySelector('script[data-cyrillic-font]')
    var href = tag ? tag.getAttribute('data-cyrillic-font') : ''
    if (!uzbek && href) {
      var link = document.createElement('link')
      link.rel = 'preload'
      link.as = 'font'
      link.type = 'font/woff2'
      link.crossOrigin = 'anonymous'
      link.href = href
      document.head.appendChild(link)
    }
  } catch (e) {
    // no preload: the font still comes through @font-face when the page needs it
  }
})()
