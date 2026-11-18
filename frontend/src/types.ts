export interface Tenant {
  name: string
  slug: string
}

export type UserRole = 'admin' | 'hr' | 'team_leader' | 'employee'

export const ROLE_LABELS: Record<UserRole, string> = {
  admin: 'Admin',
  hr: 'HR',
  team_leader: 'Team leader',
  employee: 'Employee',
}

/** Admins and HR manage company HR data and decide every request. */
export function managesHr(user: { role: UserRole }): boolean {
  return user.role === 'admin' || user.role === 'hr'
}

export interface User {
  id: string
  name: string
  email: string
  role: UserRole
  is_active: boolean
  created_at?: string | null
  employee?: { id: string; full_name: string } | null
}

export interface UserInput {
  name: string
  email: string
  role: UserRole
  is_active: boolean
  password?: string
}

export interface Department {
  id: string
  name: string
  description: string | null
  employees_count?: number
  created_at: string | null
  updated_at: string | null
}

export interface DepartmentInput {
  name: string
  description: string
}

export type EmployeeStatus = 'active' | 'on_leave' | 'terminated'

export interface Employee {
  id: string
  employee_code: string
  first_name: string
  last_name: string
  full_name: string
  email: string | null
  phone: string | null
  job_title: string | null
  hire_date: string | null
  status: EmployeeStatus
  department: { id: string; name: string } | null
  manager: { id: string; full_name: string } | null
  user: { id: string; email: string } | null
}

export interface EmployeeInput {
  employee_code: string
  first_name: string
  last_name: string
  email: string
  phone: string
  job_title: string
  hire_date: string
  status: EmployeeStatus
  department_id: string
  manager_id: string
  user_id: string
}

export interface LeaveType {
  id: string
  name: string
  days_per_year: number | null
  prorate_first_year: boolean
  is_active: boolean
}

export interface LeaveTypeInput {
  name: string
  days_per_year: number | null
  prorate_first_year: boolean
  is_active: boolean
}

export type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'

export interface LeaveRequest {
  id: string
  employee: { id: string; full_name: string; employee_code: string }
  leave_type: { id: string; name: string }
  start_date: string
  end_date: string
  days: number
  reason: string | null
  status: LeaveStatus
  decided_by?: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

/** Hourly permission (part of one working day). Times are HH:MM. */
export interface ShortLeave {
  id: string
  employee: { id: string; full_name: string; employee_code: string }
  date: string
  start_time: string
  end_time: string
  minutes: number
  reason: string | null
  status: LeaveStatus
  decided_by?: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

export interface ShortLeaveInput {
  employee_id?: string
  date: string
  start_time: string
  end_time: string
  reason: string
}

export interface LeaveRequestInput {
  employee_id?: string
  leave_type_id: string
  start_date: string
  end_date: string
  reason: string
}

export interface LeaveBalance {
  leave_type: { id: string; name: string }
  /** Entitlement this year (prorated in the hire year when the type says so). */
  allowance: number | null
  /** The type's full yearly allowance. */
  full_allowance: number | null
  approved: number
  pending: number
  remaining: number | null
}

export interface Holiday {
  id: string
  name: string
  start_date: string
  end_date: string
  days: number
}

export interface HolidayInput {
  name: string
  start_date: string
  end_date: string
}

export type UpcomingHoliday = Pick<Holiday, 'name' | 'start_date' | 'end_date'>

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

export type Dashboard =
  | {
      role: 'overview'
      stats: {
        employees_total: number
        employees_active: number
        departments: number
        pending_requests: number
        pending_permissions: number
        on_leave_today: number
      }
      pending_requests: LeaveRequest[]
      on_leave_today: LeaveRequest[]
      upcoming_holidays: UpcomingHoliday[]
    }
  | {
      role: 'employee' | 'team_leader'
      employee: {
        id: string
        full_name: string
        job_title: string | null
        department: string | null
        manager: string | null
      } | null
      balances: LeaveBalance[]
      recent_requests: LeaveRequest[]
      team?: { size: number; pending_requests: LeaveRequest[]; on_leave_today: LeaveRequest[]; pending_permissions: number }
      upcoming_holidays: UpcomingHoliday[]
    }

export interface GeoPoint {
  latitude: number
  longitude: number
  accuracy: number | null
}

export interface AttendanceRecord {
  id: string
  employee: { id: string; full_name: string; employee_code: string } | null
  date: string
  /** Company-local HH:MM */
  check_in: string | null
  check_out: string | null
  check_in_location: GeoPoint | null
  check_out_location: GeoPoint | null
  late_minutes: number
  worked_minutes: number | null
  note: string | null
  edited_by: string | null
}

export interface CompanySettings {
  timezone: string
  work_start: string
  work_end: string
  grace_minutes: number
  require_location: boolean
}

export interface AttendanceToday {
  date: string
  record: AttendanceRecord | null
  schedule: CompanySettings
}

export interface SalaryEntry {
  id: string
  amount: number
  currency: string
  effective_from: string
  note: string | null
  created_by: string | null
}

export interface SalaryHistory {
  current: SalaryEntry | null
  history: SalaryEntry[]
}

export type ReportType = 'summary' | 'attendance' | 'leave' | 'permissions' | 'salaries'

export interface Report {
  title: string
  headers: string[]
  rows: (string | number | null)[][]
}
