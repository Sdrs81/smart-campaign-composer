import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { api } from '../../api/client'
import type { Campaign, EditableContent } from '../../api/types'
import { SAVE_DEBOUNCE_MS, useContentEditing } from '../useContentEditing'

const EDIT: EditableContent = { sms: { body: 'Yeni' }, email: { subject: 'Konu', body: 'Gövde' } }
const SAVED = { id: 7, status: 'content_ready' } as Campaign

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.spyOn(api, 'previewCampaign').mockResolvedValue({ customer: { id: null }, messages: [] })
})
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('useContentEditing', () => {
  it('still saves the last edit of a closed editor but keeps its answer from the page', async () => {
    // Regression: the answer of that save used to overwrite the page's campaign, which by then could be
    // another campaign or newly generated text the closed editor never saw.
    let answer: (campaign: Campaign) => void = () => undefined
    const update = vi
      .spyOn(api, 'updateContent')
      .mockImplementation(() => new Promise<Campaign>((resolve) => (answer = resolve)))
    const onSaved = vi.fn<(campaign: Campaign) => void>()
    const scope = effectScope()
    const editing = scope.run(() => useContentEditing(() => 7, onSaved))!

    editing.edit(EDIT)
    scope.stop()
    answer(SAVED)
    await vi.advanceTimersByTimeAsync(SAVE_DEBOUNCE_MS)

    expect(update).toHaveBeenCalledExactlyOnceWith(7, EDIT)
    expect(onSaved).not.toHaveBeenCalled()
  })

  it('tells the page about a save while the editor is open', async () => {
    vi.spyOn(api, 'updateContent').mockResolvedValue(SAVED)
    const onSaved = vi.fn<(campaign: Campaign) => void>()
    const scope = effectScope()
    const editing = scope.run(() => useContentEditing(() => 7, onSaved))!

    editing.edit(EDIT)
    await vi.advanceTimersByTimeAsync(SAVE_DEBOUNCE_MS)

    expect(onSaved).toHaveBeenCalledExactlyOnceWith(SAVED)
    scope.stop()
  })
})
