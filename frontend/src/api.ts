import type { Department, DepartmentInput, Tenant, User } from './types'

/** Error returned by the API, with Laravel validation errors when present. */
export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : undefined
}

async function request<T>(method: string, path: string, body?: unknown, retried = false): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  // Laravel's CSRF protection: echo the XSRF-TOKEN cookie back as a header.
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token

  const response = await fetch(`/api/v1${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  // 419 = CSRF token expired/missing: refresh the cookie once and retry.
  if (response.status === 419 && !retried) {
    await fetch('/api/v1/tenant', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    return request<T>(method, path, body, true)
  }

  if (response.status === 204) return undefined as T

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(response.status, data.message ?? `Request failed (${response.status})`, data.errors)
  }

  return data as T
}

export const api = {
  tenant: () => request<{ data: Tenant }>('GET', '/tenant').then((r) => r.data),

  me: () => request<{ data: User }>('GET', '/auth/me').then((r) => r.data),
  login: (email: string, password: string) =>
    request<{ data: User }>('POST', '/auth/login', { email, password }).then((r) => r.data),
  logout: () => request<void>('POST', '/auth/logout'),

  departments: {
    list: () => request<{ data: Department[] }>('GET', '/departments').then((r) => r.data),
    create: (input: DepartmentInput) =>
      request<{ data: Department }>('POST', '/departments', input).then((r) => r.data),
    update: (id: string, input: DepartmentInput) =>
      request<{ data: Department }>('PUT', `/departments/${id}`, input).then((r) => r.data),
    remove: (id: string) => request<void>('DELETE', `/departments/${id}`),
  },

  /** Readiness probe (outside /api). */
  health: async (): Promise<boolean> => {
    try {
      const response = await fetch('/health/ready', { headers: { Accept: 'application/json' } })
      return response.ok
    } catch {
      return false
    }
  },
}
