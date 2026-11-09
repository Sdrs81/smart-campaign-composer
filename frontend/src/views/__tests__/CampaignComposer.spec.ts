import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { api, ApiError } from '../../api/client'
import type { Campaign, ContentRules } from '../../api/types'
import { routes } from '../../router'
import CampaignComposer from '../CampaignComposer.vue'

const BRIEF = 'Sepeti terk eden altın seviye müşterilere indirim gönder'

const RULES: ContentRules = {
  channels: {},
  limits: {},
  variables: [],
  brief: { min_length: 10, max_length: 1000 },
  segment: {
    fields: [
      { name: 'loyalty_tier', label: 'Sadakat seviyesi' },
      { name: 'last_cart_add_at', label: 'Sepete son ekleme tarihi' },
    ],
    operators: [
      { name: 'in', template: '{value}' },
      { name: 'within_last_days', template: 'son {value} gün içinde' },
    ],
  },
}

function campaign(overrides: Partial<Campaign> = {}): Campaign {
  return {
    id: 5,
    brief: BRIEF,
    status: 'draft',
    segment_rule: null,
    unsupported_criteria: null,
    audience_counts: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

const EXTRACTED = campaign({
  status: 'audience_ready',
  segment_rule: {
    version: 1,
    conditions: [
      { field: 'loyalty_tier', operator: 'in', value: ['gold', 'platinum'] },
      { field: 'last_cart_add_at', operator: 'within_last_days', value: 7 },
    ],
  },
  unsupported_criteria: ['iPhone kullananlar'],
  audience_counts: {
    channel_counts: { sms: 120, email: 90 },
    unique_total: 150,
    sample: [],
    longest_values: {},
  },
})

async function mountComposer(path: string, rules: Partial<ContentRules> = {}) {
  vi.spyOn(api, 'getContentRules').mockResolvedValue({ ...RULES, ...rules })
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(CampaignComposer, {
    props: { id: path === '/campaigns/new' ? undefined : 5 },
    global: { plugins: [createPinia(), router] },
  })
  await flushPromises()
  return { wrapper, router }
}

async function submitBrief(wrapper: Awaited<ReturnType<typeof mountComposer>>['wrapper']) {
  await wrapper.find('textarea').setValue(BRIEF)
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

afterEach(() => vi.restoreAllMocks())

describe('CampaignComposer', () => {
  it('counts characters against the server limit and disables a too-short brief', async () => {
    const { wrapper } = await mountComposer('/campaigns/new')

    await wrapper.find('textarea').setValue('kısa')

    expect(wrapper.text()).toContain('4 / 1000')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('counts an emoji family as one character', async () => {
    const { wrapper } = await mountComposer('/campaigns/new')

    await wrapper.find('textarea').setValue('👨‍👩‍👧')

    expect(wrapper.text()).toContain('1 / 1000')
  })

  it('creates the campaign, extracts the audience and shows chips and counts', async () => {
    const create = vi.spyOn(api, 'createCampaign').mockResolvedValue(campaign())
    const extract = vi.spyOn(api, 'defineAudience').mockResolvedValue(EXTRACTED)
    const { wrapper, router } = await mountComposer('/campaigns/new')

    await submitBrief(wrapper)

    expect(create).toHaveBeenCalledWith(BRIEF)
    expect(extract).toHaveBeenCalledWith(5)
    expect(router.currentRoute.value.name).toBe('campaign')
    const chips = wrapper.findAll('ul[aria-label="Rule"] li').map((chip) => chip.text())
    expect(chips).toEqual([
      'Sadakat seviyesi: gold, platinum',
      'Sepete son ekleme tarihi: son 7 gün içinde',
    ])
    expect(wrapper.text()).toContain('iPhone kullananlar')
    const counts = wrapper.findAll('dd').map((count) => count.text())
    expect(counts).toEqual(['120', '90', '150'])
  })

  it('shows the model problems on a 422 and keeps the campaign for a retry', async () => {
    vi.spyOn(api, 'createCampaign').mockResolvedValue(campaign())
    const extract = vi
      .spyOn(api, 'defineAudience')
      .mockRejectedValueOnce(
        new ApiError(
          422,
          'segment_extraction_failed',
          'The brief could not be turned into a rule.',
          {
            errors: ['conditions[1].value: must be a number'],
            unsupported_criteria: ['iPhone kullananlar'],
          },
        ),
      )
      .mockResolvedValueOnce(EXTRACTED)
    const { wrapper } = await mountComposer('/campaigns/new')

    await submitBrief(wrapper)

    const alert = wrapper.find('[role="alert"]')
    expect(alert.text()).toContain('could not be turned into a rule')
    expect(alert.text()).toContain('conditions[1].value: must be a number')
    expect(alert.text()).toContain('iPhone kullananlar')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(extract).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('dd').map((count) => count.text())).toEqual(['120', '90', '150'])
  })

  it('tells the marketer to try again on a 503', async () => {
    vi.spyOn(api, 'createCampaign').mockResolvedValue(campaign())
    vi.spyOn(api, 'defineAudience').mockRejectedValue(
      new ApiError(503, 'llm_unavailable', 'The language model is unreachable.'),
    )
    const { wrapper } = await mountComposer('/campaigns/new')

    await submitBrief(wrapper)

    const alert = wrapper.find('[role="alert"]')
    expect(alert.text()).toContain('language model is unreachable')
    expect(alert.text()).toContain('try again')
    expect(wrapper.find('button[type="submit"]').text()).toBe('Extract audience')
  })

  it('shows a saved campaign read-only and hides the button once approved', async () => {
    vi.spyOn(api, 'getCampaign').mockResolvedValue({ ...EXTRACTED, status: 'approved' })
    const { wrapper } = await mountComposer('/campaigns/5')

    expect(wrapper.find('textarea').attributes('readonly')).toBeDefined()
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('can no longer be changed')
    expect(wrapper.findAll('ul[aria-label="Rule"] li')).toHaveLength(2)
  })

  it('approves from the confirmation and moves on to the dashboard', async () => {
    // Regression: the page used to swap the campaign to "approved" first, which removed the dialog
    // before it could report the approval, so nobody navigated.
    const ready: Campaign = {
      ...EXTRACTED,
      status: 'content_ready',
      messages: [
        { channel: 'sms', subject: null, body: 'Merhaba!', source: 'llm' },
        { channel: 'email', subject: 'Konu', body: 'Gövde', source: 'llm' },
      ],
    }
    vi.spyOn(api, 'getCampaign').mockResolvedValue(ready)
    vi.spyOn(api, 'previewCampaign').mockResolvedValue({ customer: { id: null }, messages: [] })
    const approve = vi
      .spyOn(api, 'approveCampaign')
      .mockResolvedValue({ ...ready, status: 'approved' })
    const { wrapper, router } = await mountComposer('/campaigns/5', {
      limits: { sms_body_max: 160, email_subject_max: 80, email_body_max: 2000 },
    })

    const open = wrapper.findAll('button').find((button) => button.text() === 'Approve campaign')!
    await open.trigger('click')
    const confirm = wrapper
      .findAll('button')
      .find((button) => button.text() === 'Approve and send')!
    await confirm.trigger('click')
    await flushPromises()

    expect(approve).toHaveBeenCalledExactlyOnceWith(5)
    // The dashboard page is a lazy import, so the navigation finishes a moment after the click.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('campaign-dashboard'))
    expect(router.currentRoute.value.params.id).toBe('5')
  })
})
