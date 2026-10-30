import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, effectScope } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { ofetch } from 'ofetch'
import { ApiClientError } from '@/api/client'
import { usePolling } from '@/composables/usePolling'
import { useBattleStore } from '../battle'
import { SERVER_NOW, answeringRound, battle, poll, roundState } from '@/api/__tests__/battleFixtures'

/*
 * The limits of the requests of a battle with the REAL client (only the network is a stub): a request on a dead connection hangs, and the
 * poll - which does not start another beside one that is on its way - would go quiet, the server would count the silence against the player,
 * and an answer would hold its task locked. The poll is given up after 8 s, an action after 10 s; both end as a lost connection (review Ж2).
 */

vi.mock('ofetch', () => ({ ofetch: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ token: 'jwt', login: vi.fn() }) }))

interface Sent {
  method?: string
  body?: Record<string, unknown>
  signal?: AbortSignal
}

/** Every request that went out: its path and what it was sent with. */
let calls: { path: string; options: Sent }[] = []
/** The requests that hang: by the end of the path, a number of the first tries that do (`answer`: 1 = the first one only). */
let hanging: Record<string, number> = {}

const ORDER = { order: ['fW2', 'fT5', 'fR4', 'fE3', 'fQ1'] }

function reply(path: string): unknown {
  if (path.endsWith('/poll')) return poll({ version: 24 })
  if (path.endsWith('/round')) return answeringRound()
  if (path.endsWith('/answer')) return { verdict: 'correct', points: 100, accepted_at: SERVER_NOW, round_resolved: false, poll: poll({ version: 24, round: roundState({ my_answered: true }) }) }
  return battle()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
  vi.setSystemTime(new Date(SERVER_NOW))
  calls = []
  hanging = {}
  vi.mocked(ofetch).mockReset()
  vi.mocked(ofetch).mockImplementation(((path: string, options: Sent) => {
    calls.push({ path, options })
    const key = Object.keys(hanging).find((suffix) => path.endsWith(suffix) && hanging[suffix]! > 0)
    if (key !== undefined) {
      hanging[key]!--
      // the connection is dead: nothing comes, and the request ends only when its signal says so (the way `fetch` ends)
      return new Promise((_resolve, reject) => {
        options.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')))
      })
    }
    return Promise.resolve(reply(path))
  }) as never)
})
afterEach(() => {
  vi.useRealTimers()
})

const sentTo = (suffix: string) => calls.filter((call) => call.path.endsWith(suffix))

async function opened() {
  const store = useBattleStore()
  await store.open(15)
  return store
}

describe('the limits of the requests of a battle, end to end', () => {
  it('an answer whose request hangs is given up after 10 s and goes again with the SAME request_id: the server counts it once', async () => {
    const store = await opened()
    hanging = { '/answer': 1 } // the first try hangs, the next gets through

    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(9999)
    expect(sentTo('/answer')).toHaveLength(1)
    expect(store.answerState).toBe('sending')
    await vi.advanceTimersByTimeAsync(1) // 10 s: the limit of an action
    expect(sentTo('/answer')[0]?.options.signal?.aborted).toBe(true)
    await vi.advanceTimersByTimeAsync(500) // the pause before the first repeat
    await sending

    const answers = sentTo('/answer')
    expect(answers).toHaveLength(2)
    expect(answers[1]?.options.body?.request_id).toBe(answers[0]?.options.body?.request_id)
    expect(answers[1]?.options.body).toMatchObject({ round: 2, answer: ORDER })
    expect(store.answerState).toBe('idle')
    expect(store.roundState?.my_answered).toBe(true)
    expect(store.actionError).toBe('')
  })

  it('an answer that hangs every time ends in “Retry”, as a lost connection does, and “Retry” sends the same request again', async () => {
    const store = await opened()
    hanging = { '/answer': 99 }

    const sending = store.answer(ORDER)
    await vi.advanceTimersByTimeAsync(10_000 * 4 + 500 + 1000 + 2000)
    await sending

    expect(sentTo('/answer')).toHaveLength(4) // the first try and three repeats
    expect(store.answerState).toBe('failed')
    expect(store.answerCause).toBe('network')
    expect(new Set(sentTo('/answer').map((call) => call.options.body?.request_id)).size).toBe(1)

    hanging = {}
    await store.retryAnswer()
    expect(store.answerState).toBe('idle')
    expect(sentTo('/answer')).toHaveLength(5)
    expect(sentTo('/answer')[4]?.options.body?.request_id).toBe(sentTo('/answer')[0]?.options.body?.request_id)
  })

  it('a poll that hangs is given up after 8 s: the strip says there is no connection, and nothing holds the next poll back', async () => {
    const store = await opened()
    hanging = { '/poll': 1 }

    const ticking = store.tick().catch((err: unknown) => err)
    await vi.advanceTimersByTimeAsync(7999)
    expect(store.ticking).toBe(true) // still waiting
    await vi.advanceTimersByTimeAsync(1)
    const err = await ticking

    expect(err).toBeInstanceOf(ApiClientError)
    expect(err).toMatchObject({ kind: 'network', code: 'timeout' })
    expect(store.offline).toBe(true)
    expect(store.offlineCause).toBe('network')
    expect(store.ticking).toBe(false)

    await store.tick() // the connection is back: the next poll goes out and answers
    expect(store.offline).toBe(false)
  })

  it('the beat of the poll slows down after the hung request (the pause doubles) and goes on; it does not pile requests up beside it', async () => {
    const store = await opened()
    hanging = { '/poll': 1 }
    const scope = effectScope()
    scope.run(() => usePolling(() => store.tick(), { enabled: computed(() => store.polling) }))

    await vi.advanceTimersByTimeAsync(2000) // the first beat: it hangs
    expect(sentTo('/poll')).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(7999) // a beat that finds the one before still on its way is skipped
    expect(sentTo('/poll')).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(1) // 10 s: given up; the pause is 4 s now
    expect(store.offline).toBe(true)
    await vi.advanceTimersByTimeAsync(3999)
    expect(sentTo('/poll')).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(1) // 14 s: the next beat
    expect(sentTo('/poll')).toHaveLength(2)
    await vi.advanceTimersByTimeAsync(0)
    expect(store.offline).toBe(false)

    scope.stop()
  })

  it('a battle that is read for the first time and hangs is told as a lost connection with “Retry”, not as a spinner for ever', async () => {
    hanging = { '/practice/battles/15': 1 }
    const store = useBattleStore()

    const opening = store.open(15)
    await vi.advanceTimersByTimeAsync(8000)
    await opening

    expect(store.loadStatus).toBe('error')
    expect(store.gone).toBe(false)
    expect(store.errorMessage).toBe('Нет связи с сервером. Попробуйте ещё раз.')
  })

  it('every request of a battle goes out with a signal of its own: whatever hangs can be cut off (the opening, the poll, the round, the answer)', async () => {
    const store = await opened()
    await store.tick()
    await store.answer(ORDER)

    expect(new Set(calls.map((call) => call.path.replace(/\d+/g, 'N'))).size).toBeGreaterThanOrEqual(4)
    for (const call of calls) expect(call.options.signal, call.path).toBeInstanceOf(AbortSignal)
  })
})
