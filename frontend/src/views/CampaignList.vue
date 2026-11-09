<script setup lang="ts">
import { onMounted, onScopeDispose } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import StatusBadge from '../components/StatusBadge.vue'
import { useCampaignStore } from '../stores/campaign'

const store = useCampaignStore()
const { t } = useI18n()

onMounted(() => store.fetchList(1))
// An unfinished request must not keep running after the user leaves the page.
onScopeDispose(store.cancelFetch)
</script>

<template>
  <section aria-labelledby="campaigns-heading">
    <div class="mb-6 flex items-center justify-between">
      <h2 id="campaigns-heading" class="text-lg font-semibold">{{ t('list.heading') }}</h2>
      <RouterLink
        :to="{ name: 'campaign-new' }"
        class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600"
      >
        {{ t('list.newCampaign') }}
      </RouterLink>
    </div>

    <div aria-live="polite">
      <p v-if="store.loading" class="text-slate-600">{{ t('list.loading') }}</p>
      <p v-else-if="store.error" role="alert" class="text-red-700">{{ store.error }}</p>
      <p v-else-if="store.campaigns.length === 0" class="text-slate-600">
        {{ t('list.empty') }}
      </p>
    </div>

    <ul
      v-if="store.campaigns.length > 0"
      class="divide-y divide-slate-200 rounded border border-slate-200 bg-white"
    >
      <li v-for="campaign in store.campaigns" :key="campaign.id">
        <RouterLink
          :to="{ name: 'campaign', params: { id: campaign.id } }"
          class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50 focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
        >
          <span class="truncate">
            <span class="mr-2 text-slate-500">#{{ campaign.id }}</span>
            {{ campaign.brief }}
          </span>
          <StatusBadge :status="campaign.status" />
        </RouterLink>
      </li>
    </ul>

    <nav
      v-if="store.lastPage > 1"
      :aria-label="t('list.pagination')"
      class="mt-4 flex items-center gap-4"
    >
      <button
        type="button"
        class="rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-50 focus:outline-2 focus:outline-indigo-600"
        :disabled="store.page <= 1 || store.loading"
        @click="store.fetchList(store.page - 1)"
      >
        {{ t('list.previous') }}
      </button>
      <span class="text-sm text-slate-600">
        {{ t('list.page', { page: store.page, last: store.lastPage }) }}
      </span>
      <button
        type="button"
        class="rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-50 focus:outline-2 focus:outline-indigo-600"
        :disabled="store.page >= store.lastPage || store.loading"
        @click="store.fetchList(store.page + 1)"
      >
        {{ t('list.next') }}
      </button>
    </nav>
  </section>
</template>
