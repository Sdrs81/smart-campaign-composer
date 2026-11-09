<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps<{ subject: string | undefined; body: string | undefined; streaming: boolean }>()

const { t } = useI18n()
const headingId = useId()
</script>

<template>
  <section :aria-labelledby="headingId" class="space-y-2">
    <h4 :id="headingId" class="text-sm font-medium text-slate-700">{{ t('preview.email') }}</h4>
    <div aria-live="polite" :aria-busy="streaming" class="rounded border border-slate-200 bg-white">
      <p class="border-b border-slate-200 px-4 py-3 font-medium">
        <span class="mr-2 text-sm font-normal text-slate-500">{{ t('preview.subject') }}</span>
        <template v-if="subject">{{ subject }}</template>
        <span v-else class="text-slate-400">{{
          streaming ? t('preview.writing') : t('preview.noSubject')
        }}</span>
      </p>
      <p class="min-h-24 whitespace-pre-wrap px-4 py-3 text-sm">
        <template v-if="body">{{ body }}</template>
        <span v-else class="text-slate-400">{{
          streaming ? t('preview.writing') : t('preview.noEmailBody')
        }}</span>
      </p>
    </div>
  </section>
</template>
