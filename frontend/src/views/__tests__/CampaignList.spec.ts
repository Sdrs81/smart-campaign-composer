import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { api } from '../../api/client'
import type { Campaign, CampaignStatus } from '../../api/types'
import { routes } from '../../router'
import CampaignList from '../CampaignList.vue'

function campaign(id: number, status: CampaignStatus): Campaign {
  return {
    id,
    brief: `Brief ${id}`,
    status,
    segment_rule: null,
    unsupported_criteria: null,
    audience_counts: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  }
}

async function mountList() {
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push('/campaigns')
  await router.isReady()
  const wrapper = mount(CampaignList, { global: { plugins: [createPinia(), router] } })
  await flushPromises()
  return { wrapper, router }
}

afterEach(() => vi.restoreAllMocks())

describe('CampaignList', () => {
  it('shows each campaign with a status badge and links to it', async () => {
    vi.spyOn(api, 'listCampaigns').mockResolvedValue({
      data: [campaign(2, 'sending'), campaign(1, 'content_ready')],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 },
    })

    const { wrapper } = await mountList()

    const rows = wrapper.findAll('li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Brief 2')
    expect(rows[0]!.text()).toContain('Sending')
    expect(rows[1]!.text()).toContain('Content ready')
    expect(rows[0]!.find('a').attributes('href')).toBe('/campaigns/2')
  })

  it('shows an empty message when there are no campaigns', async () => {
    vi.spyOn(api, 'listCampaigns').mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    })

    const { wrapper } = await mountList()

    expect(wrapper.text()).toContain('No campaigns yet')
  })

  it('announces a load failure', async () => {
    vi.spyOn(api, 'listCampaigns').mockRejectedValue(new Error('network down'))

    const { wrapper } = await mountList()

    expect(wrapper.find('[role="alert"]').text()).toContain('network down')
  })
})

describe('routes', () => {
  it('lazy-loads every page and redirects / to the list', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes })

    await router.push('/')
    expect(router.currentRoute.value.name).toBe('campaigns')

    await router.push('/campaigns/new')
    expect(router.currentRoute.value.name).toBe('campaign-new')

    await router.push('/campaigns/5')
    expect(router.currentRoute.value.name).toBe('campaign')

    await router.push('/campaigns/5/dashboard')
    expect(router.currentRoute.value.name).toBe('campaign-dashboard')
  })
})
