import { describe, expect, it } from 'vitest'

import { graphemeLength } from '../graphemeLength'
import { worstCaseLength } from '../worstCaseLength'

// Same fixtures and expectations as backend/tests/Unit/PersonalizationRendererTest.php.
const VARIABLES = [
  { name: 'first_name', fallback: 'Değerli müşterimiz' },
  { name: 'last_name', fallback: 'müşterimiz' },
  { name: 'city', fallback: 'bölgeniz' },
]
const FAMILY_EMOJI = '\u{1F468}‍\u{1F469}‍\u{1F467}'

describe('graphemeLength', () => {
  it.each([
    ['Merhaba', 7],
    ['şğüöçıİ', 7],
    ['👍', 1],
    [FAMILY_EMOJI, 1],
    ['', 0],
  ])('counts %j as %i visible characters', (text, expected) => {
    expect(graphemeLength(text)).toBe(expected)
  })

  it('shows why code points are the wrong unit: the family emoji is five of them', () => {
    expect([...FAMILY_EMOJI]).toHaveLength(5)
    expect(FAMILY_EMOJI.length).toBe(8)
    expect(graphemeLength(FAMILY_EMOJI)).toBe(1)
  })
})

describe('worstCaseLength', () => {
  it('uses the longest value of each variable', () => {
    expect(worstCaseLength('Merhaba {{first_name}}!', { first_name: 25 }, VARIABLES)).toBe(
      8 + 25 + 1,
    )
    expect(worstCaseLength('Merhaba {{ first_name }}!', { first_name: 25 }, VARIABLES)).toBe(
      8 + 25 + 1,
    )
    expect(worstCaseLength('Merhaba', {}, VARIABLES)).toBe(7)
  })

  it('counts the fallback when it is longer than any real value', () => {
    // "Değerli müşterimiz" is 18 characters.
    expect(worstCaseLength('Merhaba {{first_name}}!', { first_name: 5 }, VARIABLES)).toBe(
      8 + 18 + 1,
    )
  })

  it('counts every occurrence of a variable', () => {
    expect(worstCaseLength('{{city}} {{city}}', { city: 3 }, VARIABLES)).toBe(8 + 1 + 8)
  })

  it('counts an unknown placeholder as the literal text it will stay', () => {
    expect(worstCaseLength('Selam {{ad}} {{first_name}}', { first_name: 20 }, VARIABLES)).toBe(
      6 + 6 + 1 + 20,
    )
  })

  it('measures the worst case in visible characters around emoji and Turkish letters', () => {
    const template = `Merhaba ${FAMILY_EMOJI} {{first_name}} şğ`

    expect(worstCaseLength(template, { first_name: 20 }, VARIABLES)).toBe(8 + 1 + 1 + 20 + 1 + 2)
  })
})
