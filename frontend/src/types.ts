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
}

export interface Department {
  id: string
  name: string
  description: string | null
  created_at: string | null
  updated_at: string | null
}

export interface DepartmentInput {
  name: string
  description: string
}
