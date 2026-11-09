import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '../errors'
import { SseParser, streamSse, type SseEvent } from '../sse'

const STREAM_TEXT =
  'retry: 3000\n\n' +
  ': heartbeat\n\n' +
  'event: delta\ndata: {"text":"Merhaba ş"}\n\n' +
  'event: final\r\ndata: line one\r\ndata: line two\r\n\r\n' +
  'data: no name\n\n'

const EXPECTED: SseEvent[] = [
  { event: 'delta', data: '{"text":"Merhaba ş"}' },
  { event: 'final', data: 'line one\nline two' },
  { event: 'message', data: 'no name' },
]

function parseInPieces(pieces: string[]): SseEvent[] {
  const parser = new SseParser()
  return pieces.flatMap((piece) => parser.push(piece))
}

function streamResponse(
  chunks: string[],
  init: ResponseInit = {},
): { response: Response; cancelled: () => boolean } {
  let cancelled = false
  const encoder = new TextEncoder()
  let index = 0
  const body = new ReadableStream<Uint8Array>({
    pull(controller) {
      if (index < chunks.length) controller.enqueue(encoder.encode(chunks[index++]))
      else controller.close()
    },
    cancel() {
      cancelled = true
    },
  })
  return { response: new Response(body, init), cancelled: () => cancelled }
}

afterEach(() => vi.unstubAllGlobals())

describe('SseParser', () => {
  it('turns the whole text into events', () => {
    expect(parseInPieces([STREAM_TEXT])).toEqual(EXPECTED)
  })

  it('gives the same events however the text is split', () => {
    for (let cut = 1; cut < STREAM_TEXT.length; cut++) {
      expect(parseInPieces([STREAM_TEXT.slice(0, cut), STREAM_TEXT.slice(cut)])).toEqual(EXPECTED)
    }
  })

  it('gives the same events when fed one character at a time', () => {
    expect(parseInPieces([...STREAM_TEXT])).toEqual(EXPECTED)
  })
})

describe('streamSse', () => {
  it('posts the body and reports events split across network chunks', async () => {
    const { response } = streamResponse([
      'event: del',
      'ta\ndata: {"a"',
      ':1}\n',
      '\nevent: final\ndata: {}\n\n',
    ])
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(response)
    vi.stubGlobal('fetch', fetchMock)
    const events: SseEvent[] = []

    await streamSse('/api/x', { tone: 'warm' }, (event) => events.push(event))

    expect(events).toEqual([
      { event: 'delta', data: '{"a":1}' },
      { event: 'final', data: '{}' },
    ])
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/x',
      expect.objectContaining({ method: 'POST', body: '{"tone":"warm"}' }),
    )
  })

  it('decodes a multi-byte character split between chunks', async () => {
    const bytes = new TextEncoder().encode('data: ş\n\n')
    const body = new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(bytes.slice(0, 7)) // cuts "ş" (2 bytes) in half
        controller.enqueue(bytes.slice(7))
        controller.close()
      },
    })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body)))
    const events: SseEvent[] = []

    await streamSse('/api/x', {}, (event) => events.push(event))

    expect(events).toEqual([{ event: 'message', data: 'ş' }])
  })

  it('stops reading and cancels the stream when aborted', async () => {
    const controller = new AbortController()
    const { response, cancelled } = streamResponse(['data: 1\n\n', 'data: 2\n\n', 'data: 3\n\n'])
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))
    const events: SseEvent[] = []

    await streamSse(
      '/api/x',
      {},
      (event) => {
        events.push(event)
        controller.abort()
      },
      controller.signal,
    )

    expect(events).toEqual([{ event: 'message', data: '1' }])
    expect(cancelled()).toBe(true)
  })

  it('resolves quietly when aborted before the response arrives', async () => {
    const controller = new AbortController()
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new DOMException('aborted', 'AbortError')))
    controller.abort()

    await expect(
      streamSse('/api/x', {}, () => undefined, controller.signal),
    ).resolves.toBeUndefined()
  })

  it('rejects with the typed error on a non-2xx answer', async () => {
    const payload = { error: { code: 'conflict', message: 'Campaign is approved.', details: {} } }
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response(JSON.stringify(payload), { status: 409 })),
    )

    await expect(streamSse('/api/x', {}, () => undefined)).rejects.toMatchObject({
      status: 409,
      code: 'conflict',
      message: 'Campaign is approved.',
    })
  })
})

describe('ApiError', () => {
  it('is an Error subclass', () => {
    expect(new ApiError(500, 'server_error', 'x')).toBeInstanceOf(Error)
  })
})
