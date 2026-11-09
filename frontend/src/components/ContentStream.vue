<script setup lang="ts">
import { computed } from 'vue'

import type { Campaign } from '../api/types'
import { useContentStream } from '../composables/useContentStream'
import EmailPreview from './EmailPreview.vue'
import SmsPreview from './SmsPreview.vue'

const props = defineProps<{ campaign: Campaign }>()
const emit = defineEmits<{ completed: [] }>()

const { status, draft, repaired, error, start, cancel } = useContentStream(
  () => props.campaign.id,
  () => emit('completed'),
)

const streaming = computed(() => status.value === 'streaming')
// The cards show the text arriving; once it is checked and saved, the editor below takes over the same text.
const showCards = computed(() => status.value === 'streaming' || status.value === 'failed')
const hasContent = computed(() => (props.campaign.messages?.length ?? 0) > 0)
</script>

<template>
  <section
    aria-labelledby="content-heading"
    class="space-y-4 rounded border border-slate-200 bg-white p-4"
  >
    <div class="flex items-center justify-between gap-4">
      <h3 id="content-heading" class="font-semibold">Content</h3>
      <button
        v-if="streaming"
        type="button"
        class="rounded border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600"
        @click="cancel"
      >
        Cancel
      </button>
      <button
        v-else
        type="button"
        class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600"
        @click="start()"
      >
        {{ hasContent || status === 'done' ? 'Generate again' : 'Generate content' }}
      </button>
    </div>

    <div aria-live="polite">
      <p v-if="streaming" class="text-sm text-slate-600">Writing content…</p>
      <p v-else-if="status === 'cancelled'" class="text-sm text-slate-600">
        Cancelled. Nothing was saved.
      </p>
      <p v-else-if="status === 'done' && repaired" class="text-sm text-amber-800">
        The first draft broke a content rule, so it was rewritten automatically. The text below is
        the checked version.
      </p>
      <p v-else-if="status === 'done'" class="text-sm text-emerald-800">
        Content checked and saved.
      </p>
    </div>

    <div v-if="error" role="alert" class="space-y-2 rounded border border-red-300 bg-red-50 p-3">
      <p class="font-medium text-red-900">{{ error.message }}</p>
      <ul v-if="error.errors.length > 0" class="list-disc pl-5 text-sm text-red-900">
        <li v-for="problem in error.errors" :key="problem">{{ problem }}</li>
      </ul>
    </div>

    <div v-if="showCards" class="grid gap-6 md:grid-cols-2">
      <SmsPreview :body="draft.sms.body" :streaming="streaming" />
      <EmailPreview
        :subject="draft.email.subject"
        :body="draft.email.body"
        :streaming="streaming"
      />
    </div>
  </section>
</template>
