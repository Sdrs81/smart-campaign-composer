import { getCurrentScope, onScopeDispose, ref, shallowRef } from 'vue'

import { api } from '../api/client'
import { toApiError } from '../api/errors'
import type { Campaign, EditableContent, FieldErrors, Preview } from '../api/types'

export const SAVE_DEBOUNCE_MS = 600

export type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'failed'

/**
 * Saves edited content after a pause in typing, keeps the server's complaints per field, and keeps the
 * preview (the saved text as one sample customer receives it) in step with what was saved.
 */
export function useContentEditing(campaignId: () => number, onSaved: (campaign: Campaign) => void) {
  const saveState = ref<SaveState>('idle')
  const fieldErrors = shallowRef<FieldErrors>({})
  const saveError = ref<string | null>(null)
  const preview = shallowRef<Preview | null>(null)
  const previewError = ref<string | null>(null)
  const previewCustomerId = ref<number | undefined>(undefined)

  let timer: ReturnType<typeof setTimeout> | null = null
  let queued: EditableContent | null = null
  let saving = false
  let previewRequest: AbortController | null = null

  function hasQueuedEdit(): boolean {
    return queued !== null
  }

  function edit(content: EditableContent): void {
    queued = content
    saveState.value = 'pending'
    if (timer !== null) clearTimeout(timer)
    timer = setTimeout(() => {
      timer = null
      void save()
    }, SAVE_DEBOUNCE_MS)
  }

  async function save(): Promise<void> {
    // One request at a time: a newer edit waits and is sent when this one is answered.
    if (saving || queued === null) return
    const content = queued
    queued = null
    saving = true
    saveState.value = 'saving'

    try {
      const saved = await api.updateContent(campaignId(), content)
      fieldErrors.value = {}
      saveError.value = null
      onSaved(saved)
      saveState.value = hasQueuedEdit() ? 'pending' : 'saved'
      void loadPreview()
    } catch (failure) {
      const error = toApiError(failure)
      fieldErrors.value = error.fieldErrors
      saveError.value = Object.keys(error.fieldErrors).length > 0 ? null : error.message
      saveState.value = 'failed'
    } finally {
      saving = false
      if (hasQueuedEdit() && timer === null) void save()
    }
  }

  async function loadPreview(): Promise<void> {
    previewRequest?.abort()
    const request = new AbortController()
    previewRequest = request
    try {
      preview.value = await api.previewCampaign(
        campaignId(),
        previewCustomerId.value,
        request.signal,
      )
      previewError.value = null
    } catch (failure) {
      if (!request.signal.aborted) previewError.value = toApiError(failure).message
    }
  }

  function selectCustomer(customerId: number | undefined): void {
    previewCustomerId.value = customerId
    void loadPreview()
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      if (timer !== null) clearTimeout(timer)
      timer = null
      previewRequest?.abort()
      // Leaving within the pause must not lose the last edit.
      void save()
    })
  }

  return {
    saveState,
    fieldErrors,
    saveError,
    preview,
    previewError,
    previewCustomerId,
    edit,
    loadPreview,
    selectCustomer,
  }
}
