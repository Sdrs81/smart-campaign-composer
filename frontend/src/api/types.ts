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

export interface AudienceCounts {
  channel_counts: Partial<Record<Channel, number>>
  unique_total: number
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
    fields: { name: string; label: string }[]
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

export interface DeliveryError {
  delivery_id: number
  channel: Channel
  status: DeliveryStatus
  attempts: number
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
