import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { api } from '../../api/client'
import type { ContentStreamEvent } from '../../api/types'
import { useContentStream } from '../useContentStream'

/** A stream the test drives by hand: `emit` delivers an event, `finish` closes the connection. */
function fakeStream() {
  let onEvent: (event: ContentStreamEvent) => void = () => undefined
  let signal: AbortSignal | undefined
  let finish: () => void = () => undefined
  const spy = vi
    .spyOn(api, 'streamContent')
    .mockImplementation((_id, _tone, handler, abortSignal) => {
      onEvent = handler
      signal = abortSignal
      return new Promise<void>((resolve) => {
        finish = resolve
        abortSignal?.addEventListener('abort', () => resolve())
      })
    })
  return {
    spy,
    emit: (event: ContentStreamEvent) => onEvent(event),
    finish: () => finish(),
    signal: () => signal,
  }
}

const frames: (() => void)[] = []
function flushFrames(): void {
  frames.splice(0).forEach((callback) => callback())
}

const delta = (text: string): ContentStreamEvent => ({ type: 'delta', text })
const FINAL: ContentStreamEvent = {
  type: 'final',
  sms: { body: 'Merhaba ayşe, %20 indirim' },
  email: { subject: 'İndirim', body: 'Detaylar burada.' },
  repaired: true,
}

beforeEach(() => {
  frames.length = 0
  vi.stubGlobal('requestAnimationFrame', (callback: () => void) => frames.push(callback))
  vi.stubGlobal('cancelAnimationFrame', () => frames.splice(0))
})
afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('useContentStream', () => {
  it('grows the text step by step as partial JSON arrives', async () => {
    const stream = fakeStream()
    const content = useContentStream(() => 7)
    const running = content.start()

    stream.emit(delta('{"sms":{"body":"Mer'))
    flushFrames()
    expect(content.draft.value.sms.body).toBe('Mer')

    stream.emit(delta('haba"},"email":{"subject":"İndi'))
    flushFrames()
    expect(content.draft.value.sms.body).toBe('Merhaba')
    expect(content.draft.value.email.subject).toBe('İndi')

    stream.emit(delta('rim","body":"Detay'))
    flushFrames()
    expect(content.draft.value.email).toEqual({ subject: 'İndirim', body: 'Detay' })
    expect(content.status.value).toBe('streaming')

    stream.finish()
    await running
  })

  it('draws once per frame however many deltas arrived', () => {
    const stream = fakeStream()
    const content = useContentStream(() => 7)
    void content.start()

    stream.emit(delta('{"sms":'))
    stream.emit(delta('{"body":"a'))
    stream.emit(delta('b"}'))

    expect(frames).toHaveLength(1)
    flushFrames()
    expect(content.draft.value.sms.body).toBe('ab')
  })

  it('keeps the last picture while the text is not parseable yet', () => {
    const stream = fakeStream()
    const content = useContentStream(() => 7)
    void content.start()

    stream.emit(delta('{"sms":{"body":"Hi"}'))
    flushFrames()
    stream.emit(delta(',"em'))
    flushFrames()

    expect(content.draft.value.sms.body).toBe('Hi')
  })

  it('replaces the drawn text with the validated content on final and reports the repair', async () => {
    const stream = fakeStream()
    const completed = vi.fn<() => void>()
    const content = useContentStream(() => 7, completed)
    const running = content.start()

    stream.emit(delta('{"sms":{"body":"unchecked and too long'))
    flushFrames()
    stream.emit(FINAL)
    stream.finish()
    await running

    expect(content.status.value).toBe('done')
    expect(content.repaired.value).toBe(true)
    expect(content.draft.value).toEqual({
      sms: { body: 'Merhaba ayşe, %20 indirim' },
      email: { subject: 'İndirim', body: 'Detaylar burada.' },
    })
    expect(completed).toHaveBeenCalledOnce()
    expect(frames).toHaveLength(0)
  })

  it('stops reading and clears unchecked text when cancelled', async () => {
    const stream = fakeStream()
    const content = useContentStream(() => 7)
    const running = content.start()
    stream.emit(delta('{"sms":{"body":"Half'))
    flushFrames()

    content.cancel()
    await running

    expect(stream.signal()?.aborted).toBe(true)
    expect(content.status.value).toBe('cancelled')
    expect(content.draft.value.sms.body).toBeUndefined()
  })

  it('shows the error event with its path-based problems', async () => {
    const stream = fakeStream()
    const completed = vi.fn<() => void>()
    const content = useContentStream(() => 7, completed)
    const running = content.start()

    stream.emit({
      type: 'error',
      code: 'content_invalid',
      message: 'Content broke the rules.',
      errors: ['sms.body: 161 > 160'],
    })
    stream.finish()
    await running

    expect(content.status.value).toBe('failed')
    expect(content.error.value).toEqual({
      code: 'content_invalid',
      message: 'Content broke the rules.',
      errors: ['sms.body: 161 > 160'],
    })
    expect(completed).not.toHaveBeenCalled()
  })

  it('fails when the connection closes without final or error', async () => {
    const stream = fakeStream()
    const content = useContentStream(() => 7)
    const running = content.start()

    stream.emit(delta('{"sms":{"body":"x'))
    stream.finish()
    await running

    expect(content.status.value).toBe('failed')
    expect(content.error.value?.code).toBe('stream_ended')
  })

  it('aborts the request when its scope is disposed', () => {
    const stream = fakeStream()
    const scope = effectScope()
    scope.run(() => void useContentStream(() => 7).start())

    scope.stop()

    expect(stream.signal()?.aborted).toBe(true)
  })
})
