import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useOrganizationsStore } from '../organizations'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import type { OrganizationMembership } from '@/api/types'

vi.mock('@/api', () => ({ api: { organizations: { list: vi.fn(), leave: vi.fn() } } }))

const membership = (id: number, overrides: Partial<OrganizationMembership> = {}): OrganizationMembership => ({
  id,
  name: `Center ${id}`,
  seat_state: 'seated',
  joined_via: 'invite',
  joined_at: '2026-10-19T12:00:00+00:00',
  can_leave: true,
  ...overrides,
})

describe('organizations store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads the memberships', async () => {
    vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [membership(1), membership(2)] })
    const store = useOrganizationsStore()

    await store.load()

    expect(store.status).toBe('ready')
    expect(store.items.map((i) => i.id)).toEqual([1, 2])
  })

  it('keeps an empty list and says why when loading fails', async () => {
    vi.mocked(api.organizations.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'x'))
    const store = useOrganizationsStore()

    await store.load()

    expect(store.status).toBe('error')
    expect(store.items).toEqual([])
    expect(store.errorMessage).not.toBe('')
  })

  it('replaces the list with the server answer after leaving', async () => {
    vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [membership(1), membership(2)] })
    vi.mocked(api.organizations.leave).mockResolvedValue({ organizations: [membership(2)] })
    const store = useOrganizationsStore()
    await store.load()

    expect(await store.leave(1)).toBe(true)

    expect(vi.mocked(api.organizations.leave).mock.calls).toEqual([[1]])
    expect(store.items.map((i) => i.id)).toEqual([2])
    expect(store.errorMessage).toBe('')
    expect(store.leaving).toBe(false)
  })

  it('refuses a second leave while one is in flight', async () => {
    let finish!: (value: { organizations: OrganizationMembership[] }) => void
    vi.mocked(api.organizations.leave).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const store = useOrganizationsStore()

    const first = store.leave(1)
    expect(await store.leave(1)).toBe(false)
    finish({ organizations: [] })
    await first

    expect(vi.mocked(api.organizations.leave)).toHaveBeenCalledOnce()
  })

  it('reloads the list and shows the reason when leaving is refused', async () => {
    vi.mocked(api.organizations.leave).mockRejectedValue(new ApiClientError('not_found', 404, 'membership_not_found', 'x'))
    vi.mocked(api.organizations.list).mockResolvedValue({ organizations: [membership(2)] })
    const store = useOrganizationsStore()

    expect(await store.leave(1)).toBe(false)

    expect(store.items.map((i) => i.id)).toEqual([2])
    expect(store.errorMessage).toBe('Вы уже не состоите в этом центре.')
    expect(store.leaving).toBe(false)
  })
})
