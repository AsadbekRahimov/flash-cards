import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { StoryShare } from '@/api/shareTypes'
import { useStoryShare, type StoryFallbackTarget } from '../useStoryShare'

const tg = vi.hoisted(() => ({
  canShareToStory: vi.fn(),
  shareToStory: vi.fn(),
  plainShare: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { share: { story: vi.fn(), event: vi.fn() } } }))

vi.mock('../useTelegram', () => ({
  useTelegram: () => ({ canShareToStory: tg.canShareToStory, shareToStory: tg.shareToStory }),
}))

vi.mock('../useShare', async () => {
  const { ref } = await import('vue')
  return { useShare: () => ({ pending: ref(false), share: tg.plainShare }) }
})

const STORY: StoryShare = {
  media_url: 'https://muhrla.test/storage/share-cards/story_streak_7-ru-0123456789abcdef0123.jpg',
  text: '7 дней подряд в Muhrla · t.me/MuhrlaBot?start=ref_ABCD2345',
  widget_link: { url: 'https://t.me/MuhrlaBot?start=ref_ABCD2345', name: 'Muhrla' },
}

const TARGET: StoryFallbackTarget = { kind: 'invite', subject: null, link: 'https://t.me/MuhrlaBot?start=ref_ABCD2345', text: '7 дней подряд!' }

const error = (kind: ApiClientError['kind'], status: number, code: string) => new ApiClientError(kind, status, code, code)

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

const fallbackOf = vi.fn(async (_story: StoryShare | null): Promise<StoryFallbackTarget | null> => TARGET)

function make(kind: 'streak' | 'achievement' = 'streak', subject: string | null = null) {
  return useStoryShare(kind, subject, fallbackOf, { noLink: () => 'нет ссылки' })
}

describe('useStoryShare', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    tg.canShareToStory.mockReturnValue(true)
    tg.shareToStory.mockReturnValue('opened')
    tg.plainShare.mockResolvedValue('sent')
    vi.mocked(api.share.story).mockResolvedValue(STORY)
    vi.mocked(api.share.event).mockResolvedValue(undefined)
    fallbackOf.mockImplementation(async () => TARGET)
  })

  it('asks the server for the picture on the tap and opens the story editor with it; reports "story_sent"', async () => {
    const story = make('achievement', 'first_session')

    expect(api.share.story).not.toHaveBeenCalled()
    await expect(story.share()).resolves.toBe('opened')

    expect(api.share.story).toHaveBeenCalledTimes(1)
    expect(api.share.story).toHaveBeenCalledWith('achievement', 'first_session')
    expect(tg.shareToStory).toHaveBeenCalledWith(STORY.media_url, { text: STORY.text, widget_link: STORY.widget_link })
    expect(api.share.event).toHaveBeenCalledWith({ event: 'story_sent', kind: 'achievement' })
    expect(tg.plainShare).not.toHaveBeenCalled()
    expect(story.error.value).toBe('')
  })

  it('reuses the answer for the next taps of the screen: one request for the card', async () => {
    const story = make()

    await story.share()
    await story.share()
    await story.share()

    expect(api.share.story).toHaveBeenCalledTimes(1)
    expect(api.share.story).toHaveBeenCalledWith('streak', null)
    expect(tg.shareToStory).toHaveBeenCalledTimes(3)
  })

  it('takes no second tap while the picture is on its way', async () => {
    const pending = deferred<StoryShare>()
    vi.mocked(api.share.story).mockReturnValueOnce(pending.promise)
    const story = make()

    const first = story.share()
    expect(story.pending.value).toBe(true)
    await expect(story.share()).resolves.toBe('busy')
    pending.resolve(STORY)

    await expect(first).resolves.toBe('opened')
    expect(api.share.story).toHaveBeenCalledTimes(1)
    expect(tg.shareToStory).toHaveBeenCalledTimes(1)
    expect(story.pending.value).toBe(false)
  })

  it('below 7.8 asks the server nothing and takes the plain share at once ("story_fallback old_client")', async () => {
    tg.canShareToStory.mockReturnValue(false)
    const story = make()

    await expect(story.share()).resolves.toBe('fallback')

    expect(api.share.story).not.toHaveBeenCalled()
    expect(tg.shareToStory).not.toHaveBeenCalled()
    expect(fallbackOf).toHaveBeenCalledWith(null)
    expect(tg.plainShare).toHaveBeenCalledWith('invite', null, { link: TARGET.link, text: TARGET.text }, undefined)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'story_fallback', kind: 'streak', reason: 'old_client' })
  })

  it.each([
    [403, 'forbidden', 'share_unavailable'],
    [404, 'not_found', 'share_subject_not_found'],
  ] as const)('a refusal %s of the server takes the plain share and is not asked again', async (status, kind, code) => {
    vi.mocked(api.share.story).mockRejectedValue(error(kind, status, code))
    const story = make()

    await expect(story.share()).resolves.toBe('fallback')
    await expect(story.share()).resolves.toBe('fallback')

    expect(api.share.story).toHaveBeenCalledTimes(1)
    expect(tg.plainShare).toHaveBeenCalledTimes(2)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'story_fallback', kind: 'streak', reason: 'unavailable' })
    expect(story.error.value).toBe('')
  })

  it('a 422 (a mistake of this build) is written to the console and takes the plain share', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    vi.mocked(api.share.story).mockRejectedValue(error('validation', 422, 'unknown_kind'))

    await expect(make().share()).resolves.toBe('fallback')

    expect(warn).toHaveBeenCalledWith('share.story refused', { kind: 'streak', code: 'unknown_kind' })
    expect(api.share.event).toHaveBeenCalledWith({ event: 'story_fallback', kind: 'streak', reason: 'error' })
    warn.mockRestore()
  })

  it.each([
    ['rate_limited', 429, 'too_many_requests', 'Слишком много запросов. Подождите немного и повторите.'],
    ['network', 0, 'network', 'Нет связи с сервером. Попробуйте ещё раз.'],
    ['server', 503, 'server_error', 'Сервер временно недоступен. Попробуйте позже.'],
  ] as const)('%s: nothing opens, the error says why, and the tap asks again', async (kind, status, code, message) => {
    vi.mocked(api.share.story).mockRejectedValueOnce(error(kind, status, code))
    const story = make()

    await expect(story.share()).resolves.toBe('error')
    expect(story.error.value).toBe(message)
    expect(tg.shareToStory).not.toHaveBeenCalled()
    expect(tg.plainShare).not.toHaveBeenCalled()
    expect(api.share.event).not.toHaveBeenCalled()

    await expect(story.share()).resolves.toBe('opened')
    expect(api.share.story).toHaveBeenCalledTimes(2)
    expect(story.error.value).toBe('')
  })

  it('an SDK refusal of the picture takes the plain share with the answer of the server ("story_fallback error")', async () => {
    tg.shareToStory.mockReturnValue('failed')

    await expect(make().share()).resolves.toBe('fallback')

    expect(fallbackOf).toHaveBeenCalledWith(STORY)
    expect(api.share.event).toHaveBeenCalledWith({ event: 'story_fallback', kind: 'streak', reason: 'error' })
    expect(api.share.event).not.toHaveBeenCalledWith({ event: 'story_sent', kind: 'streak' })
  })

  it('passes the mark of the screen to the plain share and tells a closed picker apart', async () => {
    tg.canShareToStory.mockReturnValue(false)
    const onShared = vi.fn()
    fallbackOf.mockResolvedValue({ kind: 'achievement', subject: 'first_session', link: TARGET.link, text: 'Я получил(а)', onShared })
    tg.plainShare.mockResolvedValue('cancelled')

    await expect(make('achievement', 'first_session').share()).resolves.toBe('cancelled')
    expect(tg.plainShare).toHaveBeenCalledWith('achievement', 'first_session', { link: TARGET.link, text: 'Я получил(а)' }, onShared)
  })

  it('says so when there is no link to share', async () => {
    tg.canShareToStory.mockReturnValue(false)
    fallbackOf.mockResolvedValue(null)
    const story = make()

    await expect(story.share()).resolves.toBe('error')
    expect(story.error.value).toBe('нет ссылки')
    expect(tg.plainShare).not.toHaveBeenCalled()
  })
})
