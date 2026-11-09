import { afterEach, describe, expect, it, vi } from 'vitest'

import { en } from '../en'
import { detectLocale, i18n, setLocale } from '../index'
import { tr } from '../tr'

type Tree = { [key: string]: string | Tree }

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  return Object.entries(tree).reduce<Record<string, string>>((flat, [key, value]) => {
    const path = prefix === '' ? key : `${prefix}.${key}`
    return typeof value === 'string'
      ? { ...flat, [path]: value }
      : { ...flat, ...flatten(value, path) }
  }, {})
}

const parametersOf = (message: string): string[] =>
  [...message.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort()

afterEach(() => vi.unstubAllGlobals())

describe('translations', () => {
  const english = flatten(en)
  const turkish = flatten(tr)

  it('has the same keys in both languages', () => {
    expect(Object.keys(turkish).sort()).toEqual(Object.keys(english).sort())
  })

  // Each check lists the keys that break it, so a failure names the message to fix.
  const keysWhere = (broken: (key: string, message: string) => boolean): string[] =>
    Object.entries({ ...english, ...turkish })
      .filter(([key, message]) => broken(key, message))
      .map(([key]) => key)

  it('uses the same parameters in both languages, so no value is lost in translation', () => {
    const mismatched = Object.entries(english)
      .filter(
        ([key, message]) => parametersOf(turkish[key]!).join() !== parametersOf(message).join(),
      )
      .map(([key]) => key)

    expect(mismatched).toEqual([])
  })

  it('keeps the characters vue-i18n reserves out of plain text', () => {
    // { } only as {name} parameters; @ | $ never, since each would need escaping.
    expect(keysWhere((_key, message) => /[{}@|$]/.test(message.replace(/\{\w+\}/g, '')))).toEqual(
      [],
    )
  })

  it('leaves no message empty', () => {
    expect(keysWhere((_key, message) => message.trim() === '')).toEqual([])
  })
})

describe('detectLocale', () => {
  it('prefers the saved choice over the browser language', () => {
    vi.stubGlobal('navigator', { language: 'en-US' })
    localStorage.setItem('locale', 'tr')

    expect(detectLocale()).toBe('tr')
  })

  it.each([
    ['tr-TR', 'tr'],
    ['TR', 'tr'],
    ['en-GB', 'en'],
    ['de-DE', 'en'],
  ])('maps the browser language %s to %s when nothing is saved', (language, expected) => {
    vi.stubGlobal('navigator', { language })

    expect(detectLocale()).toBe(expected)
  })

  it('ignores a saved value that is not a supported language', () => {
    vi.stubGlobal('navigator', { language: 'tr-TR' })
    localStorage.setItem('locale', 'fr')

    expect(detectLocale()).toBe('tr')
  })

  it('falls back to the browser language when storage is blocked', () => {
    vi.stubGlobal('navigator', { language: 'tr-TR' })
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })

    expect(detectLocale()).toBe('tr')
    vi.restoreAllMocks()
  })
})

describe('setLocale', () => {
  it('switches the messages, remembers the choice and tells the page its language', () => {
    expect(i18n.global.t('approve.open')).toBe('Approve campaign')

    setLocale('tr')

    expect(i18n.global.t('approve.open')).toBe('Kampanyayı onayla')
    expect(localStorage.getItem('locale')).toBe('tr')
    expect(document.documentElement.lang).toBe('tr')
  })

  it('still switches when the choice cannot be saved', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('quota', 'QuotaExceededError')
    })

    setLocale('tr')

    expect(i18n.global.locale.value).toBe('tr')
    vi.restoreAllMocks()
  })
})
