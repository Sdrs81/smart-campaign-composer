<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'

import LocaleSwitcher from './components/LocaleSwitcher.vue'
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <header
      class="flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-6 py-4"
    >
      <h1 class="text-xl font-semibold">
        <RouterLink
          :to="{ name: 'campaigns' }"
          class="hover:text-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
        >
          Smart Campaign Composer
        </RouterLink>
      </h1>
      <LocaleSwitcher />
    </header>
    <main class="px-6 py-8">
      <!-- Keyed by path (it holds the campaign id): a page for one campaign must not be reused for another,
           or it would keep the first campaign's open stream and data. A query or hash change keeps the page. -->
      <RouterView v-slot="{ Component, route }">
        <component :is="Component" :key="route.path" />
      </RouterView>
    </main>
  </div>
</template>
