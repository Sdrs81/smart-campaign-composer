<script setup lang="ts">
import { computed, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { api, parseStats } from '../api/client'
import type { Channel, CampaignStats } from '../api/types'
import ChannelProgress from '../components/ChannelProgress.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { useEventSource } from '../composables/useEventSource'

const props = defineProps<{ id: number }>()

const { t, locale } = useI18n()

const CHANNELS: Channel[] = ['sms', 'email']
const TERMINAL_STATUSES = ['completed', 'failed']

// A snapshot replaces the previous one whole, so it is not made deeply reactive.
const stats = shallowRef<CampaignStats | null>(null)

// The server sends `end` once the campaign is final; the browser would otherwise reconnect for good.
const { connected, lost } = useEventSource(api.statsStreamUrl(props.id), {
  handlers: {
    stats: (payload) => {
      // A frame that is not a snapshot is skipped; the last good one stays on screen.
      stats.value = parseStats(payload) ?? stats.value
    },
  },
  closeOn: ['end'],
})

const finished = computed(
  () => stats.value !== null && TERMINAL_STATUSES.includes(stats.value.status),
)
const connection = computed(() => {
  if (finished.value) return t('dashboard.finished')
  if (lost.value) return t('dashboard.lost')
  if (connected.value) return t('dashboard.live')
  return stats.value === null ? t('dashboard.connecting') : t('dashboard.reconnecting')
})
const formatTime = (iso: string): string => new Date(iso).toLocaleTimeString(locale.value)
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-6" aria-labelledby="dashboard-heading">
    <div class="flex items-center justify-between gap-4">
      <h2 id="dashboard-heading" class="text-lg font-semibold">
        {{ t('dashboard.title', { id }) }}
      </h2>
      <div class="flex items-center gap-3">
        <span aria-live="polite" class="text-sm text-slate-600">{{ connection }}</span>
        <StatusBadge v-if="stats" :status="stats.status" />
      </div>
    </div>

    <p v-if="!stats" class="text-slate-600">{{ t('dashboard.waiting') }}</p>

    <template v-else>
      <div class="grid gap-4 md:grid-cols-2">
        <ChannelProgress
          v-for="channel in CHANNELS"
          :key="channel"
          :label="t(`channel.${channel}`)"
          :counts="stats.channels[channel]"
        />
      </div>

      <dl class="grid grid-cols-2 gap-4">
        <div class="rounded border border-slate-200 bg-white p-4">
          <dt class="text-sm text-slate-600">{{ t('dashboard.sendRate') }}</dt>
          <dd class="text-2xl font-semibold">
            {{ stats.send_rate_per_second.toFixed(1)
            }}<span class="text-sm font-normal text-slate-600">
              {{ t('dashboard.perSecond') }}</span
            >
          </dd>
        </div>
        <div class="rounded border border-slate-200 bg-white p-4">
          <dt class="text-sm text-slate-600">{{ t('dashboard.retries') }}</dt>
          <dd class="text-2xl font-semibold">{{ stats.total_retries }}</dd>
        </div>
      </dl>

      <section aria-labelledby="errors-heading" class="space-y-2">
        <h3 id="errors-heading" class="font-semibold">{{ t('dashboard.recentErrors') }}</h3>
        <p v-if="stats.recent_errors.length === 0" class="text-sm text-slate-600">
          {{ t('dashboard.noErrors') }}
        </p>
        <div v-else class="overflow-x-auto rounded border border-slate-200 bg-white">
          <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th scope="col" class="px-3 py-2">{{ t('dashboard.time') }}</th>
                <th scope="col" class="px-3 py-2">{{ t('dashboard.channel') }}</th>
                <th scope="col" class="px-3 py-2">{{ t('dashboard.status') }}</th>
                <th scope="col" class="px-3 py-2">{{ t('dashboard.attempts') }}</th>
                <th scope="col" class="px-3 py-2">{{ t('dashboard.error') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="failure in stats.recent_errors" :key="failure.delivery_id">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatTime(failure.at) }}</td>
                <td class="px-3 py-2">{{ t(`channel.${failure.channel}`) }}</td>
                <td class="px-3 py-2">{{ t(`deliveryStatus.${failure.status}`) }}</td>
                <td class="px-3 py-2">{{ failure.attempts }}</td>
                <td class="px-3 py-2">{{ failure.error }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <RouterLink
      :to="{ name: 'campaigns' }"
      class="inline-block text-sm text-indigo-700 underline focus:outline-2 focus:outline-indigo-600"
    >
      {{ t('dashboard.back') }}
    </RouterLink>
  </section>
</template>
