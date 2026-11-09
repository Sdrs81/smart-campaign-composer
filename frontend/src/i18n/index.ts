import { createI18n } from 'vue-i18n'

import { en } from './en'
import { tr } from './tr'

export const SUPPORTED_LOCALES = ['en', 'tr'] as const
export type Locale = (typeof SUPPORTED_LOCALES)[number]

// Each language is named in itself, so a person who cannot read the current one still finds it.
export const LOCALE_NAMES: Record<Locale, string> = { en: 'English', tr: 'Türkçe' }

const FALLBACK_LOCALE: Locale = 'en'
const STORAGE_KEY = 'locale'

function isLocale(value: unknown): value is Locale {
  return SUPPORTED_LOCALES.some((locale) => locale === value)
}

/** The saved choice, else the browser's language (Turkish or English only), else English. */
export function detectLocale(): Locale {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (isLocale(saved)) return saved
  } catch {
    // Storage can be blocked (private window, site data off); the browser language is a fine default then.
  }
  return navigator.language.toLowerCase().startsWith('tr') ? 'tr' : FALLBACK_LOCALE
}

export const i18n = createI18n({
  legacy: false,
  locale: detectLocale(),
  fallbackLocale: FALLBACK_LOCALE,
  messages: { en, tr },
})

function announceLanguage(locale: Locale): void {
  // Screen readers pick the voice and hyphenation from <html lang>.
  document.documentElement.lang = locale
}
announceLanguage(i18n.global.locale.value as Locale)

export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  announceLanguage(locale)
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // The language still changes for this visit; it just will not be remembered.
  }
}
