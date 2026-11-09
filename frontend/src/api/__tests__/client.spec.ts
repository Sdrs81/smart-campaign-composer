import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, api } from '../client'

function respond(status: number, payload: unknown): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

afterEach(() => vi.unstubAllGlobals())

describe('api client', () => {
  it('unwraps the data envelope', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(201, { data: { id: 7, brief: 'b' } })))

    await expect(api.createCampaign('b')).resolves.toEqual({ id: 7, brief: 'b' })
  })

  it('turns a 422 into field errors by path', async () => {
    const payload = {
      error: {
        code: 'validation_failed',
        message: 'The sms.body field is too long.',
        details: { errors: { 'sms.body': ['SMS body is 161 characters; the limit is 160.'] } },
      },
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(422, payload)))

    const failure = await api
      .updateContent(1, { sms: { body: 'x' }, email: { subject: 's', body: 'b' } })
      .catch((error: unknown) => error)

    expect(failure).toBeInstanceOf(ApiError)
    expect(failure).toMatchObject({ status: 422, code: 'validation_failed' })
    expect((failure as ApiError).fieldErrors).toEqual({
      'sms.body': ['SMS body is 161 characters; the limit is 160.'],
    })
  })

  it('has no field errors for a non-422 error', async () => {
    const payload = { error: { code: 'not_found', message: 'Campaign not found.', details: {} } }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(404, payload)))

    const failure = (await api.getCampaign(9).catch((error: unknown) => error)) as ApiError

    expect(failure.status).toBe(404)
    expect(failure.fieldErrors).toEqual({})
  })

  it('falls back to the status line when the body is not the error shape', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('<html>', { status: 502, statusText: 'Bad Gateway' })),
    )

    await expect(api.getCampaign(1)).rejects.toMatchObject({
      status: 502,
      code: 'http_error',
      message: '502 Bad Gateway',
    })
  })

  it('maps content stream events to typed events', async () => {
    const text =
      'event: delta\ndata: {"text":"{\\"sms\\""}\n\n' +
      'event: final\ndata: {"sms":{"body":"a"},"email":{"subject":"s","body":"b"},"repaired":true}\n\n'
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(text)))
    const seen: unknown[] = []

    await api.streamContent(1, 'warm', (event) => seen.push(event))

    expect(seen).toEqual([
      { type: 'delta', text: '{"sms"' },
      { type: 'final', sms: { body: 'a' }, email: { subject: 's', body: 'b' }, repaired: true },
    ])
  })
})
