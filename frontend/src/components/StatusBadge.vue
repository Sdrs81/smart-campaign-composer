<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { CampaignStatus } from '../api/types'

const props = defineProps<{ status: CampaignStatus }>()
const { t } = useI18n()

const CLASSES: Record<CampaignStatus, string> = {
  draft: 'bg-slate-100 text-slate-700',
  audience_ready: 'bg-sky-100 text-sky-800',
  content_ready: 'bg-indigo-100 text-indigo-800',
  approved: 'bg-amber-100 text-amber-800',
  sending: 'bg-blue-100 text-blue-800',
  completed: 'bg-emerald-100 text-emerald-800',
  failed: 'bg-red-100 text-red-800',
}

const classes = computed(() => CLASSES[props.status])
</script>

<template>
  <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classes">
    {{ t(`status.${status}`) }}
  </span>
</template>
