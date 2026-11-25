import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useShare } from '../useShare'

const tg = vi.hoisted(() => ({
  canShareMessage: vi.fn(),
  shareMessage: vi.fn(),
  shareUrl: vi.fn(),
  calls: [] as string[],
}))

vi.mock('@/api', () => ({ api: { share: { prepare: vi.fn(), event: vi.fn() } } }))

vi.mock('../useTelegram', () => ({
  useTelegram: () => ({ canShareMessage: tg.canShareMessage, shareMessage: tg.shareMessage, shareUrl: tg.shareUrl }),
}))

const FALLBACK = { link: 'https://t.me/LexiFlowBot?start=ref_ABCD2345', text: 'Я получил(а) «Первый шаг»' }

const error = (kind: ApiClientError['kind'], status: number, code: string) => new ApiClientError(kind, status, code, code)

/** A promise and the hands that settle it, to hold a step half-way. */
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe('useShare', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    tg.calls = []
    tg.canShareMessage.mockReturnValue(true)
    vi.mocked(api.share.prepare).mockImplementation(async () => {
      tg.calls.push('prepare')
      return { id: 'prep-1', expires_at: '2026-11-25T12:00:00+00:00' }
    })
    tg.shareMessage.mockImplementation(async () => {
      tg.calls.push('shareMessage')
      return true
    })
    vi.mocked(api.share.event).mockImplementation(async (body) => {
      tg.calls.push(`event:${body.event}`)
    })
  })

  it('prepares the message, sends it, marks it shared and reports "sent" - in this order', async () => {
    const onShared = vi.fn(() => tg.calls.push('shared'))
    const { share } = useShare()

    await expect(share('achievement', 'first_session', FALLBACK, onShared)).resolves.toBe('sent')

    expect(api.share.prepare).toHaveBeenCalledWith('achievement', 'first_session')
    expect(tg.shareMessage).toHaveBeenCalledWith('prep-1')
    expect(onShared).toHaveBeenCalledTimes(1)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'sent', kind: 'achievement' })
    expect(tg.calls).toEqual(['prepare', 'shareMessage', 'shared', 'event:sent'])
    expect(tg.shareUrl).not.toHaveBeenCalled()
  })

  it('prepares an invitation with no subject', async () => {
    const { share } = useShare()

    await share('invite', null, FALLBACK)

    expect(api.share.prepare).toHaveBeenCalledWith('invite', null)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'sent', kind: 'invite' })
  })

  it('opens the plain dialog at once on an old client: no prepare, the mark as before, "fallback old_client"', async () => {
    tg.canShareMessage.mockReturnValue(false)
    const onShared = vi.fn()
    const { share } = useShare()

    await expect(share('game_city', '3', FALLBACK, onShared)).resolves.toBe('fallback')

    expect(api.share.prepare).not.toHaveBeenCalled()
    expect(tg.shareMessage).not.toHaveBeenCalled()
    expect(tg.shareUrl).toHaveBeenCalledWith(FALLBACK.link, FALLBACK.text)
    expect(onShared).toHaveBeenCalledTimes(1)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'fallback', kind: 'game_city', reason: 'old_client' })
  })

  it.each([
    ['403 share_unavailable', error('forbidden', 403, 'share_unavailable')],
    ['404 share_subject_not_found', error('not_found', 404, 'share_subject_not_found')],
    ['422 validation', error('validation', 422, 'validation_failed')],
    ['429', error('rate_limited', 429, 'too_many_requests')],
    ['503 share_failed', error('server', 503, 'share_failed')],
    ['the network', error('network', 0, 'network')],
  ])('takes the plain dialog once, with the same link and text, when prepare fails with %s', async (_name, failure) => {
    vi.mocked(api.share.prepare).mockRejectedValue(failure)
    const onShared = vi.fn()
    const { share } = useShare()

    await expect(share('achievement', 'first_session', FALLBACK, onShared)).resolves.toBe('fallback')

    expect(api.share.prepare).toHaveBeenCalledTimes(1)
    expect(tg.shareMessage).not.toHaveBeenCalled()
    expect(tg.shareUrl).toHaveBeenCalledTimes(1)
    expect(tg.shareUrl).toHaveBeenCalledWith(FALLBACK.link, FALLBACK.text)
    expect(onShared).toHaveBeenCalledTimes(1)
    expect(api.share.event).toHaveBeenCalledTimes(1)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'fallback', kind: 'achievement', reason: 'error' })
  })

  it('takes the plain dialog when Telegram could not send the message', async () => {
    tg.shareMessage.mockRejectedValue(new Error('MESSAGE_EXPIRED'))
    const onShared = vi.fn()
    const { share } = useShare()

    await expect(share('friend_invite', null, FALLBACK, onShared)).resolves.toBe('fallback')

    expect(tg.shareUrl).toHaveBeenCalledWith(FALLBACK.link, FALLBACK.text)
    expect(onShared).toHaveBeenCalledTimes(1)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'fallback', kind: 'friend_invite', reason: 'error' })
  })

  it('does nothing more when the learner closes the chat picker: no mark, no dialog, no event', async () => {
    tg.shareMessage.mockResolvedValue(false)
    const onShared = vi.fn()
    const { share, pending } = useShare()

    await expect(share('achievement', 'first_session', FALLBACK, onShared)).resolves.toBe('cancelled')

    expect(onShared).not.toHaveBeenCalled()
    expect(tg.shareUrl).not.toHaveBeenCalled()
    expect(api.share.event).not.toHaveBeenCalled()
    expect(pending.value).toBe(false)
  })

  it('ignores a second tap while the first share is on its way: one request', async () => {
    const prepared = deferred<{ id: string; expires_at: string }>()
    vi.mocked(api.share.prepare).mockReturnValue(prepared.promise)
    const { share, pending } = useShare()

    const first = share('invite', null, FALLBACK)
    expect(pending.value).toBe(true)
    await expect(share('invite', null, FALLBACK)).resolves.toBe('busy')

    prepared.resolve({ id: 'prep-1', expires_at: '2026-11-25T12:00:00+00:00' })
    await expect(first).resolves.toBe('sent')

    expect(api.share.prepare).toHaveBeenCalledTimes(1)
    expect(tg.shareMessage).toHaveBeenCalledTimes(1)
    expect(pending.value).toBe(false)
  })

  it('is free again after a failure', async () => {
    vi.mocked(api.share.prepare).mockRejectedValueOnce(error('server', 503, 'share_failed'))
    const { share, pending } = useShare()

    await share('invite', null, FALLBACK)
    expect(pending.value).toBe(false)
    await expect(share('invite', null, FALLBACK)).resolves.toBe('sent')
  })

  it('does not wait for the report and survives its failure', async () => {
    vi.mocked(api.share.event).mockReturnValue(new Promise(() => undefined))
    const onShared = vi.fn()
    const { share } = useShare()

    await expect(share('achievement', 'first_session', FALLBACK, onShared)).resolves.toBe('sent')
    expect(onShared).toHaveBeenCalledTimes(1)

    vi.mocked(api.share.event).mockRejectedValue(error('rate_limited', 429, 'too_many_requests'))
    await expect(share('achievement', 'first_session', FALLBACK)).resolves.toBe('sent')

    vi.mocked(api.share.event).mockImplementation(() => {
      throw new Error('boom')
    })
    tg.canShareMessage.mockReturnValue(false)
    await expect(share('achievement', 'first_session', FALLBACK)).resolves.toBe('fallback')
    expect(tg.shareUrl).toHaveBeenCalledTimes(1)
  })
})
