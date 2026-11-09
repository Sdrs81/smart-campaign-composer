<script setup lang="ts">
import { computed } from 'vue'

import type { Campaign, ContentRules } from '../api/types'
import { describeCondition } from '../segment/describeCondition'

const props = defineProps<{ campaign: Campaign; segment: ContentRules['segment'] }>()

const chips = computed(() =>
  (props.campaign.segment_rule?.conditions ?? []).map((condition) =>
    describeCondition(condition, props.segment),
  ),
)
const counts = computed(() => [
  { label: 'SMS', value: props.campaign.audience_counts?.channel_counts.sms ?? 0 },
  { label: 'Email', value: props.campaign.audience_counts?.channel_counts.email ?? 0 },
  { label: 'Total', value: props.campaign.audience_counts?.unique_total ?? 0 },
])
const unsupported = computed(() => props.campaign.unsupported_criteria ?? [])
</script>

<template>
  <section
    aria-labelledby="audience-heading"
    class="space-y-4 rounded border border-slate-200 bg-white p-4"
  >
    <h3 id="audience-heading" class="font-semibold">Audience</h3>

    <ul class="flex flex-wrap gap-2" aria-label="Rule">
      <li
        v-for="chip in chips"
        :key="chip"
        class="rounded-full bg-indigo-50 px-3 py-1 text-sm text-indigo-900"
      >
        {{ chip }}
      </li>
    </ul>

    <div
      v-if="unsupported.length > 0"
      role="note"
      class="rounded border border-amber-300 bg-amber-50 p-3 text-sm"
    >
      <p class="font-medium text-amber-900">Not applied to the audience (no matching field):</p>
      <ul class="list-disc pl-5 text-amber-900">
        <li v-for="criterion in unsupported" :key="criterion">{{ criterion }}</li>
      </ul>
    </div>

    <dl class="grid grid-cols-3 gap-4">
      <div v-for="count in counts" :key="count.label" class="rounded bg-slate-50 p-3 text-center">
        <dt class="text-sm text-slate-600">{{ count.label }}</dt>
        <dd class="text-2xl font-semibold">{{ count.value }}</dd>
      </div>
    </dl>
  </section>
</template>
