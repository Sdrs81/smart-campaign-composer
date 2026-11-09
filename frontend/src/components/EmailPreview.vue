<script setup lang="ts">
defineProps<{ subject: string | undefined; body: string | undefined; streaming: boolean }>()
</script>

<template>
  <section aria-labelledby="email-heading" class="space-y-2">
    <h4 id="email-heading" class="text-sm font-medium text-slate-700">Email</h4>
    <div aria-live="polite" :aria-busy="streaming" class="rounded border border-slate-200 bg-white">
      <p class="border-b border-slate-200 px-4 py-3 font-medium">
        <span class="mr-2 text-sm font-normal text-slate-500">Subject</span>
        <template v-if="subject">{{ subject }}</template>
        <span v-else class="text-slate-400">{{ streaming ? 'Writing…' : 'No subject yet.' }}</span>
      </p>
      <p class="min-h-24 whitespace-pre-wrap px-4 py-3 text-sm">
        <template v-if="body">{{ body }}</template>
        <span v-else class="text-slate-400">{{
          streaming ? 'Writing…' : 'No email body yet.'
        }}</span>
      </p>
    </div>
  </section>
</template>
