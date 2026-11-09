import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { api, ApiError } from '../../api/client'
import type { Campaign } from '../../api/types'
import { useCampaignStore } from '../campaign'

function campaign(id: number): Campaign {
  return {
    id,
    brief: `Brief ${id}`,
    status: 'draft',
    segment_rule: null,
    unsupported_criteria: null,
    audience_counts: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  }
}

function pageOf(items: Campaign[], current = 1, last = 1) {
  return {
    data: items,
    meta: { current_page: current, last_page: last, per_page: 15, total: items.length },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.restoreAllMocks()
})

describe('campaign store', () => {
  it('loads a page of campaigns', async () => {
    vi.spyOn(api, 'listCampaigns').mockResolvedValue(pageOf([campaign(2), campaign(1)], 1, 3))
    const store = useCampaignStore()

    await store.fetchList(1)

    expect(store.campaigns.map((item) => item.id)).toEqual([2, 1])
    expect(store.lastPage).toBe(3)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('keeps the API message when loading fails', async () => {
    vi.spyOn(api, 'listCampaigns').mockRejectedValue(
      new ApiError(503, 'unavailable', 'Service is down.'),
    )
    const store = useCampaignStore()

    await store.fetchList()

    expect(store.error).toBe('Service is down.')
    expect(store.loading).toBe(false)
  })

  it('ignores the answer of a request that a newer one replaced', async () => {
    const answers: ((value: ReturnType<typeof pageOf>) => void)[] = []
    vi.spyOn(api, 'listCampaigns').mockImplementation(
      (_page, signal) =>
        new Promise((resolve, reject) => {
          answers.push(resolve)
          signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
        }),
    )
    const store = useCampaignStore()

    const first = store.fetchList(1)
    const second = store.fetchList(2)
    answers[1]!(pageOf([campaign(20)], 2, 2))
    await Promise.all([first, second])

    expect(store.campaigns.map((item) => item.id)).toEqual([20])
    expect(store.page).toBe(2)
    expect(store.error).toBeNull()
  })
})
