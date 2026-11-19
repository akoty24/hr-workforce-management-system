import type {
  AttendanceRecord,
  AttendanceToday,
  CompanySettings,
  EmployeeDocument,
  PayrollRun,
  PayrollRunDetail,
  Payslip,
  PayslipItem,
  Report,
  ReportType,
  SalaryEntry,
  SalaryHistory,
  Dashboard,
  Department,
  DepartmentInput,
  Employee,
  EmployeeInput,
  Holiday,
  HolidayInput,
  LeaveBalance,
  LeaveRequest,
  LeaveRequestInput,
  LeaveStatus,
  LeaveType,
  LeaveTypeInput,
  Paginated,
  PlatformOwner,
  PlatformTenant,
  PlatformTenantInput,
  ShortLeave,
  ShortLeaveInput,
  Tenant,
  User,
  UserInput,
} from './types'
import { lang } from './i18n'

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

/** Notified when any request returns 401 (session ended/expired). */
let onUnauthenticated: (() => void) | null = null
export function setUnauthenticatedHandler(handler: (() => void) | null) {
  onUnauthenticated = handler
}

function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : undefined
}

function query(params: Record<string, string | number | undefined | null>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value))
  }
  const text = search.toString()
  return text ? `?${text}` : ''
}

async function request<T>(method: string, path: string, body?: unknown, retried = false): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    // Server validation messages in the interface language.
    'Accept-Language': lang(),
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  // Laravel's CSRF protection: echo the XSRF-TOKEN cookie back as a header.
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token

  // Paths are relative to /api/v1 unless they name another API (the owner console).
  const platform = path.startsWith('/api/platform')
  const response = await fetch(platform ? path : `/api/v1${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  // 419 = CSRF token expired/missing: refresh the cookie once and retry.
  if (response.status === 419 && !retried) {
    await fetch(platform ? '/api/platform/me' : '/api/v1/tenant', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    return request<T>(method, path, body, true)
  }

  if (response.status === 204) return undefined as T

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    if (response.status === 401 && !platform && !path.startsWith('/auth/')) onUnauthenticated?.()
    throw new ApiError(response.status, data.message ?? `Request failed (${response.status})`, data.errors)
  }

  return data as T
}

const data = <T>(promise: Promise<{ data: T }>) => promise.then((r) => r.data)

/** Multipart upload (files): same CSRF and error handling as request(). */
async function upload<T>(path: string, form: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept-Language': lang() }
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token

  const response = await fetch(`/api/v1${path}`, { method: 'POST', headers, credentials: 'same-origin', body: form })
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    if (response.status === 401) onUnauthenticated?.()
    throw new ApiError(response.status, body.message ?? `Request failed (${response.status})`, body.errors)
  }
  return body as T
}

export const api = {
  tenant: () => data(request<{ data: Tenant }>('GET', '/tenant')),

  /** Owner console (only answers on the platform host, e.g. admin.localhost). */
  platform: {
    me: () => data(request<{ data: PlatformOwner }>('GET', '/api/platform/me')),
    login: (email: string, password: string) =>
      data(request<{ data: PlatformOwner }>('POST', '/api/platform/login', { email, password })),
    logout: () => request<void>('POST', '/api/platform/logout'),
    changePassword: (current_password: string, password: string, password_confirmation: string) =>
      request<void>('PUT', '/api/platform/password', { current_password, password, password_confirmation }),
    tenants: () => data(request<{ data: PlatformTenant[] }>('GET', '/api/platform/tenants')),
    createTenant: (input: PlatformTenantInput) =>
      data(request<{ data: PlatformTenant & { admin_email: string; admin_password: string | null } }>('POST', '/api/platform/tenants', input)),
    updateTenant: (id: string, input: { name?: string; slug?: string; status?: 'active' | 'suspended' }) =>
      data(request<{ data: PlatformTenant }>('PATCH', `/api/platform/tenants/${id}`, input)),
    admins: (tenantId: string) =>
      data(request<{ data: { id: string; name: string; email: string; is_active: boolean }[] }>('GET', `/api/platform/tenants/${tenantId}/admins`)),
    resetAdminPassword: (tenantId: string, userId: string) =>
      data(request<{ data: { password: string | null } }>('PUT', `/api/platform/tenants/${tenantId}/admins/${userId}/password`, {})),
  },

  me: () => data(request<{ data: User }>('GET', '/auth/me')),
  login: (email: string, password: string) => data(request<{ data: User }>('POST', '/auth/login', { email, password })),
  logout: () => request<void>('POST', '/auth/logout'),
  forgotPassword: (email: string) => request<{ message: string }>('POST', '/auth/forgot-password', { email }),
  resetPassword: (input: { token: string; email: string; password: string; password_confirmation: string }) =>
    request<{ message: string }>('POST', '/auth/reset-password', input),
  changePassword: (current_password: string, password: string, password_confirmation: string) =>
    request<void>('PUT', '/account/password', { current_password, password, password_confirmation }),

  dashboard: () => data(request<{ data: Dashboard }>('GET', '/dashboard')),

  departments: {
    list: () => data(request<{ data: Department[] }>('GET', '/departments')),
    create: (input: DepartmentInput) => data(request<{ data: Department }>('POST', '/departments', input)),
    update: (id: string, input: DepartmentInput) => data(request<{ data: Department }>('PUT', `/departments/${id}`, input)),
    remove: (id: string) => request<void>('DELETE', `/departments/${id}`),
  },

  employees: {
    list: (params: { search?: string; department_id?: string; status?: string; page?: number; per_page?: number }) =>
      request<Paginated<Employee>>('GET', `/employees${query(params)}`),
    create: (input: EmployeeInput) => data(request<{ data: Employee }>('POST', '/employees', input)),
    update: (id: string, input: EmployeeInput) => data(request<{ data: Employee }>('PUT', `/employees/${id}`, input)),
    remove: (id: string) => request<void>('DELETE', `/employees/${id}`),
  },

  users: {
    list: () => data(request<{ data: User[] }>('GET', '/users')),
    create: (input: UserInput) => data(request<{ data: User }>('POST', '/users', input)),
    update: (id: string, input: UserInput) => data(request<{ data: User }>('PUT', `/users/${id}`, input)),
  },

  attendance: {
    today: () => data(request<{ data: AttendanceToday }>('GET', '/attendance/today')),
    checkIn: (location: { latitude?: number; longitude?: number; accuracy?: number }) =>
      data(request<{ data: AttendanceRecord }>('POST', '/attendance/check-in', location)),
    checkOut: (location: { latitude?: number; longitude?: number; accuracy?: number }) =>
      data(request<{ data: AttendanceRecord }>('POST', '/attendance/check-out', location)),
    list: (params: { from?: string; to?: string; employee_id?: string; scope?: 'mine' | 'team' }) =>
      data(request<{ data: AttendanceRecord[] }>('GET', `/attendance${query(params)}`)),
    create: (input: { employee_id: string; date: string; check_in: string; check_out: string | null; note: string | null }) =>
      data(request<{ data: AttendanceRecord }>('POST', '/attendance', input)),
    update: (id: string, input: { check_in: string; check_out: string | null; note: string | null }) =>
      data(request<{ data: AttendanceRecord }>('PUT', `/attendance/${id}`, input)),
  },

  salaries: {
    list: (employeeId: string) => data(request<{ data: SalaryHistory }>('GET', `/employees/${employeeId}/salaries`)),
    create: (employeeId: string, input: { amount: number; currency: string; effective_from: string; note: string | null }) =>
      data(request<{ data: SalaryEntry }>('POST', `/employees/${employeeId}/salaries`, input)),
    remove: (id: string) => request<void>('DELETE', `/salaries/${id}`),
  },

  settings: {
    get: () => data(request<{ data: CompanySettings }>('GET', '/settings')),
    update: (input: CompanySettings) => data(request<{ data: CompanySettings }>('PUT', '/settings', input)),
  },

  payroll: {
    list: () => data(request<{ data: PayrollRun[] }>('GET', '/payroll-runs')),
    create: (year: number, month: number) => data(request<{ data: PayrollRunDetail }>('POST', '/payroll-runs', { year, month })),
    get: (id: string) => data(request<{ data: PayrollRunDetail }>('GET', `/payroll-runs/${id}`)),
    recalculate: (id: string) => data(request<{ data: PayrollRunDetail }>('POST', `/payroll-runs/${id}/recalculate`)),
    finalize: (id: string) => data(request<{ data: PayrollRunDetail }>('POST', `/payroll-runs/${id}/finalize`)),
    remove: (id: string) => request<void>('DELETE', `/payroll-runs/${id}`),
    updatePayslip: (id: string, items: PayslipItem[]) => data(request<{ data: Payslip }>('PUT', `/payslips/${id}`, { items })),
    exportUrl: (id: string) => `/api/v1/payroll-runs/${id}/export`,
    mine: () => data(request<{ data: Payslip[] }>('GET', '/my/payslips')),
  },

  documents: {
    list: (employeeId: string) => data(request<{ data: EmployeeDocument[] }>('GET', `/employees/${employeeId}/documents`)),
    upload: (employeeId: string, form: FormData) =>
      data(upload<{ data: EmployeeDocument }>(`/employees/${employeeId}/documents`, form)),
    remove: (id: string) => request<void>('DELETE', `/documents/${id}`),
    expiring: () => data(request<{ data: EmployeeDocument[] }>('GET', '/documents/expiring')),
    downloadUrl: (id: string) => `/api/v1/documents/${id}/download`,
  },

  reports: {
    get: (type: ReportType, params: { from: string; to: string; department_id?: string }) =>
      data(request<{ data: Report }>('GET', `/reports/${type}${query(params)}`)),
    /** Same-origin link; the session cookie authenticates the download. */
    downloadUrl: (type: ReportType, params: { from: string; to: string; department_id?: string }) =>
      `/api/v1/reports/${type}${query({ ...params, format: 'xlsx' })}`,
  },

  holidays: {
    list: (year?: number) => data(request<{ data: Holiday[] }>('GET', `/holidays${query({ year })}`)),
    create: (input: HolidayInput) => data(request<{ data: Holiday }>('POST', '/holidays', input)),
    update: (id: string, input: HolidayInput) => data(request<{ data: Holiday }>('PUT', `/holidays/${id}`, input)),
    remove: (id: string) => request<void>('DELETE', `/holidays/${id}`),
  },

  leaveTypes: {
    list: () => data(request<{ data: LeaveType[] }>('GET', '/leave-types')),
    create: (input: LeaveTypeInput) => data(request<{ data: LeaveType }>('POST', '/leave-types', input)),
    update: (id: string, input: LeaveTypeInput) => data(request<{ data: LeaveType }>('PUT', `/leave-types/${id}`, input)),
    remove: (id: string) => request<void>('DELETE', `/leave-types/${id}`),
  },

  leaveRequests: {
    list: (params: { status?: LeaveStatus | ''; employee_id?: string; scope?: 'mine' | 'team' }) =>
      data(request<{ data: LeaveRequest[] }>('GET', `/leave-requests${query(params)}`)),
    create: (input: LeaveRequestInput) => data(request<{ data: LeaveRequest }>('POST', '/leave-requests', input)),
    approve: (id: string, note?: string) => data(request<{ data: LeaveRequest }>('POST', `/leave-requests/${id}/approve`, { note })),
    reject: (id: string, note?: string) => data(request<{ data: LeaveRequest }>('POST', `/leave-requests/${id}/reject`, { note })),
    cancel: (id: string) => data(request<{ data: LeaveRequest }>('POST', `/leave-requests/${id}/cancel`)),
  },

  shortLeaves: {
    list: (params: { status?: LeaveStatus | ''; employee_id?: string; scope?: 'mine' | 'team' }) =>
      data(request<{ data: ShortLeave[] }>('GET', `/short-leaves${query(params)}`)),
    create: (input: ShortLeaveInput) => data(request<{ data: ShortLeave }>('POST', '/short-leaves', input)),
    approve: (id: string, note?: string) => data(request<{ data: ShortLeave }>('POST', `/short-leaves/${id}/approve`, { note })),
    reject: (id: string, note?: string) => data(request<{ data: ShortLeave }>('POST', `/short-leaves/${id}/reject`, { note })),
    cancel: (id: string) => data(request<{ data: ShortLeave }>('POST', `/short-leaves/${id}/cancel`)),
  },

  leaveBalances: (employee_id?: string) =>
    data(request<{ data: LeaveBalance[] }>('GET', `/leave-balances${query({ employee_id })}`)),

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

/** Human-readable message for any thrown value. */
export function errorMessage(e: unknown): string {
  return e instanceof ApiError ? e.message : 'Cannot reach the server.'
}
