import { MalformedJSON, OBJ, PartialJSON, STR, parse } from 'partial-json'
import { getCurrentScope, onScopeDispose, ref, shallowRef } from 'vue'

import { api } from '../api/client'
import { toApiError } from '../api/errors'
import type { ContentStreamError, ContentStreamEvent } from '../api/types'
import { i18n } from '../i18n'

/** What the model has written so far; every field is absent until its first characters arrive. */
export interface DraftContent {
  sms: { body?: string }
  email: { subject?: string; body?: string }
}

export type ContentStreamStatus = 'idle' | 'streaming' | 'done' | 'failed' | 'cancelled'

const EMPTY_DRAFT: DraftContent = { sms: {}, email: {} }

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readText(source: unknown, key: string): string | undefined {
  if (!isRecord(source)) return undefined
  const value = source[key]
  return typeof value === 'string' ? value : undefined
}

function readDraft(parsed: unknown): DraftContent {
  const sms = isRecord(parsed) ? parsed.sms : undefined
  const email = isRecord(parsed) ? parsed.email : undefined
  return {
    sms: { body: readText(sms, 'body') },
    email: { subject: readText(email, 'subject'), body: readText(email, 'body') },
  }
}

/**
 * Streams generated content for one campaign. Deltas are collected and drawn once per animation frame
 * (a delta can arrive every few milliseconds); the validated `final` replaces whatever was drawn.
 */
export function useContentStream(
  campaignId: () => number,
  onCompleted: () => void = () => undefined,
) {
  const status = ref<ContentStreamStatus>('idle')
  // Replaced whole on every frame, so it is not made deeply reactive.
  const draft = shallowRef<DraftContent>(EMPTY_DRAFT)
  const repaired = ref(false)
  const error = shallowRef<ContentStreamError | null>(null)

  let controller: AbortController | null = null
  let rawJson = ''
  let frame: number | null = null

  function drawDraft(): void {
    frame = null
    try {
      draft.value = readDraft(parse(rawJson, STR | OBJ))
    } catch (failure) {
      // A prefix such as `{"sm` is not yet parseable; the next delta completes it, so keep the last picture.
      if (!(failure instanceof PartialJSON || failure instanceof MalformedJSON)) throw failure
    }
  }

  function readStatus(): ContentStreamStatus {
    return status.value
  }

  function cancelFrame(): void {
    if (frame !== null) cancelAnimationFrame(frame)
    frame = null
  }

  function handle(event: ContentStreamEvent): void {
    if (event.type === 'delta') {
      rawJson += event.text
      frame ??= requestAnimationFrame(drawDraft)
      return
    }

    cancelFrame()
    if (event.type === 'final') {
      draft.value = { sms: { body: event.sms.body }, email: event.email }
      repaired.value = event.repaired
      status.value = 'done'
      return
    }
    error.value = { code: event.code, message: event.message, errors: event.errors }
    status.value = 'failed'
  }

  async function start(tone?: string): Promise<void> {
    controller?.abort()
    const own = new AbortController()
    controller = own
    rawJson = ''
    draft.value = EMPTY_DRAFT
    repaired.value = false
    error.value = null
    status.value = 'streaming'

    try {
      await api.streamContent(campaignId(), tone, handle, own.signal)
      if (own.signal.aborted) return
      // `handle` moves the status while the request runs; read it fresh, not as narrowed by the assignment above.
      const outcome = readStatus()
      if (outcome === 'done') {
        onCompleted()
        return
      }
      // The server closed the stream without `final` or `error`: nothing validated arrived.
      if (outcome === 'streaming') {
        cancelFrame()
        error.value = {
          code: 'stream_ended',
          message: i18n.global.t('content.streamEnded'),
          errors: [],
        }
        status.value = 'failed'
      }
    } catch (failure) {
      if (own.signal.aborted) return
      cancelFrame()
      const apiError = toApiError(failure)
      error.value = { code: apiError.code, message: apiError.message, errors: apiError.problems }
      status.value = 'failed'
    }
  }

  /** Stops reading; the server cancels the model stream when the connection closes, so no tokens are wasted. */
  function cancel(): void {
    if (status.value !== 'streaming') return
    controller?.abort()
    cancelFrame()
    // Unvalidated text must not look like finished content.
    draft.value = EMPTY_DRAFT
    status.value = 'cancelled'
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      controller?.abort()
      cancelFrame()
    })
  }

  return { status, draft, repaired, error, start, cancel }
}
