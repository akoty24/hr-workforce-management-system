export interface Tenant {
  name: string
  slug: string
}

export type UserRole = 'admin' | 'employee'

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
  user_id: string
}

export interface LeaveType {
  id: string
  name: string
  days_per_year: number | null
  is_active: boolean
}

export interface LeaveTypeInput {
  name: string
  days_per_year: number | null
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

export interface LeaveRequestInput {
  employee_id?: string
  leave_type_id: string
  start_date: string
  end_date: string
  reason: string
}

export interface LeaveBalance {
  leave_type: { id: string; name: string }
  allowance: number | null
  approved: number
  pending: number
  remaining: number | null
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

export type Dashboard =
  | {
      role: 'admin'
      stats: {
        employees_total: number
        employees_active: number
        departments: number
        pending_requests: number
        on_leave_today: number
      }
      pending_requests: LeaveRequest[]
      on_leave_today: LeaveRequest[]
    }
  | {
      role: 'employee'
      employee: { id: string; full_name: string; job_title: string | null; department: string | null } | null
      balances: LeaveBalance[]
      recent_requests: LeaveRequest[]
    }
