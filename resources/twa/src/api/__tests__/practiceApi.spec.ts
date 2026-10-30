import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

/** The paths, queries and bodies of docs/16 §7: what the stores of practice and battle rely on. */
const http = vi.hoisted(() => ({
  get: vi.fn().mockResolvedValue({ ok: 'get' }),
  post: vi.fn().mockResolvedValue({ ok: 'post' }),
  getBlob: vi.fn().mockResolvedValue(new Blob(['x'])),
}))

vi.mock('../client', () => ({ createApiClient: () => http }))

// Imported after the mock, so that `api` builds its client on the stub above.
const { api } = await import('../index')

beforeEach(() => {
  setActivePinia(createPinia())
  http.get.mockClear()
  http.post.mockClear()
  http.getBlob.mockClear()
})

describe('api.practice', () => {
  it('hub: the course, and the lesson only when there is one', async () => {
    await api.practice.hub('english-a1')
    await api.practice.hub('english-a1', 41)

    expect(http.get).toHaveBeenNthCalledWith(1, '/practice/hub', { course: 'english-a1' })
    expect(http.get).toHaveBeenNthCalledWith(2, '/practice/hub', { course: 'english-a1', lesson_id: 41 })
  })

  it('open posts the body as given (a copy), get reads one session', async () => {
    const body = { mode: 'pairs', course: 'english-a1', lesson_id: 41, source: 'hub', replace_active: false } as const

    await api.practice.open(body)
    await api.practice.get(77)

    expect(http.post).toHaveBeenCalledWith('/practice/sessions', body)
    expect(http.post.mock.calls[0]?.[1]).not.toBe(body)
    expect(http.get).toHaveBeenCalledWith('/practice/sessions/77')
  })

  it('answer and replace go to the session', async () => {
    const answer = { request_id: 'r1', element_id: 'eK7', time_spent_ms: 800, answer: { order: ['f1', 'f2'] } }
    const replace = { request_id: 'r2', element_id: 'eD4', reason: 'audio_unavailable' } as const

    await api.practice.answer(77, answer)
    await api.practice.replace(77, replace)

    expect(http.post).toHaveBeenNthCalledWith(1, '/practice/sessions/77/answer', answer)
    expect(http.post).toHaveBeenNthCalledWith(2, '/practice/sessions/77/replace', replace)
  })

  it('audioBlob fetches the audio_url of the element as a blob', async () => {
    const blob = await api.practice.audioBlob('/api/twa/practice/sessions/77/audio/eD4')

    expect(http.getBlob).toHaveBeenCalledWith('/api/twa/practice/sessions/77/audio/eD4', undefined)
    expect(blob).toBeInstanceOf(Blob)
  })

  it('audioBlob hands the signal of the caller to the client, which stops the request with it', async () => {
    const controller = new AbortController()

    await api.practice.audioBlob('/api/twa/practice/sessions/77/audio/eD4', controller.signal)

    expect(http.getBlob).toHaveBeenCalledWith('/api/twa/practice/sessions/77/audio/eD4', controller.signal)
  })
})

describe('api.battles', () => {
  it('options: friendship, course and an optional lesson', async () => {
    await api.battles.options(11, 'english-a1')
    await api.battles.options(11, 'english-a1', 41)

    expect(http.get).toHaveBeenNthCalledWith(1, '/practice/battles/options', { friendship_id: 11, course: 'english-a1' })
    expect(http.get).toHaveBeenNthCalledWith(2, '/practice/battles/options', { friendship_id: 11, course: 'english-a1', lesson_id: 41 })
  })

  it('reads the list, a battle, its poll and a round', async () => {
    await api.battles.list()
    await api.battles.get(15)
    await api.battles.poll(15)
    await api.battles.round(15, 2)

    expect(http.get.mock.calls).toEqual([
      ['/practice/battles'],
      ['/practice/battles/15'],
      ['/practice/battles/15/poll'],
      ['/practice/battles/15/round', { no: 2 }],
    ])
  })

  it('invites, and runs the actions of the room without a body', async () => {
    const invite = { friendship_id: 11, mode: 'sentence', course: 'english-a1', lesson_id: 41, rematch_of: null } as const

    await api.battles.invite(invite)
    await api.battles.accept(15)
    await api.battles.decline(15)
    await api.battles.cancel(15)
    await api.battles.ready(15)

    expect(http.post.mock.calls).toEqual([
      ['/practice/battles', invite],
      ['/practice/battles/15/accept'],
      ['/practice/battles/15/decline'],
      ['/practice/battles/15/cancel'],
      ['/practice/battles/15/ready'],
    ])
  })

  it('surrender confirms; answer and audio-failed carry their bodies', async () => {
    const answer = { request_id: 'r1', round: 2, answer: { pairs: { l1: 'r1' } } }
    const failed = { request_id: 'r2', element_id: 'eD4' }

    await api.battles.surrender(15)
    await api.battles.answer(15, answer)
    await api.battles.audioFailed(15, failed)

    expect(http.post.mock.calls).toEqual([
      ['/practice/battles/15/surrender', { confirm: true }],
      ['/practice/battles/15/answer', answer],
      ['/practice/battles/15/audio-failed', failed],
    ])
  })

  it('audioBlob fetches a clip of a round', async () => {
    await api.battles.audioBlob('/api/twa/practice/battles/15/audio/eD4')

    expect(http.getBlob).toHaveBeenCalledWith('/api/twa/practice/battles/15/audio/eD4')
  })
})
