<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps<{ body: string | undefined; streaming: boolean }>()

const { t } = useI18n()
// Unique per instance: the stream cards and the editor's preview can be on the page together.
const headingId = useId()
</script>

<template>
  <section :aria-labelledby="headingId" class="space-y-2">
    <h4 :id="headingId" class="text-sm font-medium text-slate-700">{{ t('preview.sms') }}</h4>
    <div class="mx-auto w-64 rounded-3xl border-4 border-slate-800 bg-slate-100 p-4">
      <div
        aria-live="polite"
        :aria-busy="streaming"
        class="min-h-16 whitespace-pre-wrap rounded-2xl rounded-tl-sm bg-white p-3 text-sm shadow-sm"
      >
        <template v-if="body">{{ body }}</template>
        <span v-else class="text-slate-400">{{
          streaming ? t('preview.writing') : t('preview.noSms')
        }}</span>
      </div>
    </div>
  </section>
</template>
