import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { createPinia } from 'pinia'

import App from '../../App.vue'
import {
  DELIVERY_ERROR_CODES,
  type CampaignStats,
  type CampaignStatus,
  type DeliveryError,
} from '../../api/types'
import ChannelProgress from '../../components/ChannelProgress.vue'
import { setLocale } from '../../i18n'
import { en } from '../../i18n/en'
import { tr } from '../../i18n/tr'
import { routes } from '../../router'
import CampaignDashboard from '../CampaignDashboard.vue'

class FakeEventSource {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSED = 2
  static instances: FakeEventSource[] = []
  readyState = FakeEventSource.CONNECTING
  closed = false
  onopen: (() => void) | null = null
  onerror: (() => void) | null = null
  private listeners = new Map<string, ((event: MessageEvent<string>) => void)[]>()

  constructor(readonly url: string) {
    FakeEventSource.instances.push(this)
  }

  addEventListener(name: string, listener: (event: MessageEvent<string>) => void): void {
    this.listeners.set(name, [...(this.listeners.get(name) ?? []), listener])
  }

  close(): void {
    this.closed = true
  }

  open(): void {
    this.readyState = FakeEventSource.OPEN
    this.onopen?.()
  }

  refuse(): void {
    this.readyState = FakeEventSource.CLOSED
    this.onerror?.()
  }

  drop(): void {
    this.readyState = FakeEventSource.CONNECTING
    this.onerror?.()
  }

  emit(name: string, data: string): void {
    for (const listener of this.listeners.get(name) ?? [])
      listener({ data } as MessageEvent<string>)
  }
}

function snapshot(
  status: CampaignStatus,
  sms: { sent: number; failed?: number; pending?: number },
  extra: Partial<CampaignStats> = {},
): CampaignStats {
  const channel = (counts: { sent: number; failed?: number; pending?: number }) => ({
    pending: counts.pending ?? 0,
    sending: 0,
    sent: counts.sent,
    failed: counts.failed ?? 0,
    skipped: 0,
    total: counts.sent + (counts.failed ?? 0) + (counts.pending ?? 0),
  })
  return {
    campaign_id: 7,
    status,
    channels: { sms: channel(sms), email: channel({ sent: 5 }) },
    send_rate_per_second: 12.34,
    total_retries: 3,
    recent_errors: [],
    ...extra,
  }
}

async function mountDashboard() {
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push('/campaigns/7/dashboard')
  await router.isReady()
  const wrapper = mount(CampaignDashboard, { props: { id: 7 }, global: { plugins: [router] } })
  const source = FakeEventSource.instances[0]!
  return { wrapper, source }
}

function dashboardError(overrides: Partial<DeliveryError> = {}): DeliveryError {
  return {
    delivery_id: 9,
    channel: 'sms',
    status: 'failed',
    attempts: 1,
    code: 'internal',
    error: 'raw error',
    at: '2026-01-01T10:00:00Z',
    ...overrides,
  }
}

function emitErrors(source: FakeEventSource, errors: DeliveryError[]): void {
  source.emit('stats', JSON.stringify(snapshot('sending', { sent: 1 }, { recent_errors: errors })))
}

const widthOf = (wrapper: Awaited<ReturnType<typeof mountDashboard>>['wrapper'], segment: string) =>
  Number(wrapper.find(`[data-segment="${segment}"]`).attributes('width'))

beforeEach(() => {
  FakeEventSource.instances = []
  vi.stubGlobal('EventSource', FakeEventSource)
})
afterEach(() => vi.unstubAllGlobals())

describe('CampaignDashboard', () => {
  it('listens to the stats stream of its campaign and waits for the first snapshot', async () => {
    const { wrapper, source } = await mountDashboard()

    expect(source.url).toBe('/api/campaigns/7/stats/stream')
    expect(wrapper.text()).toContain('Connecting…')
    expect(wrapper.text()).toContain('Waiting for the first update')
  })

  it('shows live numbers that grow with each snapshot', async () => {
    const { wrapper, source } = await mountDashboard()
    source.open()

    source.emit('stats', JSON.stringify(snapshot('sending', { sent: 20, pending: 80 })))
    await nextTick()
    const sms = wrapper.findAllComponents(ChannelProgress)[0]!
    expect(sms.find('h3').text()).toBe('SMS')
    expect(sms.findAll('dd').map((value) => value.text())).toEqual(['20', '0', '0', '80'])
    expect(widthOf(wrapper, 'sent')).toBe(20)
    expect(wrapper.text()).toContain('Live')
    expect(wrapper.text()).toContain('Sending')
    expect(wrapper.text()).toContain('12.3')

    source.emit('stats', JSON.stringify(snapshot('sending', { sent: 70, failed: 5, pending: 25 })))
    await nextTick()
    expect(sms.findAll('dd').map((value) => value.text())).toEqual(['70', '5', '0', '25'])
    expect(widthOf(wrapper, 'sent')).toBe(70)
    expect(widthOf(wrapper, 'failed')).toBe(5)
  })

  it('lists recent errors in a table', async () => {
    const { wrapper, source } = await mountDashboard()

    source.emit(
      'stats',
      JSON.stringify(
        snapshot(
          'sending',
          { sent: 1 },
          {
            recent_errors: [
              {
                delivery_id: 9,
                channel: 'sms',
                status: 'failed',
                attempts: 3,
                code: 'provider_temporary',
                error: 'provider 503',
                at: '2026-01-01T10:00:00Z',
              },
            ],
          },
        ),
      ),
    )
    await nextTick()

    const cells = wrapper.findAll('tbody tr td').map((cell) => cell.text())
    expect(cells).toContain('3')
    expect(cells.at(-1)).toContain('The delivery service had a temporary problem')
  })

  it('keeps the raw error behind a collapsed "Technical detail" and explains it in plain words', async () => {
    const { wrapper, source } = await mountDashboard()
    emitErrors(source, [
      dashboardError({ code: 'provider_rejected', error: 'Provider answered 400: bad' }),
    ])
    await nextTick()

    const details = wrapper.find('details')
    expect(details.attributes('open')).toBeUndefined()
    expect(details.find('summary').text()).toBe('Technical detail')
    expect(details.text()).toContain('Provider answered 400: bad')
    // The visible sentence is the explanation, not the provider's text.
    expect(wrapper.find('tbody td:last-child p').text()).toContain('refused this message')
    expect(wrapper.find('tbody td:last-child p').text()).not.toContain('Provider answered')
  })

  it('has a plain sentence for every error code in both languages', async () => {
    for (const code of DELIVERY_ERROR_CODES) {
      expect(en.deliveryError[code], `${code} in English`).toBeTruthy()
      expect(tr.deliveryError[code], `${code} in Turkish`).toBeTruthy()
    }

    setLocale('tr')
    const { wrapper, source } = await mountDashboard()
    emitErrors(source, [dashboardError({ code: 'consent_withdrawn' })])
    await nextTick()

    expect(wrapper.find('tbody td:last-child p').text()).toBe(tr.deliveryError.consent_withdrawn)
    expect(wrapper.find('summary').text()).toBe('Teknik ayrıntı')
  })

  it.each([
    ['a missing code', null],
    ['a code this version does not know', 'brand_new_code'],
  ])('falls back to a generic sentence for %s', async (_name, code) => {
    const { wrapper, source } = await mountDashboard()
    emitErrors(source, [dashboardError({ code })])
    await nextTick()

    expect(wrapper.find('tbody td:last-child p').text()).toBe(en.deliveryError.unknown)
  })

  it('shows a reconnect state when the connection drops and goes live again', async () => {
    const { wrapper, source } = await mountDashboard()
    source.open()
    source.emit('stats', JSON.stringify(snapshot('sending', { sent: 1, pending: 9 })))
    await nextTick()

    source.drop()
    await nextTick()
    expect(wrapper.text()).toContain('Reconnecting…')
    expect(source.closed).toBe(false)

    source.open()
    await nextTick()
    expect(wrapper.text()).toContain('Live')
  })

  it('stops at the end event once the campaign is completed', async () => {
    const { wrapper, source } = await mountDashboard()
    source.open()

    source.emit('stats', JSON.stringify(snapshot('completed', { sent: 100 })))
    source.emit('end', '{}')
    await nextTick()

    expect(source.closed).toBe(true)
    expect(wrapper.text()).toContain('Finished')
    expect(wrapper.text()).toContain('Completed')
  })

  it('shows titles, channels, statuses and table headers in Turkish', async () => {
    setLocale('tr')
    const { wrapper, source } = await mountDashboard()
    source.open()

    source.emit(
      'stats',
      JSON.stringify(
        snapshot(
          'sending',
          { sent: 1, pending: 9 },
          {
            recent_errors: [
              {
                delivery_id: 9,
                channel: 'email',
                status: 'failed',
                attempts: 3,
                code: 'retries_exhausted',
                error: 'provider 503',
                at: '2026-01-01T10:00:00Z',
              },
            ],
          },
        ),
      ),
    )
    await nextTick()

    const text = wrapper.text()
    expect(text).toContain('Kampanya #7 panosu')
    expect(text).toContain('Canlı')
    expect(text).toContain('Gönderiliyor')
    expect(text).toContain('Gönderim hızı')
    expect(text).toContain('Yeniden deneme')
    expect(text).toContain('E-posta')
    expect(text).toContain('toplam 10')
    expect(wrapper.findAll('th').map((header) => header.text())).toEqual([
      'Zaman',
      'Kanal',
      'Durum',
      'Deneme',
      'Hata',
    ])
    // The sentence is translated; the server's raw text sits in the collapsed technical detail as received.
    const cells = wrapper.findAll('tbody tr td').map((cell) => cell.text())
    expect(cells.at(-1)).toContain(tr.deliveryError.retries_exhausted)
    expect(wrapper.find('details').text()).toContain('provider 503')
    expect(cells).toContain('Başarısız')
    expect(text).not.toContain('Recent errors')
  })

  it('skips a frame that is not a snapshot and keeps the last good one', async () => {
    const { wrapper, source } = await mountDashboard()
    source.open()
    source.emit('stats', JSON.stringify(snapshot('sending', { sent: 20, pending: 80 })))
    await nextTick()

    source.emit('stats', '{"campaign_id": 7, "stat') // cut off
    source.emit('stats', '<html>502 Bad Gateway</html>')
    source.emit('stats', JSON.stringify({ status: 'sending' })) // valid JSON, not a snapshot
    await nextTick()

    const sms = wrapper.findAllComponents(ChannelProgress)[0]!
    expect(sms.findAll('dd').map((value) => value.text())).toEqual(['20', '0', '0', '80'])
    expect(source.closed).toBe(false)
  })

  it('says so when the browser has given up on the stream', async () => {
    const { wrapper, source } = await mountDashboard()

    source.refuse()
    await nextTick()

    expect(wrapper.text()).toContain('Connection lost')
    expect(wrapper.text()).not.toContain('Reconnecting')
  })

  it('opens the stream of the other campaign when the address changes to another id', async () => {
    // Regression: the page was reused for the new id and kept the first campaign's stream and numbers.
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push('/campaigns/1/dashboard')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [createPinia(), router] } })
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1))
    expect(FakeEventSource.instances[0]!.url).toBe('/api/campaigns/1/stats/stream')

    await router.push('/campaigns/2/dashboard')
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(2))

    expect(FakeEventSource.instances[0]!.closed).toBe(true)
    expect(FakeEventSource.instances[1]!.url).toBe('/api/campaigns/2/stats/stream')
    wrapper.unmount()
  })

  it('has one round back button to the list, named in both languages, and no link at the bottom', async () => {
    const { wrapper } = await mountDashboard()
    const links = wrapper.findAll('a')

    expect(links).toHaveLength(1)
    expect(links[0]!.attributes('href')).toBe('/campaigns')
    expect(links[0]!.attributes('aria-label')).toBe('Back to campaigns')
    expect(wrapper.find('section > div a').exists()).toBe(true)

    setLocale('tr')
    await nextTick()
    expect(wrapper.find('a').attributes('aria-label')).toBe('Kampanyalara dön')
    setLocale('en')
  })

  it('closes the stream when the page is left', async () => {
    const { wrapper, source } = await mountDashboard()

    wrapper.unmount()

    expect(source.closed).toBe(true)
  })
})
