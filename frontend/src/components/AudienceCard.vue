<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { Campaign, ContentRules } from '../api/types'
import { describeCondition } from '../segment/describeCondition'

const props = defineProps<{ campaign: Campaign; segment: ContentRules['segment'] }>()
const { t } = useI18n()

const chips = computed(() =>
  (props.campaign.segment_rule?.conditions ?? []).map((condition) =>
    describeCondition(condition, props.segment),
  ),
)
const counts = computed(() => [
  { label: t('channel.sms'), value: props.campaign.audience_counts?.channel_counts.sms ?? 0 },
  { label: t('channel.email'), value: props.campaign.audience_counts?.channel_counts.email ?? 0 },
  { label: t('total'), value: props.campaign.audience_counts?.unique_total ?? 0 },
])
const unsupported = computed(() => props.campaign.unsupported_criteria ?? [])
</script>

<template>
  <section
    aria-labelledby="audience-heading"
    class="space-y-4 rounded border border-slate-200 bg-white p-4"
  >
    <h3 id="audience-heading" class="font-semibold">{{ t('audience.heading') }}</h3>

    <ul class="flex flex-wrap gap-2" :aria-label="t('audience.rule')">
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
      <p class="font-medium text-amber-900">{{ t('audience.unsupported') }}</p>
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
