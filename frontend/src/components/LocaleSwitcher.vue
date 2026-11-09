<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { LOCALE_NAMES, setLocale, type Locale } from '../i18n'

const { t, locale } = useI18n()

// One button that offers the other language: with two languages a menu would only add a click.
const target = computed<Locale>(() => (locale.value === 'tr' ? 'en' : 'tr'))
</script>

<template>
  <button
    type="button"
    :lang="target"
    :aria-label="t('language.switchTo', { language: LOCALE_NAMES[target] })"
    class="rounded border border-slate-300 px-3 py-1 text-sm font-medium hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600"
    @click="setLocale(target)"
  >
    {{ LOCALE_NAMES[target] }}
  </button>
</template>
