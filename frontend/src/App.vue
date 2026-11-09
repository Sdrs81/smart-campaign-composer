<script setup lang="ts">
import { RouterView } from 'vue-router'

import LocaleSwitcher from './components/LocaleSwitcher.vue'
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <header
      class="flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-6 py-4"
    >
      <h1 class="text-xl font-semibold">Smart Campaign Composer</h1>
      <LocaleSwitcher />
    </header>
    <main class="px-6 py-8">
      <RouterView />
    </main>
  </div>
</template>
