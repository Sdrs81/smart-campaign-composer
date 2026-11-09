import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { api, ApiError } from '../../api/client'
import type { Campaign } from '../../api/types'
import { useCampaignStore } from '../../stores/campaign'
import ApproveDialog from '../ApproveDialog.vue'

const CAMPAIGN: Campaign = {
  id: 7,
  brief: 'Sepeti terk edenlere indirim',
  status: 'content_ready',
  segment_rule: null,
  unsupported_criteria: null,
  audience_counts: {
    channel_counts: { sms: 380, email: 417 },
    unique_total: 485,
    sample: [],
    longest_values: {},
  },
  messages: [],
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

function mountDialog(canApprove = true) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useCampaignStore().current = CAMPAIGN
  return mount(ApproveDialog, {
    props: { campaign: CAMPAIGN, canApprove },
    global: { plugins: [pinia] },
    attachTo: document.body,
  })
}

const buttonLabelled = (wrapper: ReturnType<typeof mountDialog>, label: string) =>
  wrapper.findAll('button').find((button) => button.text() === label)

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('ApproveDialog', () => {
  it('keeps the approve button closed while the content is not ready', () => {
    const wrapper = mountDialog(false)

    expect(buttonLabelled(wrapper, 'Approve campaign')!.attributes('disabled')).toBeDefined()
  })

  it('shows the channel counts in the confirmation and focuses the safe choice', async () => {
    const wrapper = mountDialog()

    await buttonLabelled(wrapper, 'Approve campaign')!.trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.findAll('dd').map((count) => count.text())).toEqual(['380', '417', '485'])
    expect(document.activeElement?.textContent?.trim()).toBe('Cancel')
  })

  it('sends one request for a double click and reports the approved campaign', async () => {
    let answer: (campaign: Campaign) => void = () => undefined
    const approve = vi
      .spyOn(api, 'approveCampaign')
      .mockImplementation(() => new Promise<Campaign>((resolve) => (answer = resolve)))
    const wrapper = mountDialog()
    await buttonLabelled(wrapper, 'Approve campaign')!.trigger('click')
    const confirm = buttonLabelled(wrapper, 'Approve and send')!

    // Both clicks land before Vue has re-rendered the disabled button.
    void confirm.trigger('click')
    void confirm.trigger('click')
    await flushPromises()
    expect(approve).toHaveBeenCalledTimes(1)
    expect(buttonLabelled(wrapper, 'Approving…')!.attributes('disabled')).toBeDefined()

    answer({ ...CAMPAIGN, status: 'approved' })
    await flushPromises()

    expect(approve).toHaveBeenCalledExactlyOnceWith(7)
    expect(wrapper.emitted('approved')).toHaveLength(1)
    expect(wrapper.emitted('approved')![0]![0]).toMatchObject({ status: 'approved' })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('shows the refusal, keeps the dialog open and allows another try', async () => {
    const approve = vi
      .spyOn(api, 'approveCampaign')
      .mockRejectedValueOnce(
        new ApiError(422, 'validation_failed', 'The given data was invalid.', {
          errors: { 'sms.body': ['is up to 161 characters; the limit is 160.'] },
        }),
      )
      .mockResolvedValueOnce({ ...CAMPAIGN, status: 'approved' })
    const wrapper = mountDialog()
    await buttonLabelled(wrapper, 'Approve campaign')!.trigger('click')

    await buttonLabelled(wrapper, 'Approve and send')!.trigger('click')
    await flushPromises()

    const alert = wrapper.find('[role="alert"]')
    expect(alert.text()).toContain('The given data was invalid.')
    expect(alert.text()).toContain('sms.body: is up to 161 characters; the limit is 160.')
    expect(wrapper.emitted('approved')).toBeUndefined()

    await buttonLabelled(wrapper, 'Approve and send')!.trigger('click')
    await flushPromises()

    expect(approve).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('approved')).toHaveLength(1)
  })

  it('closes on Escape and returns focus to the approve button', async () => {
    const wrapper = mountDialog()
    await buttonLabelled(wrapper, 'Approve campaign')!.trigger('click')

    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Escape' })
    await flushPromises()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(document.activeElement?.textContent?.trim()).toBe('Approve campaign')
  })
})
