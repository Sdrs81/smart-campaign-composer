import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useEventSource } from '../useEventSource'

class FakeEventSource {
  static readonly OPEN = 1
  static instances: FakeEventSource[] = []
  readyState = 0
  closed = false
  onopen: (() => void) | null = null
  onerror: (() => void) | null = null
  private listeners = new Map<string, ((event: MessageEvent<string>) => void)[]>()

  constructor(readonly url: string) {
    FakeEventSource.instances.push(this)
  }

  addEventListener(name: string, listener: (event: MessageEvent<string>) => void): void {
    this.listeners.set(name, [...(this.listeners.get(name) ?? []), listener])
  }

  close(): void {
    this.closed = true
  }

  emit(name: string, data: string): void {
    for (const listener of this.listeners.get(name) ?? [])
      listener({ data } as MessageEvent<string>)
  }
}

beforeEach(() => {
  FakeEventSource.instances = []
  vi.stubGlobal('EventSource', FakeEventSource)
})
afterEach(() => vi.unstubAllGlobals())

describe('useEventSource', () => {
  it('passes named events to their handlers', () => {
    const received: string[] = []
    useEventSource('/api/stream', { handlers: { stats: (data) => received.push(data) } })

    FakeEventSource.instances[0]!.emit('stats', '{"a":1}')

    expect(received).toEqual(['{"a":1}'])
  })

  it('closes the source when its scope is disposed', () => {
    const scope = effectScope()
    scope.run(() => useEventSource('/api/stream', { handlers: {} }))
    const source = FakeEventSource.instances[0]!

    expect(source.closed).toBe(false)
    scope.stop()

    expect(source.closed).toBe(true)
  })

  it('closes itself on an end event so the browser does not reconnect', () => {
    const { connected } = useEventSource('/api/stream', { handlers: {}, closeOn: ['end'] })
    const source = FakeEventSource.instances[0]!
    source.onopen?.()
    expect(connected.value).toBe(true)

    source.emit('end', '{}')

    expect(source.closed).toBe(true)
    expect(connected.value).toBe(false)
  })
})
