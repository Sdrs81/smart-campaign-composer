import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { api } from '../../api/client'
import type { Campaign, ContentStreamEvent } from '../../api/types'
import ContentStream from '../ContentStream.vue'

const CAMPAIGN: Campaign = {
  id: 7,
  brief: 'Sepeti terk edenlere indirim',
  status: 'audience_ready',
  segment_rule: null,
  unsupported_criteria: null,
  audience_counts: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

const frames: (() => void)[] = []

beforeEach(() => {
  frames.length = 0
  vi.stubGlobal('requestAnimationFrame', (callback: () => void) => frames.push(callback))
  vi.stubGlobal('cancelAnimationFrame', () => frames.splice(0))
})
afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

function drive() {
  let emit: (event: ContentStreamEvent) => void = () => undefined
  let finish: () => void = () => undefined
  vi.spyOn(api, 'streamContent').mockImplementation((_id, _tone, handler, signal) => {
    emit = handler
    return new Promise<void>((resolve) => {
      finish = resolve
      signal?.addEventListener('abort', () => resolve())
    })
  })
  return { emit: (event: ContentStreamEvent) => emit(event), finish: () => finish() }
}

describe('ContentStream', () => {
  it('shows the text growing and a cancel button while streaming', async () => {
    const stream = drive()
    const wrapper = mount(ContentStream, { props: { campaign: CAMPAIGN } })

    await wrapper.find('button').trigger('click')
    stream.emit({ type: 'delta', text: '{"sms":{"body":"Merh' })
    frames.splice(0).forEach((callback) => callback())
    await flushPromises()

    expect(wrapper.text()).toContain('Merh')
    expect(wrapper.find('button').text()).toBe('Cancel')

    stream.emit({ type: 'delta', text: 'aba"}' })
    frames.splice(0).forEach((callback) => callback())
    await flushPromises()
    expect(wrapper.text()).toContain('Merhaba')
  })

  it('swaps in the checked content on final, says it was repaired and tells the page', async () => {
    const stream = drive()
    const wrapper = mount(ContentStream, { props: { campaign: CAMPAIGN } })

    await wrapper.find('button').trigger('click')
    stream.emit({ type: 'delta', text: '{"sms":{"body":"too long draft' })
    stream.emit({
      type: 'final',
      sms: { body: 'Kısa metin' },
      email: { subject: 'Konu', body: 'Gövde' },
      repaired: true,
    })
    stream.finish()
    await flushPromises()

    expect(wrapper.text()).not.toContain('too long draft')
    expect(wrapper.text()).toContain('rewritten automatically')
    expect(wrapper.emitted('completed')).toHaveLength(1)
  })

  it('starts by itself on autostart, once, and tells the page', async () => {
    const stream = drive()
    const wrapper = mount(ContentStream, { props: { campaign: CAMPAIGN, autostart: true } })
    await flushPromises()

    expect(api.streamContent).toHaveBeenCalledOnce()
    expect(wrapper.emitted('started')).toHaveLength(1)
    expect(wrapper.find('button').text()).toBe('Cancel')
    stream.finish()
  })

  it('starts when autostart turns true after the component was already shown', async () => {
    drive()
    const wrapper = mount(ContentStream, { props: { campaign: CAMPAIGN, autostart: false } })
    expect(api.streamContent).not.toHaveBeenCalled()

    await wrapper.setProps({ autostart: true })

    expect(api.streamContent).toHaveBeenCalledOnce()
    expect(wrapper.emitted('started')).toHaveLength(1)
  })

  it('waits for a click without autostart', () => {
    drive()
    mount(ContentStream, { props: { campaign: CAMPAIGN } })

    expect(api.streamContent).not.toHaveBeenCalled()
  })

  it('cancels the stream and says nothing was saved', async () => {
    const stream = drive()
    const wrapper = mount(ContentStream, { props: { campaign: CAMPAIGN } })

    await wrapper.find('button').trigger('click')
    stream.emit({ type: 'delta', text: '{"sms":{"body":"Yarım' })
    frames.splice(0).forEach((callback) => callback())
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Nothing was saved')
    expect(wrapper.text()).not.toContain('Yarım')
    expect(wrapper.find('button').text()).toBe('Generate content')
  })

  it('shows an error alert', async () => {
    const stream = drive()
    const wrapper = mount(ContentStream, { props: { campaign: CAMPAIGN } })

    await wrapper.find('button').trigger('click')
    stream.emit({
      type: 'error',
      code: 'llm_unavailable',
      message: 'Model unreachable.',
      errors: [],
    })
    stream.finish()
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('Model unreachable.')
  })

  it('offers to generate again when the campaign already has content', () => {
    const wrapper = mount(ContentStream, {
      props: {
        campaign: {
          ...CAMPAIGN,
          status: 'content_ready',
          messages: [
            { channel: 'sms', subject: null, body: 'Kayıtlı SMS', source: 'llm' },
            { channel: 'email', subject: 'Kayıtlı konu', body: 'Kayıtlı gövde', source: 'llm' },
          ],
        },
      },
    })

    // The editor shows saved text; this step only offers to write it again.
    expect(wrapper.text()).not.toContain('Kayıtlı SMS')
    expect(wrapper.find('button').text()).toBe('Generate again')
  })
})
