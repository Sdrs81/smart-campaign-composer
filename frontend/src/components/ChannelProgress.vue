<script setup lang="ts">
import { computed } from 'vue'

import type { DeliveryStatus } from '../api/types'

const props = defineProps<{ label: string; counts: Record<DeliveryStatus | 'total', number> }>()

const BAR_WIDTH = 100

// Order is the order along the bar. "Waiting" folds the in-flight state into pending: both mean not finished yet.
const parts = computed(() => [
  {
    key: 'sent',
    label: 'Sent',
    value: props.counts.sent,
    fill: 'fill-emerald-500',
    swatch: 'bg-emerald-500',
  },
  {
    key: 'failed',
    label: 'Failed',
    value: props.counts.failed,
    fill: 'fill-red-500',
    swatch: 'bg-red-500',
  },
  {
    key: 'skipped',
    label: 'Skipped',
    value: props.counts.skipped,
    fill: 'fill-slate-400',
    swatch: 'bg-slate-400',
  },
  {
    key: 'waiting',
    label: 'Waiting',
    value: props.counts.pending + props.counts.sending,
    fill: 'fill-amber-400',
    swatch: 'bg-amber-400',
  },
])

// Drawn as SVG rectangles: the widths are data, and the project allows no inline styles.
const segments = computed(() => {
  let offset = 0
  return parts.value.map((part) => {
    const width = props.counts.total === 0 ? 0 : (part.value / props.counts.total) * BAR_WIDTH
    const segment = { ...part, x: offset, width }
    offset += width
    return segment
  })
})

const summary = computed(() => parts.value.map((part) => `${part.label} ${part.value}`).join(', '))
</script>

<template>
  <section class="space-y-2 rounded border border-slate-200 bg-white p-4">
    <div class="flex items-baseline justify-between">
      <h3 class="font-semibold">{{ label }}</h3>
      <p class="text-sm text-slate-600">{{ counts.total }} total</p>
    </div>

    <svg
      class="h-3 w-full rounded bg-slate-100"
      :viewBox="`0 0 ${BAR_WIDTH} 1`"
      preserveAspectRatio="none"
      role="img"
      :aria-label="`${label}: ${summary}`"
    >
      <rect
        v-for="segment in segments"
        :key="segment.key"
        :x="segment.x"
        y="0"
        :width="segment.width"
        height="1"
        :class="segment.fill"
        :data-segment="segment.key"
      />
    </svg>

    <dl class="grid grid-cols-4 gap-2 text-sm">
      <div v-for="part in parts" :key="part.key">
        <dt class="flex items-center gap-1 text-slate-600">
          <span class="inline-block size-2 rounded-full" :class="part.swatch" aria-hidden="true" />
          {{ part.label }}
        </dt>
        <dd class="text-lg font-semibold">{{ part.value }}</dd>
      </div>
    </dl>
  </section>
</template>
