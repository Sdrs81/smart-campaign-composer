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
