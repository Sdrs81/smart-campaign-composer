import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'

import { api, ApiError } from '../api/client'
import type { Campaign, Paginated } from '../api/types'

export const useCampaignStore = defineStore('campaign', () => {
  // Replaced whole on every fetch, so it is not made deeply reactive.
  const campaigns = shallowRef<Campaign[]>([])
  const page = ref(1)
  const lastPage = ref(1)
  const loading = ref(false)
  const error = ref<string | null>(null)

  let inFlight: AbortController | null = null

  async function fetchList(requestedPage = 1): Promise<void> {
    // A newer request makes the older answer irrelevant; aborting it stops it overwriting the list.
    inFlight?.abort()
    const controller = new AbortController()
    inFlight = controller

    loading.value = true
    error.value = null
    try {
      const result: Paginated<Campaign> = await api.listCampaigns(requestedPage, controller.signal)
      campaigns.value = result.data
      page.value = result.meta.current_page
      lastPage.value = result.meta.last_page
    } catch (failure) {
      if (controller.signal.aborted) return
      error.value =
        failure instanceof ApiError
          ? failure.message
          : `Could not load campaigns: ${String(failure)}`
    } finally {
      if (inFlight === controller) {
        loading.value = false
        inFlight = null
      }
    }
  }

  function cancelFetch(): void {
    inFlight?.abort()
    inFlight = null
    loading.value = false
  }

  return { campaigns, page, lastPage, loading, error, fetchList, cancelFetch }
})
