<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ count: number; max: number }>()

const over = computed(() => props.count > props.max)
</script>

<template>
  <p
    class="text-sm"
    :class="over ? 'font-medium text-red-700' : 'text-slate-600'"
    :data-over="over"
  >
    {{ count }} / {{ max }}
    <span v-if="over" role="status">· {{ count - max }} over the limit</span>
  </p>
</template>
