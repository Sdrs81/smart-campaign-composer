import type { ContentRules, SegmentCondition } from '../api/types'

const VALUE_PLACEHOLDER = '{value}'
const RANGE_SEPARATOR = '–'
const LIST_SEPARATOR = ', '

function formatValue(
  value: SegmentCondition['value'],
  operator: string,
  valueLabels: Record<string, string> = {},
): string {
  if (value === null) return ''
  const label = (item: unknown): string => valueLabels[String(item)] ?? String(item)
  if (!Array.isArray(value)) return label(value)
  return value.map(label).join(operator === 'between' ? RANGE_SEPARATOR : LIST_SEPARATOR)
}

/** One rule condition as a Turkish chip, e.g. "Sadakat seviyesi: Altın, Platin". Labels come from the server. */
export function describeCondition(
  condition: SegmentCondition,
  segment: ContentRules['segment'],
): string {
  const field = segment.fields.find((candidate) => candidate.name === condition.field)
  const operator = segment.operators.find((candidate) => candidate.name === condition.operator)
  const text = (operator?.template ?? VALUE_PLACEHOLDER).replace(
    VALUE_PLACEHOLDER,
    formatValue(condition.value, condition.operator, field?.value_labels),
  )

  return `${field?.label ?? condition.field}: ${text}`
}
