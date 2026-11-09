<script setup lang="ts">
import { computed, useId } from 'vue'

import { graphemeLength } from '../text/graphemeLength'

const props = defineProps<{
  limits: { min_length: number; max_length: number }
  busy: boolean
  readonly: boolean
  canSubmit: boolean
  submitLabel: string
}>()
const brief = defineModel<string>({ required: true })
const emit = defineEmits<{ submit: [] }>()

const fieldId = useId()
const count = computed(() => graphemeLength(brief.value))
const withinLimits = computed(
  () => count.value >= props.limits.min_length && count.value <= props.limits.max_length,
)
const hint = computed(() =>
  count.value > props.limits.max_length
    ? `Too long: at most ${props.limits.max_length} characters.`
    : `At least ${props.limits.min_length} characters.`,
)
</script>

<template>
  <form class="space-y-3" @submit.prevent="emit('submit')">
    <label :for="fieldId" class="block text-sm font-medium">Campaign brief</label>
    <textarea
      :id="fieldId"
      v-model="brief"
      rows="5"
      :readonly="readonly"
      :disabled="busy"
      :aria-describedby="`${fieldId}-count`"
      :aria-invalid="!readonly && brief !== '' && !withinLimits"
      class="w-full rounded border border-slate-300 bg-white p-3 focus:outline-2 focus:outline-indigo-600"
      placeholder="e.g. Customers who left items in their cart this week"
    />
    <div class="flex items-center justify-between gap-4">
      <p
        :id="`${fieldId}-count`"
        class="text-sm"
        :class="brief !== '' && !withinLimits ? 'text-red-700' : 'text-slate-600'"
      >
        {{ count }} / {{ limits.max_length }}
        <span v-if="!readonly && !withinLimits">· {{ hint }}</span>
      </p>
      <button
        v-if="canSubmit"
        type="submit"
        class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600"
        :disabled="busy || !withinLimits"
      >
        {{ busy ? 'Extracting audience…' : submitLabel }}
      </button>
    </div>
  </form>
</template>
