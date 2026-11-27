import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { offlineContext, useOfflineContext, useOnline, type OfflineContext } from '../useOnline'
import type { NetworkSignal } from '@/api/client'

const net = vi.hoisted(() => ({ listeners: new Set<(s: NetworkSignal) => void>() }))

vi.mock('@/api/client', () => ({
  onNetworkSignal: (listener: (s: NetworkSignal) => void) => {
    net.listeners.add(listener)
    return () => net.listeners.delete(listener)
  },
}))

function emit(outcome: NetworkSignal['outcome'], method: NetworkSignal['method'] = 'GET'): void {
  for (const listener of net.listeners) listener({ outcome, method })
}

function setDeviceOnline(value: boolean): void {
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => value })
}

describe('useOnline', () => {
  beforeEach(() => setDeviceOnline(true))
  afterEach(() => {
    setDeviceOnline(true)
    net.listeners.clear()
  })

  function run() {
    const scope = effectScope()
    const state = scope.run(() => useOnline())!
    return { scope, state }
  }

  it('starts from navigator.onLine', () => {
    setDeviceOnline(false)
    const { scope, state } = run()

    expect(state.isOnline.value).toBe(false)
    expect(state.loadFailed.value).toBe(false)
    scope.stop()
  })

  it('follows the offline and online events of the device', () => {
    const { scope, state } = run()

    window.dispatchEvent(new Event('offline'))
    expect(state.isOnline.value).toBe(false)
    window.dispatchEvent(new Event('online'))
    expect(state.isOnline.value).toBe(true)
    scope.stop()
  })

  it('a lost load raises loadFailed; any request that reaches the server takes it down and is proof of a connection', () => {
    const { scope, state } = run()
    window.dispatchEvent(new Event('offline'))

    emit('failed', 'GET')
    expect(state.loadFailed.value).toBe(true)

    emit('reached', 'POST')
    expect(state.loadFailed.value).toBe(false)
    // navigator.onLine lies at times: the server answered, so there is a connection.
    expect(state.isOnline.value).toBe(true)
    scope.stop()
  })

  it('a lost action (not a GET) is not a lost load: the screen of the action says it', () => {
    const { scope, state } = run()

    emit('failed', 'POST')
    emit('failed', 'PATCH')

    expect(state.loadFailed.value).toBe(false)
    scope.stop()
  })

  it('clearLoadFailed and recheck', () => {
    const { scope, state } = run()
    emit('failed')
    state.clearLoadFailed()
    expect(state.loadFailed.value).toBe(false)

    setDeviceOnline(false)
    state.recheck()
    expect(state.isOnline.value).toBe(false)
    scope.stop()
  })

  it('lets go of the events and the requests when its scope ends', () => {
    const remove = vi.spyOn(window, 'removeEventListener')
    const { scope, state } = run()
    expect(net.listeners.size).toBe(1)

    scope.stop()

    expect(net.listeners.size).toBe(0)
    expect(remove).toHaveBeenCalledWith('online', expect.any(Function))
    expect(remove).toHaveBeenCalledWith('offline', expect.any(Function))
    window.dispatchEvent(new Event('offline'))
    expect(state.isOnline.value).toBe(true)
    remove.mockRestore()
  })
})

describe('useOfflineContext', () => {
  const context = (): OfflineContext => ({ retry: vi.fn(), chip: () => null, pendingAnswer: () => false })

  it('is the context of the screen while the screen lives, and none after', () => {
    const scope = effectScope()
    const value = context()
    scope.run(() => useOfflineContext(value))
    expect(offlineContext.value).toBe(value)

    scope.stop()

    expect(offlineContext.value).toBeNull()
  })

  it('the old screen going after the new one came does not take the new context away', () => {
    const first = effectScope()
    const second = effectScope()
    first.run(() => useOfflineContext(context()))
    const next = context()
    second.run(() => useOfflineContext(next))

    first.stop()

    expect(offlineContext.value).toBe(next)
    second.stop()
  })
})
