import { ApiError, errorFromResponse } from './errors'

export interface SseEvent {
  event: string
  data: string
}

const DEFAULT_EVENT = 'message'

/**
 * Incremental text/event-stream parser. Network chunks can end anywhere (mid-line, between
 * "\r" and "\n"), so it buffers until a full line is available.
 */
export class SseParser {
  private buffer = ''
  private eventName = ''
  private dataLines: string[] = []

  /** Feeds a chunk and returns the events it completed. */
  push(chunk: string): SseEvent[] {
    this.buffer += chunk
    const events: SseEvent[] = []

    for (;;) {
      const lineEnd = this.nextLineEnd()
      if (lineEnd === null) return events

      const line = this.buffer.slice(0, lineEnd.index)
      this.buffer = this.buffer.slice(lineEnd.index + lineEnd.length)

      const event = this.handleLine(line)
      if (event) events.push(event)
    }
  }

  // A trailing "\r" may be half of "\r\n", so it is not a line end until the next char is known.
  private nextLineEnd(): { index: number; length: number } | null {
    const match = /\r\n|\n|\r(?=.)/s.exec(this.buffer)
    return match ? { index: match.index, length: match[0].length } : null
  }

  private handleLine(line: string): SseEvent | null {
    if (line === '') return this.dispatch()
    if (line.startsWith(':')) return null // comment, e.g. a heartbeat

    const separator = line.indexOf(':')
    const field = separator === -1 ? line : line.slice(0, separator)
    let value = separator === -1 ? '' : line.slice(separator + 1)
    if (value.startsWith(' ')) value = value.slice(1)

    if (field === 'event') this.eventName = value
    else if (field === 'data') this.dataLines.push(value)
    // `id` and `retry` are not used by the POST streams.
    return null
  }

  private dispatch(): SseEvent | null {
    if (this.dataLines.length === 0) {
      this.eventName = ''
      return null
    }
    const event = { event: this.eventName || DEFAULT_EVENT, data: this.dataLines.join('\n') }
    this.eventName = ''
    this.dataLines = []
    return event
  }
}

/**
 * POSTs `body` and reports each server-sent event. Resolves when the stream ends or `signal`
 * aborts (an abort is the caller's choice, not an error); rejects with ApiError on a non-2xx answer.
 */
export async function streamSse(
  url: string,
  body: unknown,
  onEvent: (event: SseEvent) => void,
  signal?: AbortSignal,
): Promise<void> {
  let response: Response
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal,
    })
  } catch (failure) {
    if (signal?.aborted) return
    throw failure
  }

  if (!response.ok) throw await errorFromResponse(response)
  if (!response.body)
    throw new ApiError(response.status, 'empty_stream', `POST ${url} returned no body to read.`)

  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  const parser = new SseParser()

  try {
    for (;;) {
      const { done, value } = await reader.read()
      if (done) return
      for (const event of parser.push(decoder.decode(value, { stream: true }))) {
        // Checked per event: the handler itself may abort, and later events must not be delivered.
        if (signal?.aborted) return
        onEvent(event)
      }
      if (signal?.aborted) return
    }
  } catch (failure) {
    if (signal?.aborted) return
    throw failure
  } finally {
    // Stops the underlying read so an abort frees the connection instead of leaving it open.
    await reader.cancel().catch(() => undefined)
  }
}
