<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ count: number; max: number }>()
const { t } = useI18n()

const over = computed(() => props.count > props.max)
</script>

<template>
  <p
    class="text-sm"
    :class="over ? 'font-medium text-red-700' : 'text-slate-600'"
    :data-over="over"
  >
    {{ count }} / {{ max }}
    <span v-if="over" role="status">· {{ t('counter.over', { amount: count - max }) }}</span>
  </p>
</template>
