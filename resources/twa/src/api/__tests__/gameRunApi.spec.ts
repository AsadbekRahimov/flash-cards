import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

/** The paths and bodies of docs/18 §12.8 for a run: what the store of a run relies on. */
const http = vi.hoisted(() => ({
  get: vi.fn().mockResolvedValue({ ok: 'get' }),
  post: vi.fn().mockResolvedValue({ ok: 'post' }),
  getBlob: vi.fn().mockResolvedValue(new Blob(['x'])),
}))

vi.mock('../client', () => ({ createApiClient: () => http }))

const { api } = await import('../index')

beforeEach(() => {
  setActivePinia(createPinia())
  http.get.mockClear()
  http.post.mockClear()
  http.getBlob.mockClear()
})

describe('api.gameRuns', () => {
  it('get reads one run', async () => {
    await api.gameRuns.get(9)
    expect(http.get).toHaveBeenCalledWith('/game/runs/9')
  })

  it('answer posts the body to the run, as a copy', async () => {
    const body = { request_id: 'r1', element_id: 'e1', time_spent_ms: 800, answer: { option_id: 'o1' } }

    await api.gameRuns.answer(9, body)

    expect(http.post).toHaveBeenCalledWith('/game/runs/9/answer', body, { timeoutMs: 10_000 })
    expect(http.post.mock.calls[0]?.[1]).not.toBe(body)
  })

  it('hint posts the request id and the element', async () => {
    await api.gameRuns.hint(9, { request_id: 'r2', element_id: 'e1' })
    expect(http.post).toHaveBeenCalledWith('/game/runs/9/hint', { request_id: 'r2', element_id: 'e1' }, { timeoutMs: 10_000 })
  })

  it('abandon confirms', async () => {
    await api.gameRuns.abandon(9)
    expect(http.post).toHaveBeenCalledWith('/game/runs/9/abandon', { confirm: true }, { timeoutMs: 10_000 })
  })

  it('audioBlob fetches the audio_url of the element as a blob, and can be stopped', async () => {
    const signal = new AbortController().signal

    const blob = await api.gameRuns.audioBlob('/api/twa/game/runs/9/audio/e3', signal)

    expect(http.getBlob).toHaveBeenCalledWith('/api/twa/game/runs/9/audio/e3', signal)
    expect(blob).toBeInstanceOf(Blob)
  })
})
