import { errorFromResponse } from './errors'
import { streamSse } from './sse'
import type {
  Campaign,
  CampaignStats,
  ContentRules,
  ContentStreamEvent,
  EditableContent,
  Paginated,
  Preview,
} from './types'

export { ApiError } from './errors'

export const API_BASE = '/api'

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
    signal,
  })
  if (!response.ok) throw await errorFromResponse(response)
  return (await response.json()) as T
}

/** Unwraps Laravel's `{data: ...}` envelope. */
async function requestData<T>(
  method: string,
  path: string,
  body?: unknown,
  signal?: AbortSignal,
): Promise<T> {
  return (await request<{ data: T }>(method, path, body, signal)).data
}

export const api = {
  listCampaigns: (page = 1, signal?: AbortSignal) =>
    request<Paginated<Campaign>>('GET', `/campaigns?page=${page}`, undefined, signal),
  createCampaign: (brief: string) => requestData<Campaign>('POST', '/campaigns', { brief }),
  getCampaign: (id: number, signal?: AbortSignal) =>
    requestData<Campaign>('GET', `/campaigns/${id}`, undefined, signal),
  defineAudience: (id: number) => requestData<Campaign>('POST', `/campaigns/${id}/audience`),
  approveCampaign: (id: number) => requestData<Campaign>('POST', `/campaigns/${id}/approve`),
  updateContent: (id: number, content: EditableContent) =>
    requestData<Campaign>('PUT', `/campaigns/${id}/content`, content),
  previewCampaign: (id: number, customerId?: number, signal?: AbortSignal) =>
    requestData<Preview>(
      'GET',
      `/campaigns/${id}/preview${customerId === undefined ? '' : `?customer_id=${customerId}`}`,
      undefined,
      signal,
    ),
  getStats: (id: number, signal?: AbortSignal) =>
    requestData<CampaignStats>('GET', `/campaigns/${id}/stats`, undefined, signal),
  getContentRules: () => requestData<ContentRules>('GET', '/content/rules'),
  statsStreamUrl: (id: number) => `${API_BASE}/campaigns/${id}/stats/stream`,

  /** Streams generated content; a malformed event payload is skipped, not fatal. */
  streamContent: (
    id: number,
    tone: string | undefined,
    onEvent: (event: ContentStreamEvent) => void,
    signal?: AbortSignal,
  ) =>
    streamSse(
      `${API_BASE}/campaigns/${id}/content/stream`,
      tone ? { tone } : {},
      ({ event, data }) => {
        const parsed = parseContentEvent(event, data)
        if (parsed) onEvent(parsed)
      },
      signal,
    ),
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const COUNTER_KEYS = ['pending', 'sending', 'sent', 'failed', 'skipped', 'total']

// Every counter must be a number: the progress bar divides by them, so a missing one would draw NaN.
function isChannelCounts(value: unknown): boolean {
  return isRecord(value) && COUNTER_KEYS.every((key) => typeof value[key] === 'number')
}

/**
 * The text of one `stats` event as a snapshot, or null when it is not one. The stream is a network
 * boundary: a cut-off frame or a proxy's error page must not reach the dashboard as if it were numbers.
 */
export function parseStats(payload: string): CampaignStats | null {
  let parsed: unknown
  try {
    parsed = JSON.parse(payload)
  } catch {
    // Nothing usable arrived; the next snapshot (at most a second away) replaces it.
    return null
  }

  if (!isRecord(parsed) || !isRecord(parsed.channels)) return null
  const { channels } = parsed
  const complete =
    typeof parsed.status === 'string' &&
    typeof parsed.send_rate_per_second === 'number' &&
    typeof parsed.total_retries === 'number' &&
    Array.isArray(parsed.recent_errors) &&
    isChannelCounts(channels.sms) &&
    isChannelCounts(channels.email)

  return complete ? (parsed as unknown as CampaignStats) : null
}

function parseContentEvent(event: string, data: string): ContentStreamEvent | null {
  let payload: Record<string, unknown>
  try {
    payload = JSON.parse(data) as Record<string, unknown>
  } catch {
    // The server always sends JSON; a broken frame carries nothing the UI could show.
    return null
  }

  if (event === 'delta') return { type: 'delta', text: String(payload.text ?? '') }
  if (event === 'final') return { type: 'final', ...(payload as object) } as ContentStreamEvent
  if (event === 'error') return { type: 'error', ...(payload as object) } as ContentStreamEvent
  return null
}
