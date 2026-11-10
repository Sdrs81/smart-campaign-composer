import { describe, expect, it } from 'vitest'

import type { ContentRules } from '../../api/types'
import { describeCondition } from '../describeCondition'

const SEGMENT = {
  fields: [{ name: 'age', label: 'Yaş' }],
  operators: [
    { name: 'between', template: '{value}' },
    { name: 'gt', template: '> {value}' },
    { name: 'is_null', template: 'boş' },
  ],
}

describe('describeCondition', () => {
  it('writes a range with an en dash', () => {
    expect(describeCondition({ field: 'age', operator: 'between', value: [25, 40] }, SEGMENT)).toBe(
      'Yaş: 25–40',
    )
  })

  it('uses the server template for the operator', () => {
    expect(describeCondition({ field: 'age', operator: 'gt', value: 30 }, SEGMENT)).toBe(
      'Yaş: > 30',
    )
  })

  it('needs no value for a template without one', () => {
    expect(describeCondition({ field: 'age', operator: 'is_null', value: null }, SEGMENT)).toBe(
      'Yaş: boş',
    )
  })

  it('shows the Turkish name of a stored value, and the stored value when it has none', () => {
    const segment: ContentRules['segment'] = {
      fields: [
        { name: 'gender', label: 'Cinsiyet', value_labels: { female: 'Kadın', male: 'Erkek' } },
        { name: 'platform', label: 'Platform', value_labels: {} },
      ],
      operators: [
        { name: 'eq', template: '{value}' },
        { name: 'in', template: '{value}' },
      ],
    }

    expect(describeCondition({ field: 'gender', operator: 'eq', value: 'female' }, segment)).toBe(
      'Cinsiyet: Kadın',
    )
    expect(
      describeCondition({ field: 'gender', operator: 'in', value: ['female', 'male'] }, segment),
    ).toBe('Cinsiyet: Kadın, Erkek')
    expect(describeCondition({ field: 'platform', operator: 'eq', value: 'ios' }, segment)).toBe(
      'Platform: ios',
    )
  })

  it('falls back to the raw names for an unknown field or operator', () => {
    expect(describeCondition({ field: 'shoe_size', operator: 'eq', value: 42 }, SEGMENT)).toBe(
      'shoe_size: 42',
    )
  })
})
