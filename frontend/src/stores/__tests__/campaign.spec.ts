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

  it('uses the copy it just created once, then refetches on every later visit', async () => {
    // Regression: the held copy used to be shown again on a revisit, however old it had become.
    vi.spyOn(api, 'createCampaign').mockResolvedValue(campaign(5))
    vi.spyOn(api, 'defineAudience').mockResolvedValue({ ...campaign(5), status: 'audience_ready' })
    const fetchOne = vi
      .spyOn(api, 'getCampaign')
      .mockResolvedValue({ ...campaign(5), status: 'completed' })
    const store = useCampaignStore()

    await store.createAndExtract('Sepeti terk edenlere indirim')
    await store.loadCurrent(5)
    expect(fetchOne).not.toHaveBeenCalled()
    expect(store.current?.status).toBe('audience_ready')

    await store.loadCurrent(5)
    expect(fetchOne).toHaveBeenCalledExactlyOnceWith(5, expect.any(AbortSignal))
    expect(store.current?.status).toBe('completed')
  })

  it('fetches a campaign it did not create', async () => {
    const fetchOne = vi.spyOn(api, 'getCampaign').mockResolvedValue(campaign(9))
    const store = useCampaignStore()

    await store.loadCurrent(9)

    expect(fetchOne).toHaveBeenCalledOnce()
    expect(store.current?.id).toBe(9)
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

  describe('automatic first content (K26)', () => {
    const MESSAGE = { channel: 'sms', subject: null, body: 'Kayıtlı SMS', source: 'llm' } as const

    it('marks the campaign after its first successful extraction', async () => {
      vi.spyOn(api, 'createCampaign').mockResolvedValue(campaign(5))
      vi.spyOn(api, 'defineAudience').mockResolvedValue({
        ...campaign(5),
        status: 'audience_ready',
      })
      const store = useCampaignStore()

      await store.createAndExtract('Sepeti terk edenlere indirim')

      expect(store.autoGenerateFor).toBe(5)
      store.clearAutoGenerate()
      expect(store.autoGenerateFor).toBeNull()
    })

    it('does not mark a campaign that already has text', async () => {
      vi.spyOn(api, 'getCampaign').mockResolvedValue(campaign(6))
      vi.spyOn(api, 'defineAudience').mockResolvedValue({
        ...campaign(6),
        status: 'content_ready',
        messages: [MESSAGE],
      })
      const store = useCampaignStore()
      await store.loadCurrent(6)

      await store.extractAudience()

      expect(store.autoGenerateFor).toBeNull()
    })

    it('sends an edited brief with the extraction and marks the campaign again', async () => {
      vi.spyOn(api, 'getCampaign').mockResolvedValue(campaign(6))
      const define = vi
        .spyOn(api, 'defineAudience')
        .mockResolvedValue({ ...campaign(6), status: 'audience_ready', brief: 'Yeni özet' })
      const store = useCampaignStore()
      await store.loadCurrent(6)

      await store.extractAudience('Yeni özet')

      expect(define).toHaveBeenCalledExactlyOnceWith(6, 'Yeni özet')
      expect(store.current?.brief).toBe('Yeni özet')
      expect(store.autoGenerateFor).toBe(6)
    })

    it('does not mark a failed extraction', async () => {
      vi.spyOn(api, 'createCampaign').mockResolvedValue(campaign(7))
      vi.spyOn(api, 'defineAudience').mockRejectedValue(new ApiError(422, 'invalid', 'No.'))
      const store = useCampaignStore()

      await store.createAndExtract('Sepeti terk edenlere indirim')

      expect(store.autoGenerateFor).toBeNull()
    })

    it('forgets the mark when another campaign is opened', async () => {
      vi.spyOn(api, 'createCampaign').mockResolvedValue(campaign(5))
      vi.spyOn(api, 'defineAudience').mockResolvedValue({
        ...campaign(5),
        status: 'audience_ready',
      })
      vi.spyOn(api, 'getCampaign').mockResolvedValue(campaign(9))
      const store = useCampaignStore()
      await store.createAndExtract('Sepeti terk edenlere indirim')

      await store.loadCurrent(9)

      expect(store.autoGenerateFor).toBeNull()
    })
  })
})
