<script setup lang="ts">
defineProps<{ body: string | undefined; streaming: boolean }>()
</script>

<template>
  <section aria-labelledby="sms-heading" class="space-y-2">
    <h4 id="sms-heading" class="text-sm font-medium text-slate-700">SMS</h4>
    <div class="mx-auto w-64 rounded-3xl border-4 border-slate-800 bg-slate-100 p-4">
      <div
        aria-live="polite"
        :aria-busy="streaming"
        class="min-h-16 whitespace-pre-wrap rounded-2xl rounded-tl-sm bg-white p-3 text-sm shadow-sm"
      >
        <template v-if="body">{{ body }}</template>
        <span v-else class="text-slate-400">{{ streaming ? 'Writing…' : 'No SMS yet.' }}</span>
      </div>
    </div>
  </section>
</template>
