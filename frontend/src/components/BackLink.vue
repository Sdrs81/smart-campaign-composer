<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

const { t } = useI18n()
</script>

<template>
  <!-- Always the list, not router.back(): the page may have been opened directly, with no history. -->
  <RouterLink
    :to="{ name: 'campaigns' }"
    :aria-label="t('nav.back')"
    :title="t('nav.back')"
    class="inline-flex size-8 shrink-0 items-center justify-center rounded-full border border-slate-300 text-slate-700 hover:bg-slate-100 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600"
  >
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      class="size-4"
    >
      <path d="M15 18l-6-6 6-6" />
    </svg>
  </RouterLink>
</template>
