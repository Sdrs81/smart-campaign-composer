import { config } from '@vue/test-utils'
import { beforeEach } from 'vitest'

import { i18n } from './i18n'

// Every mounted component gets the app's i18n, and every test starts in English (the language the
// assertions are written in), whatever an earlier test switched to.
config.global.plugins = [i18n]

beforeEach(() => {
  localStorage.clear()
  i18n.global.locale.value = 'en'
})
