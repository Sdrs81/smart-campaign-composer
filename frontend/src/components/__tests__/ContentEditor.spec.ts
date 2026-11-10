import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { api, ApiError } from '../../api/client'
import type { Campaign, ContentRules, Preview } from '../../api/types'
import { SAVE_DEBOUNCE_MS } from '../../composables/useContentEditing'
import ContentEditor from '../ContentEditor.vue'

const RULES: ContentRules = {
  channels: {},
  limits: { sms_body_max: 160, email_subject_max: 80, email_body_max: 2000 },
  variables: [
    { name: 'first_name', fallback: 'Değerli müşterimiz' },
    { name: 'city', fallback: 'bölgeniz' },
  ],
  brief: { min_length: 10, max_length: 1000 },
  segment: { fields: [], operators: [] },
}

function campaign(overrides: Partial<Campaign> = {}, smsBody = 'Merhaba!'): Campaign {
  return {
    id: 7,
    brief: 'Sepeti terk edenlere indirim',
    status: 'content_ready',
    segment_rule: null,
    unsupported_criteria: null,
    audience_counts: {
      channel_counts: { sms: 10, email: 10 },
      unique_total: 10,
      sample: [
        { id: 3, first_name: 'Ece', last_name: 'Karaduman', city: 'İstanbul' },
        { id: 5, first_name: null, last_name: 'Çörekçi', city: 'Ankara' },
      ],
      longest_values: { sms: { first_name: 25, city: 8 }, email: { first_name: 25, city: 8 } },
    },
    messages: [
      { channel: 'sms', subject: null, body: smsBody, source: 'llm' },
      { channel: 'email', subject: 'Konu', body: 'Gövde', source: 'llm' },
    ],
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

const PREVIEW: Preview = {
  customer: { id: 3 },
  messages: [
    { channel: 'sms', subject: null, body: 'Merhaba Ece!', lengths: { subject: null, body: 12 } },
    { channel: 'email', subject: 'Konu', body: 'Gövde', lengths: { subject: 4, body: 5 } },
  ],
}

// Every editor is closed after its test: one left open keeps its real 600 ms save timer running and
// would call the next test's updateContent spy, which only shows when the machine is slow.
const mounted: ReturnType<typeof mount>[] = []

function mountEditor(source: Campaign = campaign()) {
  const wrapper = mount(ContentEditor, {
    props: { campaign: source, rules: RULES },
    attachTo: document.body,
  })
  mounted.push(wrapper)
  return wrapper
}

async function typeSms(wrapper: ReturnType<typeof mountEditor>, text: string) {
  await wrapper.find('textarea').setValue(text)
}

beforeEach(() => {
  vi.spyOn(api, 'previewCampaign').mockResolvedValue(PREVIEW)
  // Closing an editor with an unsent edit saves it; that must never reach the real API.
  vi.spyOn(api, 'updateContent').mockResolvedValue(campaign())
})
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  vi.useRealTimers()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('ContentEditor counter', () => {
  it('stays calm at 160 characters and turns red at 161, closing approval', async () => {
    const wrapper = mountEditor()
    await flushPromises()
    expect(wrapper.emitted('can-approve')?.at(-1)).toEqual([true])

    await typeSms(wrapper, 'a'.repeat(160))
    expect(wrapper.find('[data-over]').attributes('data-over')).toBe('false')

    await typeSms(wrapper, 'a'.repeat(161))
    const counter = wrapper.find('[data-over]')
    expect(counter.text()).toContain('161 / 160')
    expect(counter.attributes('data-over')).toBe('true')
    expect(counter.classes()).toContain('text-red-700')
    expect(wrapper.emitted('can-approve')?.at(-1)).toEqual([false])
  })

  it('counts an emoji family and Turkish letters as the backend does', async () => {
    const wrapper = mountEditor()
    await flushPromises()

    await typeSms(wrapper, '\u{1F468}‍\u{1F469}‍\u{1F467}\u{1F44D}şğ')

    expect(wrapper.text()).toContain('4 / 160')
  })

  it('counts a variable as the longest customer value, or its fallback if that is longer', async () => {
    const wrapper = mountEditor()
    await flushPromises()

    await typeSms(wrapper, 'Merhaba {{first_name}}!')
    expect(wrapper.text()).toContain(`${8 + 25 + 1} / 160`)

    const shortNames = campaign()
    shortNames.audience_counts!.longest_values = { sms: { first_name: 5 } }
    const second = mountEditor(shortNames)
    await flushPromises()
    await second.find('textarea').setValue('Merhaba {{first_name}}!')
    expect(second.text()).toContain(`${8 + 18 + 1} / 160`)
  })

  it('does not allow approval for an empty field', async () => {
    const wrapper = mountEditor()
    await flushPromises()

    await typeSms(wrapper, '   ')

    expect(wrapper.emitted('can-approve')?.at(-1)).toEqual([false])
  })
})

describe('ContentEditor saving', () => {
  it('sends one PUT with all fields after typing pauses, however many keystrokes', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const update = vi.spyOn(api, 'updateContent').mockResolvedValue(campaign({}, 'Yeni'))
    const wrapper = mountEditor()
    await flushPromises()

    await typeSms(wrapper, 'Y')
    await typeSms(wrapper, 'Ye')
    await typeSms(wrapper, 'Yeni')
    expect(update).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Unsaved changes')
    expect(wrapper.emitted('can-approve')?.at(-1)).toEqual([false])

    await vi.advanceTimersByTimeAsync(SAVE_DEBOUNCE_MS)
    await flushPromises()

    expect(update).toHaveBeenCalledExactlyOnceWith(7, {
      sms: { body: 'Yeni' },
      email: { subject: 'Konu', body: 'Gövde' },
    })
    expect(wrapper.text()).toContain('Saved')
    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(wrapper.emitted('can-approve')?.at(-1)).toEqual([true])
  })

  it('shows the backend 422 message under the field and keeps approval closed', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const message =
      'is up to 161 characters once the longest customer values are filled in; the limit is 160.'
    vi.spyOn(api, 'updateContent').mockRejectedValue(
      new ApiError(422, 'validation_failed', 'The sms.body field is invalid.', {
        errors: { 'sms.body': [message] },
      }),
    )
    const wrapper = mountEditor()
    await flushPromises()

    await typeSms(wrapper, 'Merhaba {{first_name}} {{city}}')
    await vi.advanceTimersByTimeAsync(SAVE_DEBOUNCE_MS)
    await flushPromises()

    const alert = wrapper.find('[role="alert"]')
    expect(alert.text()).toBe(message)
    expect(wrapper.text()).toContain('Not saved')
    expect(wrapper.emitted('can-approve')?.at(-1)).toEqual([false])
  })

  it('shows a conflict that is not about a field as a general alert', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    vi.spyOn(api, 'updateContent').mockRejectedValue(
      new ApiError(
        409,
        'campaign_not_editable',
        'The campaign is approved; its content can no longer change.',
      ),
    )
    const wrapper = mountEditor()
    await flushPromises()

    await typeSms(wrapper, 'Başka')
    await vi.advanceTimersByTimeAsync(SAVE_DEBOUNCE_MS)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('can no longer change')
  })

  it('saves a pending edit when the editor is removed before the pause ends', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const update = vi.spyOn(api, 'updateContent').mockResolvedValue(campaign())
    const wrapper = mountEditor()
    await flushPromises()

    await typeSms(wrapper, 'Son dakika')
    wrapper.unmount()

    expect(update).toHaveBeenCalledOnce()
  })
})

describe('ContentEditor variables and preview', () => {
  it('inserts a variable at the cursor of the field that was last used', async () => {
    const wrapper = mountEditor(campaign({}, 'Merhaba !'))
    await flushPromises()
    const textarea = wrapper.find('textarea')
    await textarea.trigger('focus')
    ;(textarea.element as HTMLTextAreaElement).setSelectionRange(8, 8)

    const insert = wrapper.findAll('button').find((button) => button.text() === '{{first_name}}')
    await insert!.trigger('click')

    expect((textarea.element as HTMLTextAreaElement).value).toBe('Merhaba {{first_name}}!')
  })

  it('previews as the chosen sample customer', async () => {
    const wrapper = mountEditor()
    await flushPromises()
    const preview = vi.mocked(api.previewCampaign)
    preview.mockClear()

    await wrapper.find('select').setValue('5')
    await flushPromises()

    expect(preview).toHaveBeenCalledWith(7, 5, expect.any(AbortSignal))
    expect(wrapper.text()).toContain('Merhaba Ece!')
    expect(wrapper.text()).toContain('12 characters')
  })

  it('locks every field and hides the insert buttons once approved', async () => {
    const wrapper = mountEditor(campaign({ status: 'approved' }))
    await flushPromises()

    expect(wrapper.find('textarea').attributes('readonly')).toBeDefined()
    expect(wrapper.find('input[type="text"]').attributes('readonly')).toBeDefined()
    expect(wrapper.text()).toContain('content is locked')
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.emitted('can-approve')?.at(-1)).toEqual([false])
  })
})
