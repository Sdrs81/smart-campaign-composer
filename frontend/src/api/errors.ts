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

  /** Path-first problems the model could not fix (`details.errors` as a plain list, e.g. a failed extraction). */
  get problems(): string[] {
    const errors = this.details.errors
    return Array.isArray(errors) ? errors.filter((item) => typeof item === 'string') : []
  }

  /** Parts of the brief no field can express, reported alongside a failed extraction. */
  get unsupportedCriteria(): string[] {
    const criteria = this.details.unsupported_criteria
    return Array.isArray(criteria) ? criteria.filter((item) => typeof item === 'string') : []
  }
}

/** Anything thrown by a call (a network failure included) as an ApiError the UI can show. */
export function toApiError(failure: unknown): ApiError {
  if (failure instanceof ApiError) return failure
  const reason = failure instanceof Error ? failure.message : String(failure)
  return new ApiError(0, 'network_error', `Could not reach the server: ${reason}`)
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
