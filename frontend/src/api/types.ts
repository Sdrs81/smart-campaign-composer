// Mirrors the backend resources (CampaignResource, CampaignStats, ContentRulesController, ContentEvent).

export type CampaignStatus =
  'draft' | 'audience_ready' | 'content_ready' | 'approved' | 'sending' | 'completed' | 'failed'

export type Channel = 'sms' | 'email'

export type MessageSource = 'llm' | 'human'

export type DeliveryStatus = 'pending' | 'sending' | 'sent' | 'failed' | 'skipped'

export interface CampaignMessage {
  channel: Channel
  subject: string | null
  body: string
  source: MessageSource
}

export type ConditionValue = string | number | (string | number)[] | null

export interface SegmentCondition {
  field: string
  operator: string
  value: ConditionValue
}

export interface SegmentRule {
  version: number
  conditions: SegmentCondition[]
}

export interface SampleCustomer {
  id: number
  first_name: string | null
  last_name: string
  city: string
}

export interface AudienceCounts {
  channel_counts: Partial<Record<Channel, number>>
  unique_total: number
  sample: SampleCustomer[]
  // channel => variable => the longest value among the customers who get that channel.
  longest_values: Partial<Record<Channel, Record<string, number>>>
}

export interface Campaign {
  id: number
  brief: string
  status: CampaignStatus
  segment_rule: SegmentRule | null
  unsupported_criteria: string[] | null
  audience_counts: AudienceCounts | null
  // Present on the detail endpoint only.
  messages?: CampaignMessage[]
  created_at: string
  updated_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface ContentRules {
  channels: Record<string, unknown>
  limits: Record<string, number>
  variables: { name: string; fallback: string }[]
  brief: { min_length: number; max_length: number }
  segment: {
    // `value_labels` maps a stored value to its Turkish name ("female" → "Kadın"); empty for most fields.
    fields: { name: string; label: string; value_labels?: Record<string, string> }[]
    // `template` holds a `{value}` placeholder, e.g. "son {value} gün içinde".
    operators: { name: string; template: string }[]
  }
}

export interface EditableContent {
  sms: { body: string }
  email: { subject: string; body: string }
}

export interface PreviewMessage {
  channel: Channel
  subject: string | null
  body: string
  lengths: { subject: number | null; body: number }
}

export interface Preview {
  customer: { id: number | null } & Record<string, unknown>
  messages: PreviewMessage[]
}

// Mirrors App\Delivery\DeliveryErrorCode (K24); the dashboard explains each one in plain language.
export const DELIVERY_ERROR_CODES = [
  'consent_withdrawn',
  'provider_unreachable',
  'provider_busy',
  'provider_error',
  'retries_exhausted',
  'provider_rejected',
  'content_invalid',
  'internal',
] as const
export type DeliveryErrorCode = (typeof DELIVERY_ERROR_CODES)[number]

export interface DeliveryError {
  delivery_id: number
  channel: Channel
  status: DeliveryStatus
  attempts: number
  // null for a delivery stored before codes existed; an unknown string if the backend is newer.
  code: string | null
  error: string
  at: string
}

export interface CampaignStats {
  campaign_id: number
  status: CampaignStatus
  channels: Record<Channel, Record<DeliveryStatus | 'total', number>>
  send_rate_per_second: number
  total_retries: number
  recent_errors: DeliveryError[]
}

export interface ContentStreamError {
  code: string
  message: string
  errors: string[]
}

export type ContentStreamEvent =
  | { type: 'delta'; text: string }
  | { type: 'final'; sms: { body: string }; email: EditableContent['email']; repaired: boolean }
  | ({ type: 'error' } & ContentStreamError)

/** Field path (e.g. `sms.body`) to its messages, as in a 422 response. */
export type FieldErrors = Record<string, string[]>
