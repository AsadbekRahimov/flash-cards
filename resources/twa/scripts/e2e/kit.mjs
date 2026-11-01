// The kit of the browser e2e of the practice and the battle (docs/16 §14.4): the launch "like Telegram", the oracle of the right
// answers, a player (a browser context with a log of its API calls) and the report. No dependencies: Playwright is global
// (`createRequire`), the rest is node:*. The scenarios are in practice.mjs.
import crypto from 'node:crypto'
import { mkdirSync } from 'node:fs'
import { createRequire } from 'node:module'
import { join } from 'node:path'

// ---------------------------------------------------------------- the command line

/** `--name value` and `--flag` into an object; `--only a,b` stays a string. */
export function parseArgs(argv) {
  const args = {}
  for (let i = 0; i < argv.length; i++) {
    const token = argv[i]
    if (!token.startsWith('--')) continue
    const name = token.slice(2)
    const next = argv[i + 1]
    if (next === undefined || next.startsWith('--')) args[name] = true
    else {
      args[name] = next
      i++
    }
  }
  return args
}

// ------------------------------------------------------------------ small helpers

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
export const sel = (id) => `[data-testid="${id}"]`

/** Polls `fn` until it returns a truthy value (the state of the DOM or of the log, never a blind pause); throws after `timeout`. */
export async function until(fn, { timeout = 10_000, interval = 100, label = 'the condition' } = {}) {
  const end = Date.now() + timeout
  let last = null
  for (;;) {
    try {
      const value = await fn()
      if (value) return value
    } catch (error) {
      last = error
    }
    if (Date.now() > end) throw new E2EError(`timeout of ${timeout} ms: ${label}${last ? ` (${String(last.message).split('\n')[0]})` : ''}`)
    await sleep(interval)
  }
}

export class E2EError extends Error {}

/** `ru`: "1:05" / "0:07" -> seconds. */
export const clockSeconds = (text) => {
  const m = /(\d+):(\d{2})/.exec(text)
  return m ? Number(m[1]) * 60 + Number(m[2]) : null
}

// ---------------------------------------------------------- the launch of Telegram

/** The two built-in themes of Telegram (as in the visual audit of docs/14). */
export const THEMES = {
  light: {
    bg_color: '#ffffff', text_color: '#000000', hint_color: '#707579', link_color: '#3390ec',
    button_color: '#3390ec', button_text_color: '#ffffff', secondary_bg_color: '#f4f4f5',
    header_bg_color: '#ffffff', accent_text_color: '#3390ec', section_bg_color: '#ffffff',
    section_header_text_color: '#707579', subtitle_text_color: '#707579', destructive_text_color: '#e53935',
    bottom_bar_bg_color: '#ffffff', section_separator_color: '#e7e7e7',
  },
  dark: {
    bg_color: '#212121', text_color: '#ffffff', hint_color: '#aaaaaa', link_color: '#8774e1',
    button_color: '#8774e1', button_text_color: '#ffffff', secondary_bg_color: '#181818',
    header_bg_color: '#212121', accent_text_color: '#8774e1', section_bg_color: '#212121',
    section_header_text_color: '#8774e1', subtitle_text_color: '#aaaaaa', destructive_text_color: '#ff595a',
    bottom_bar_bg_color: '#212121', section_separator_color: '#000000',
  },
}

/** initData signed by the rules of Telegram: secret = HMAC_SHA256("WebAppData", bot_token), hash = hex(HMAC_SHA256(secret, check string)). */
export function signInitData(botToken, user) {
  const fields = {
    auth_date: String(Math.floor(Date.now() / 1000)),
    query_id: `AAE2E${crypto.randomBytes(5).toString('hex')}`,
    user: JSON.stringify(user),
  }
  const check = Object.keys(fields).sort().map((key) => `${key}=${fields[key]}`).join('\n')
  const secret = crypto.createHmac('sha256', 'WebAppData').update(botToken).digest()
  const hash = crypto.createHmac('sha256', secret).update(check).digest('hex')
  return new URLSearchParams({ ...fields, hash }).toString()
}

/** The `#tgWebApp…` fragment a Mini App is opened with. */
export function launchHash(botToken, user, theme) {
  return `#${new URLSearchParams({
    tgWebAppData: signInitData(botToken, user),
    tgWebAppVersion: '8.0',
    tgWebAppPlatform: 'ios',
    tgWebAppThemeParams: JSON.stringify(THEMES[theme]),
  })}`
}

/** What the page records about every `<audio>` the app creates (headless: no sound, but the clip has to decode). */
const AUDIO_HOOK = `(() => {
  const made = (window.__e2eAudio = [])
  const Native = window.Audio
  const Hooked = function (src) {
    const element = new Native(src)
    const events = []
    for (const name of ['loadedmetadata', 'canplay', 'playing', 'ended', 'error']) element.addEventListener(name, () => events.push(name))
    made.push({ element, events, src: String(src) })
    return element
  }
  Hooked.prototype = Native.prototype
  window.Audio = Hooked
})()`

// -------------------------------------------------------------------- the oracle

/**
 * The right answers. The API never sends them (docs/16 §12), so the script reads the snapshot of a session / of a battle from the
 * database of the stand - read only. Its only job is to know what to answer; what the screen says is judged by the screen.
 */
export class Oracle {
  constructor(file, { DatabaseSync }) {
    this.db = new DatabaseSync(file, { readOnly: true })
    this.db.exec('PRAGMA busy_timeout = 5000')
  }

  all(sql, ...params) {
    return this.db.prepare(sql).all(...params)
  }

  get(sql, ...params) {
    return this.db.prepare(sql).get(...params) ?? null
  }

  learner(telegramId) {
    const row = this.get('SELECT id, telegram_user_id, first_name, last_name, username FROM learners WHERE telegram_user_id = ?', telegramId)
    if (!row) throw new E2EError(`no learner with the telegram id ${telegramId} in the database of the stand`)
    return row
  }

  session(id) {
    const row = this.get('SELECT * FROM practice_sessions WHERE id = ?', id)
    if (!row) throw new E2EError(`no practice session #${id}`)
    return { ...row, snapshot: JSON.parse(row.snapshot), state: JSON.parse(row.state) }
  }

  /** The element the server is waiting for in a solo session (a round of pairs: the current round). */
  sessionElement(id) {
    const session = this.session(id)
    const element = session.snapshot.elements[session.state.current]
    if (!element) throw new E2EError(`session #${id} has no current element (index ${session.state.current})`)
    return element
  }

  attempts(sessionId) {
    return this.all('SELECT request_id, kind, element_id FROM practice_attempts WHERE session_id = ?', sessionId)
  }

  battle(id) {
    const row = this.get('SELECT * FROM battles WHERE id = ?', id)
    if (!row) throw new E2EError(`no battle #${id}`)
    return {
      ...row,
      snapshot: JSON.parse(row.snapshot),
      meta: JSON.parse(row.meta),
      participants: this.all('SELECT * FROM battle_participants WHERE battle_id = ? ORDER BY id', id),
      answers: this.all('SELECT * FROM battle_answers WHERE battle_id = ? ORDER BY round, id', id),
    }
  }

  /** The element of a round of a battle (rounds are numbered from 1). */
  battleElement(id, round) {
    const element = this.battle(id).snapshot.elements[round - 1]
    if (!element) throw new E2EError(`battle #${id} has no round ${round}`)
    return element
  }
}

// ---------------------------------------------------------------------- the API

/** The API of the stand called straight (not through a screen): the same token a Mini App gets. Used to cross-check what two screens say. */
export class Api {
  constructor({ base, botToken, user, ledger = [] }) {
    this.base = base
    this.botToken = botToken
    this.user = user
    this.token = null
    this.ledger = ledger
  }

  async call(method, path, body, { again = true } = {}) {
    if (this.token === null) {
      const auth = await fetch(`${this.base}/api/twa/auth`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ init_data: signInitData(this.botToken, this.user) }),
      })
      if (!auth.ok) throw new E2EError(`auth of ${this.user.first_name}: HTTP ${auth.status}`)
      this.token = (await auth.json()).token
    }
    const response = await fetch(`${this.base}/api/twa${path}`, {
      method,
      headers: { Authorization: `Bearer ${this.token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    this.ledger.push(Date.now())
    if (response.status === 401 && again) {
      // The token of a Mini App lives 15 minutes: a run that is longer signs in again, as the app does.
      this.token = null
      return this.call(method, path, body, { again: false })
    }
    const text = await response.text()
    let json = null
    try {
      json = JSON.parse(text)
    } catch {
      // not JSON: the status says enough
    }
    return { status: response.status, json, text }
  }

  get(path) {
    return this.call('GET', path)
  }

  post(path, body) {
    return this.call('POST', path, body ?? {})
  }
}

// --------------------------------------------------------------------- a player

/** A call to the API as the page made it. */
const brief = (entry) => `${entry.method} ${entry.path} -> ${entry.status || entry.failure}${entry.body ? ` ${String(entry.body).slice(0, 240)}` : ''}`

/**
 * One person with one browser context: a Telegram launch with a theme and a width, a log of what the page asked of the API
 * (`net`) and what broke in it (`errors`). The pages are mobile: touch, a phone's viewport, the time zone of Tashkent.
 */
export class Player {
  constructor({ name, who = null, role = null, telegramUser, browser, base, botToken, theme = 'light', width = 390, height = 844, out, tag = '', report, ledger = [] }) {
    // `role` names the pictures when the same screen is taken for different people (the challenger, the opponent), so that the light
    // and the dark picture of one screen are found side by side.
    Object.assign(this, { name, who, role, telegramUser, browser, base, botToken, theme, width, height, out, tag, report, ledger })
    this.net = []
    this.errors = []
    this.shots = []
    this.context = null
    this.page = null
  }

  async start() {
    this.context = await this.browser.newContext({
      viewport: { width: this.width, height: this.height },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: true,
      locale: 'ru-RU',
      timezoneId: 'Asia/Tashkent',
    })
    // The page asks telegram.org for telegram-web-app.js; the SDK of the bundle does the work (as in the audit of docs/14).
    await this.context.route('https://telegram.org/**', (route) => route.fulfill({ status: 200, contentType: 'application/javascript', body: '' }))
    await this.context.addInitScript(AUDIO_HOOK)
    this.page = await this.context.newPage()
    this.page.on('pageerror', (error) => this.errors.push({ at: Date.now(), kind: 'pageerror', text: error.message }))
    this.page.on('console', (message) => {
      // A 4xx / 5xx the test provoked is logged by the browser as an error too; the API log below says what it was.
      if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) this.errors.push({ at: Date.now(), kind: 'console.error', text: message.text() })
    })
    this.page.on('response', (response) => void this.#record(response))
    this.page.on('requestfailed', (request) => {
      const url = new URL(request.url())
      if (url.pathname.startsWith('/api/')) this.net.push({ at: Date.now(), method: request.method(), path: url.pathname + url.search, status: 0, failure: request.failure()?.errorText ?? 'failed', req: request.postData() })
    })
    return this
  }

  async #record(response) {
    const request = response.request()
    const url = new URL(response.url())
    if (!url.pathname.startsWith('/api/')) return
    const audio = url.pathname.includes('/audio/')
    let body = null
    if (!audio) {
      try {
        body = await response.text()
      } catch {
        body = null
      }
    }
    // What counts against the limit of the API (60 a minute for a learner): everything but the poll and the clips, which have limits of their own.
    if (!/\/poll$|\/audio\//.test(url.pathname)) this.ledger.push(Date.now())
    this.net.push({
      at: Date.now(),
      method: request.method(),
      path: url.pathname + url.search,
      status: response.status(),
      req: request.postData(),
      body,
      type: response.headers()['content-type'] ?? '',
      length: Number(response.headers()['content-length'] ?? 0),
      cache: response.headers()['cache-control'] ?? '',
    })
  }

  /** Opens `path` of the TWA the way Telegram opens a Mini App: with the signed initData and the theme in the fragment. */
  async open(path) {
    this.path = path
    await this.page.goto(`${this.base}${path}${launchHash(this.botToken, this.telegramUser, this.theme)}`, { waitUntil: 'domcontentloaded' })
    return this
  }

  /**
   * Goes to a screen of the app (a path under /twa/) the way a tap on a link does, without loading the page again: a new load signs in
   * again, and a sign-in is limited to 10 a minute for a person.
   */
  async go(path) {
    await this.page.evaluate((to) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(to), path)
  }

  async close() {
    await this.context?.close().catch(() => undefined)
    this.context = null
    this.page = null
  }

  // ------------------------------------------------------------------- the screen

  $(id) {
    return this.page.locator(sel(id)).first()
  }

  visible(id) {
    return this.$(id).isVisible()
  }

  async has(id) {
    return (await this.page.locator(sel(id)).count()) > 0
  }

  async text(id) {
    return (await this.$(id).innerText()).replace(/\s+/g, ' ').trim()
  }

  async attr(id, name) {
    return this.$(id).getAttribute(name)
  }

  /** `data-<name>` of an element that may not be there (no waiting): null when it is gone. */
  async data(id, name) {
    return this.page.evaluate(([testId, key]) => document.querySelector(`[data-testid="${testId}"]`)?.dataset[key] ?? null, [id, name])
  }

  /** The text of an element that may not be there (no waiting): null when it is gone. */
  async textNow(id) {
    return this.page.evaluate((testId) => document.querySelector(`[data-testid="${testId}"]`)?.innerText.replace(/\s+/g, ' ').trim() ?? null, id)
  }

  async waitFor(id, { timeout = 15_000, state = 'visible' } = {}) {
    try {
      await this.$(id).waitFor({ state, timeout })
    } catch {
      throw new E2EError(`${this.name}: no [${id}] (${state}) within ${timeout} ms; the screen says: "${await this.say()}"`)
    }
  }

  async click(id) {
    await this.$(id).click({ timeout: 10_000 })
  }

  /** The visible text of the screen, short: for the messages about what was not there. */
  async say() {
    try {
      return (await this.page.locator('body').innerText({ timeout: 2000 })).replace(/\s+/g, ' ').trim().slice(0, 220)
    } catch {
      return '<no page>'
    }
  }

  // ------------------------------------------------------------------ the API log

  mark() {
    return this.net.length
  }

  /** Calls of the page since `mark` that match the method and the path (a RegExp or a string). */
  calls(path, { method, since = 0 } = {}) {
    return this.net.slice(since).filter((entry) => (!method || entry.method === method) && (path instanceof RegExp ? path.test(entry.path) : entry.path.includes(path)))
  }

  async waitCall(path, { method, since = 0, timeout = 10_000, status } = {}) {
    return until(() => this.calls(path, { method, since }).find((entry) => status === undefined || entry.status === status), { timeout, label: `${this.name}: ${method ?? ''} ${path} ${status ?? ''}` })
  }

  /** The JSON of the last response of a call. */
  lastJson(path, { method, since = 0 } = {}) {
    const entry = this.calls(path, { method, since }).at(-1)
    if (!entry?.body) return null
    try {
      return JSON.parse(entry.body)
    } catch {
      return null
    }
  }

  /** What the page asked of the API lately, for a report (the polls are one line, not twenty). */
  recent(since = 0, limit = 8) {
    const lines = []
    for (const entry of this.net.slice(since)) {
      if (/\/poll\b/.test(entry.path) && lines.length > 0 && lines[lines.length - 1].startsWith('polling')) continue
      lines.push(/\/poll\b/.test(entry.path) ? `polling ${entry.status || entry.failure}: ${String(entry.body ?? '').slice(0, 200)}` : brief(entry))
    }
    return lines.slice(-limit)
  }

  // ------------------------------------------------------------------- the shots

  /**
   * A picture of the screen as the learner sees it: `<out>/<tag>--<name>--<label>--<width>-<theme>.png`, and when the page is longer than
   * the window one more for each scroll (`--p2`, `--p3`), because the buttons at the bottom stay in their place while the content moves
   * (a picture of the "whole page" would paint them over the middle of it). The path of the first one comes back.
   */
  async shot(label) {
    mkdirSync(this.out, { recursive: true })
    const base = join(this.out, `${this.tag ? `${this.tag}--` : ''}${this.role ?? this.name}--${label}--${this.width}-${this.theme}`)
    const first = `${base}.png`
    const room = await this.page
      .evaluate(() => ({ inner: innerHeight, total: document.documentElement.scrollHeight, bar: document.querySelector('[data-testid="actions"]')?.getBoundingClientRect().height ?? 0 }))
      .catch(() => null)
    if (room === null) return first
    await this.page.evaluate(() => window.scrollTo(0, 0)).catch(() => undefined)
    await this.settle()
    await this.page.screenshot({ path: first }).catch(() => undefined)
    this.shots.push(first)
    const last = room.total - room.inner
    const step = Math.max(200, room.inner - room.bar - 40)
    for (let n = 2, y = step; last > 4 && n <= 4; n++, y += step) {
      const at = Math.min(y, last)
      await this.page.evaluate((top) => window.scrollTo(0, top), at).catch(() => undefined)
      const file = `${base}--p${n}.png`
      await this.page.screenshot({ path: file }).catch(() => undefined)
      this.shots.push(file)
      if (at >= last) break
    }
    await this.page.evaluate(() => window.scrollTo(0, 0)).catch(() => undefined)
    return first
  }

  /** Waits (a second at most) for the transitions that are on the page to end, so that a picture is not of a bar half filled or a button half faded. */
  async settle() {
    await this.page
      .evaluate(() =>
        Promise.race([
          Promise.all(document.getAnimations().filter((animation) => Number.isFinite(animation.effect?.getComputedTiming().endTime ?? Infinity)).map((animation) => animation.finished.catch(() => undefined))),
          new Promise((resolve) => setTimeout(resolve, 1000)),
        ]),
      )
      .catch(() => undefined)
  }

  async overflow() {
    return this.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth).catch(() => 0)
  }

  /**
   * What the screen cuts off. The page clips the overflow sideways (`overflow-x: clip`), so a document that is "not wider than the
   * window" can still hide a card: `edges` are the visible elements that stick out of the window to the left or to the right, `clipped`
   * the texts that a box with `overflow: hidden` does not show whole (an ellipsis is a decision of the design, not always a fault).
   */
  async cutOff() {
    return this.page.evaluate(() => {
      const width = document.documentElement.clientWidth
      const name = (element) => {
        const id = element.closest('[data-testid]')?.dataset.testid ?? ''
        return `${element.tagName.toLowerCase()}${id ? `[${id}]` : ''} "${(element.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 40)}"`
      }
      const shown = (element) => {
        const style = getComputedStyle(element)
        const box = element.getBoundingClientRect()
        return style.visibility !== 'hidden' && style.display !== 'none' && Number(style.opacity) > 0.05 && box.width > 1 && box.height > 1
      }
      const edges = []
      const clipped = []
      for (const element of document.body.querySelectorAll('*')) {
        if (!shown(element) || element.closest('.sr-only, [aria-hidden="true"][class*="invisible"], svg')) continue
        const box = element.getBoundingClientRect()
        if (box.right > width + 1 || box.left < -1) edges.push(`${name(element)} ${Math.round(box.left)}..${Math.round(box.right)} of ${width}`)
        const style = getComputedStyle(element)
        if (element.children.length === 0 && (element.textContent ?? '').trim() !== '' && ['hidden', 'clip'].includes(style.overflowX) && element.scrollWidth > element.clientWidth + 1) {
          clipped.push(`${name(element)} ${element.scrollWidth} > ${element.clientWidth}`)
        }
      }
      return { edges: edges.slice(0, 6), clipped: clipped.slice(0, 6) }
    })
  }

  /** Proof that `cutOff()` sees something: a box that sticks out of the window and a text that its box cuts are put on the page, found, and taken away again. */
  async cutOffSees() {
    await this.page.evaluate(() => {
      const probe = document.createElement('div')
      probe.id = 'e2e-probe'
      probe.textContent = 'probe'
      probe.style.cssText = 'position:absolute;top:10px;left:80%;width:50%;height:20px;background:red'
      const clip = document.createElement('div')
      clip.id = 'e2e-probe-clip'
      clip.textContent = 'a text that does not fit into its box at all'
      clip.style.cssText = 'position:absolute;top:40px;left:0;width:40px;overflow:hidden;white-space:nowrap'
      document.body.append(probe, clip)
    })
    const cut = await this.cutOff()
    await this.page.evaluate(() => {
      document.getElementById('e2e-probe')?.remove()
      document.getElementById('e2e-probe-clip')?.remove()
    })
    return cut.edges.some((edge) => edge.includes('probe')) && cut.clipped.some((text) => text.includes('does not fit'))
  }

  /** The `<audio>` elements the app has made so far, with what each did (the hook of AUDIO_HOOK). */
  async audioPlayers() {
    return this.page.evaluate(() =>
      (window.__e2eAudio ?? []).map(({ element, events, src }) => ({
        src,
        events,
        duration: element.duration,
        readyState: element.readyState,
        error: element.error?.code ?? null,
      })),
    )
  }
}

// -------------------------------------------------------------------- the report

/**
 * Scenarios with PASS / FAIL and the findings behind a FAIL. `check` records a finding and goes on (the scenario shows
 * as many faults as it can); a wait that never ends throws and ends the scenario.
 */
export class Report {
  constructor({ out, log = console.log }) {
    this.out = out
    this.log = log
    this.rows = []
    this.findings = []
  }

  /** @param {string} name  @param {(s: Scene) => Promise<void>} body */
  async scenario(name, body, { skip = null } = {}) {
    if (skip) {
      this.rows.push({ name, status: 'SKIP', ms: 0, note: skip })
      this.log(`\n== ${name}: SKIP (${skip})`)
      return
    }
    const scene = new Scene(this, name)
    const started = Date.now()
    this.log(`\n== ${name}`)
    try {
      await body(scene)
    } catch (error) {
      await scene.crash(error)
    } finally {
      await scene.closeAll()
    }
    const failed = scene.findings.length > 0
    this.rows.push({ name, status: failed ? 'FAIL' : 'PASS', ms: Date.now() - started, note: `${scene.checks} checks${failed ? `, ${scene.findings.length} finding(s)` : ''}` })
    this.log(`== ${name}: ${failed ? 'FAIL' : 'PASS'} in ${((Date.now() - started) / 1000).toFixed(1)} s, ${scene.checks} checks`)
  }

  summary() {
    const width = Math.max(...this.rows.map((row) => row.name.length), 8)
    this.log('\n================ RESULT ================')
    for (const row of this.rows) this.log(`${row.status.padEnd(5)} ${row.name.padEnd(width)}  ${(row.ms / 1000).toFixed(1).padStart(6)} s  ${row.note}`)
    const total = this.rows.reduce((sum, row) => sum + row.ms, 0)
    this.log(`${'-'.repeat(width + 20)}\ntotal ${(total / 1000).toFixed(1)} s`)
    if (this.findings.length > 0) {
      this.log(`\n================ FINDINGS (${this.findings.length}) ================`)
      for (const finding of this.findings) {
        this.log(`\n#${finding.id} [${finding.scenario}] ${finding.message}`)
        if (finding.expected !== undefined) this.log(`   expected: ${finding.expected}`)
        if (finding.actual !== undefined) this.log(`   actual:   ${finding.actual}`)
        for (const line of finding.requests) this.log(`   | ${line}`)
        if (finding.shot) this.log(`   shot: ${finding.shot}`)
      }
    }
    return this.rows.every((row) => row.status !== 'FAIL') ? 0 : 1
  }
}

/** What a scenario holds: its players and its findings. */
export class Scene {
  constructor(report, name) {
    this.report = report
    this.name = name
    this.players = []
    this.findings = []
    this.checks = 0
    this.stepName = ''
  }

  /** @returns {Player} */
  add(player) {
    this.players.push(player)
    return player
  }

  /** A part of the scenario that runs beside the others (its own steps, players and findings; the findings count for the scenario too). */
  fork(label) {
    const child = new Scene(this.report, `${this.name}:${label}`)
    child.parent = this
    return child
  }

  /** The checks of a finished fork are the scenario's own. */
  absorb(child) {
    this.checks += child.checks
  }

  step(text) {
    this.stepName = text
    this.report.log(`   - ${this.parent ? `[${this.name.split(':').at(-1)}] ` : ''}${text}`)
  }

  note(text) {
    this.report.log(`     ${this.parent ? `[${this.name.split(':').at(-1)}] ` : ''}${text}`)
  }

  /** A soft check: a finding on failure, and the scenario goes on. Returns the truth of `condition`. */
  async check(condition, message, { players = [], expected, actual, since = 0 } = {}) {
    this.checks++
    if (condition) return true
    await this.finding(message, { players, expected, actual, since })
    return false
  }

  async finding(message, { players = [], expected, actual, since = 0 } = {}) {
    const id = this.report.findings.length + 1
    const requests = []
    let shot = null
    for (const player of players) {
      requests.push(...player.recent(since).map((line) => `${player.name}: ${line}`))
      if (player.page) shot = await player.shot(`FINDING-${id}`)
    }
    const finding = { id, scenario: this.name, step: this.stepName, message, expected, actual, requests, shot }
    for (let scene = this; scene; scene = scene.parent) scene.findings.push(finding)
    this.report.findings.push(finding)
    this.report.log(`     !! #${id} ${message}${expected !== undefined ? ` | expected ${expected}` : ''}${actual !== undefined ? ` | actual ${actual}` : ''}`)
  }

  /** A scenario that could not go on (a wait that timed out, a bug of the script). */
  async crash(error) {
    const text = error instanceof Error ? error.message : String(error)
    const where = this.stepName ? ` at "${this.stepName}"` : ''
    await this.finding(`${error instanceof E2EError ? 'stuck' : 'script error'}${where}: ${text}`, { players: this.players.filter((player) => player.page) })
    if (!(error instanceof E2EError) && error instanceof Error) this.report.log(error.stack)
  }

  /** The pages that broke, as findings (an uncaught error of the page is never expected). */
  async noPageErrors() {
    for (const player of this.players) {
      const broken = player.errors.filter((entry) => !entry.reported)
      for (const entry of broken) entry.reported = true
      if (broken.length > 0) await this.finding(`${player.name}: the page raised errors`, { players: [player], actual: broken.map((entry) => `${entry.kind}: ${entry.text}`).join(' | ') })
    }
  }

  /** Every call of the API that ended with 4xx / 5xx / no answer and was not expected. */
  async noUnexpectedStatuses(allowed = []) {
    for (const player of this.players) {
      for (const entry of player.net) {
        if (entry.reported || (entry.status >= 200 && entry.status < 400)) continue
        entry.reported = true
        if (allowed.some((rule) => rule.method === entry.method && rule.path.test(entry.path) && rule.status === entry.status)) continue
        await this.finding(`${player.name}: unexpected ${entry.status || entry.failure} for ${entry.method} ${entry.path}`, { players: [player], actual: String(entry.body ?? '').slice(0, 300) })
      }
    }
  }

  async closeAll() {
    for (const player of this.players) {
      const calls = player.net.filter((entry) => !/\/poll$|\/audio\//.test(entry.path)).length
      if (calls > 0) this.report.log(`     ${player.name}: ${calls} calls of the API (the limit is 60 a minute), ${player.net.length - calls} polls and clips`)
      await player.close()
    }
  }
}

/**
 * Calls of the API that end without a good status and are not faults: the question "continue or start a new one" of an unfinished session
 * (409), an answer whose request or reply the script drops on purpose, a clip that the page stops loading because the learner has gone
 * on (the review of a dictation loads the clip to listen again, and gives it up when it is left), and what a reload of the page cancels.
 */
export const EXPECTED_STATUSES = [
  { method: 'POST', path: /\/practice\/sessions$/, status: 409 },
  { method: 'POST', path: /\/answer$/, status: 0 },
  { method: 'GET', path: /\/audio\//, status: 0 },
  { method: 'GET', path: /\/poll$/, status: 0 },
  { method: 'GET', path: /\/round\?no=\d+$/, status: 0 },
  { method: 'GET', path: /\/battles\/\d+$/, status: 0 },
]

/** Playwright from the global modules (the stand does not install it): `NODE_PATH` or `npm root -g` of the machine. */
export function loadPlaywright() {
  const require = createRequire(import.meta.url)
  try {
    return require('playwright')
  } catch {
    const root = require('node:child_process').execSync('npm root -g', { encoding: 'utf8' }).trim()
    return require(require.resolve('playwright', { paths: [root] }))
  }
}
