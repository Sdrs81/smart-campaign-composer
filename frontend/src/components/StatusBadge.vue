<script setup lang="ts">
import { computed } from 'vue'

import type { CampaignStatus } from '../api/types'

const props = defineProps<{ status: CampaignStatus }>()

const APPEARANCE: Record<CampaignStatus, { label: string; classes: string }> = {
  draft: { label: 'Draft', classes: 'bg-slate-100 text-slate-700' },
  audience_ready: { label: 'Audience ready', classes: 'bg-sky-100 text-sky-800' },
  content_ready: { label: 'Content ready', classes: 'bg-indigo-100 text-indigo-800' },
  approved: { label: 'Approved', classes: 'bg-amber-100 text-amber-800' },
  sending: { label: 'Sending', classes: 'bg-blue-100 text-blue-800' },
  completed: { label: 'Completed', classes: 'bg-emerald-100 text-emerald-800' },
  failed: { label: 'Failed', classes: 'bg-red-100 text-red-800' },
}

const appearance = computed(() => APPEARANCE[props.status])
</script>

<template>
  <span
    class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium"
    :class="appearance.classes"
  >
    {{ appearance.label }}
  </span>
</template>
