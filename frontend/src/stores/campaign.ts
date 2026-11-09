import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'

import { api, ApiError } from '../api/client'
import { toApiError } from '../api/errors'
import { i18n } from '../i18n'
import type { Campaign, ContentRules, Paginated } from '../api/types'

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
          : i18n.global.t('list.loadFailed', { reason: String(failure) })
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

  // The campaign being composed. Replaced whole by each answer, so it is not made deeply reactive.
  const current = shallowRef<Campaign | null>(null)
  const rules = shallowRef<ContentRules | null>(null)
  const composing = ref(false)
  const composeError = shallowRef<ApiError | null>(null)
  let loadingCurrent: AbortController | null = null
  // True only between creating a campaign here and the page that opens it: that copy is newer than a
  // refetch and carries the extraction error. Every later visit must fetch, or it shows a stale copy.
  let adoptHeldCopy = false

  async function loadRules(): Promise<void> {
    if (rules.value) return
    try {
      rules.value = await api.getContentRules()
    } catch (failure) {
      composeError.value = toApiError(failure)
    }
  }

  async function loadCurrent(id: number): Promise<void> {
    if (adoptHeldCopy && current.value?.id === id) {
      adoptHeldCopy = false
      return
    }
    adoptHeldCopy = false

    loadingCurrent?.abort()
    const controller = new AbortController()
    loadingCurrent = controller
    current.value = null
    composeError.value = null
    composing.value = true
    try {
      current.value = await api.getCampaign(id, controller.signal)
    } catch (failure) {
      if (!controller.signal.aborted) composeError.value = toApiError(failure)
    } finally {
      if (loadingCurrent === controller) {
        composing.value = false
        loadingCurrent = null
      }
    }
  }

  const approving = ref(false)
  const approvalError = shallowRef<ApiError | null>(null)

  /**
   * Approves the held campaign and returns the approved campaign, or null when refused or already in progress:
   * a second call while the first is unanswered sends nothing (the server also treats a repeat as one approval).
   * It does not replace `current`: that would remove the approval dialog before it could report the result,
   * so the caller applies the answer.
   */
  async function approveCurrent(): Promise<Campaign | null> {
    const campaign = current.value
    if (!campaign || approving.value) return null

    approving.value = true
    approvalError.value = null
    try {
      return await api.approveCampaign(campaign.id)
    } catch (failure) {
      approvalError.value = toApiError(failure)
      return null
    } finally {
      approving.value = false
    }
  }

  function clearApprovalError(): void {
    approvalError.value = null
  }

  /** Refetches the held campaign, e.g. after content generation moved it to `content_ready`. */
  async function reloadCurrent(): Promise<void> {
    const id = current.value?.id
    if (id === undefined) return
    try {
      current.value = await api.getCampaign(id)
    } catch (failure) {
      composeError.value = toApiError(failure)
    }
  }

  function leaveCurrent(): void {
    loadingCurrent?.abort()
    loadingCurrent = null
    composing.value = false
  }

  function startNew(): void {
    current.value = null
    composeError.value = null
    adoptHeldCopy = false
  }

  /** Runs the audience extraction for the held campaign. A failure is kept in `composeError`, the campaign stays. */
  async function extractAudience(): Promise<void> {
    const campaign = current.value
    if (!campaign) return
    composing.value = true
    composeError.value = null
    try {
      current.value = await api.defineAudience(campaign.id)
    } catch (failure) {
      composeError.value = toApiError(failure)
    } finally {
      composing.value = false
    }
  }

  /** Creates the campaign, then extracts its audience. Returns the new id, or null when creating failed. */
  async function createAndExtract(brief: string): Promise<number | null> {
    composing.value = true
    composeError.value = null
    try {
      current.value = await api.createCampaign(brief)
    } catch (failure) {
      composeError.value = toApiError(failure)
      composing.value = false
      return null
    }
    adoptHeldCopy = true
    await extractAudience()
    return current.value?.id ?? null
  }

  return {
    campaigns,
    page,
    lastPage,
    loading,
    error,
    fetchList,
    cancelFetch,
    current,
    rules,
    composing,
    composeError,
    loadRules,
    loadCurrent,
    reloadCurrent,
    approving,
    approvalError,
    approveCurrent,
    clearApprovalError,
    leaveCurrent,
    startNew,
    extractAudience,
    createAndExtract,
  }
})
