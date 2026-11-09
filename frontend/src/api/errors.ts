import type { FieldErrors } from './types'

/** The backend's single error shape: `{error: {code, message, details}}`. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Field path to messages for a 422; empty for any other error. */
  get fieldErrors(): FieldErrors {
    const errors = this.details.errors
    if (this.status !== 422 || typeof errors !== 'object' || errors === null) return {}

    const fields: FieldErrors = {}
    for (const [field, messages] of Object.entries(errors)) {
      if (Array.isArray(messages)) fields[field] = messages.map(String)
    }
    return fields
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export async function errorFromResponse(response: Response): Promise<ApiError> {
  const fallback = `${response.status} ${response.statusText}`.trim()
  let payload: unknown
  try {
    payload = await response.json()
  } catch {
    // A proxy error page or an empty body: nothing structured to read, so report the status line.
    return new ApiError(response.status, 'http_error', fallback)
  }

  const error = isRecord(payload) ? payload.error : undefined
  if (!isRecord(error)) return new ApiError(response.status, 'http_error', fallback)

  return new ApiError(
    response.status,
    typeof error.code === 'string' ? error.code : 'http_error',
    typeof error.message === 'string' ? error.message : fallback,
    isRecord(error.details) ? error.details : {},
  )
}
