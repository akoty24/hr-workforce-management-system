import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { Department, Employee, EmployeeInput, EmployeeStatus, Paginated, User } from '../types'
import { ErrorText, FieldError, Modal, PageHead, StatusBadge } from '../components/ui'
import { formatDate } from '../format'

const STATUSES: { value: EmployeeStatus; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'on_leave', label: 'On leave' },
  { value: 'terminated', label: 'Terminated' },
]

export function EmployeesPage({ user }: { user: User }) {
  // Team leaders get a read-only view of their direct reports (filtered server-side).
  const isAdmin = user.role === 'admin'
  const [result, setResult] = useState<Paginated<Employee> | null>(null)
  const [departments, setDepartments] = useState<Department[]>([])
  const [users, setUsers] = useState<User[]>([])
  const [search, setSearch] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<Employee | 'new' | null>(null)

  const load = useCallback(async () => {
    try {
      setResult(await api.employees.list({ search, department_id: departmentId, status, page }))
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [search, departmentId, status, page])

  // Debounced reload when filters change.
  useEffect(() => {
    const timer = window.setTimeout(load, 250)
    return () => window.clearTimeout(timer)
  }, [load])

  useEffect(() => {
    api.departments.list().then(setDepartments).catch(() => {})
    if (isAdmin) api.users.list().then(setUsers).catch(() => {})
  }, [isAdmin])

  async function remove(employee: Employee) {
    if (!window.confirm(`Delete ${employee.full_name}? This cannot be undone.`)) return
    try {
      await api.employees.remove(employee.id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  const filterChange = (setter: (v: string) => void) => (value: string) => {
    setter(value)
    setPage(1)
  }

  return (
    <section>
      <PageHead
        title={isAdmin ? 'Employees' : 'My team'}
        subtitle={isAdmin ? 'Personal and job records of everyone in your company.' : 'People who report directly to you.'}
        actions={isAdmin && <button onClick={() => setEditing('new')}>+ New employee</button>}
      />

      <div className="filters">
        <input
          type="search"
          placeholder="Search name, code, email, title…"
          value={search}
          onChange={(e) => filterChange(setSearch)(e.target.value)}
        />
        <select value={departmentId} onChange={(e) => filterChange(setDepartmentId)(e.target.value)}>
          <option value="">All departments</option>
          {departments.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </select>
        <select value={status} onChange={(e) => filterChange(setStatus)(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
      </div>

      <ErrorText message={error} />

      {!result && !error && <p className="muted">Loading…</p>}

      {result && result.data.length === 0 && (
        <div className="card empty">
          <p>No employees found.</p>
        </div>
      )}

      {result && result.data.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                {isAdmin && <th>Reports to</th>}
                <th>Hired</th>
                <th>Status</th>
                {isAdmin && <th className="actions-col" aria-label="Actions" />}
              </tr>
            </thead>
            <tbody>
              {result.data.map((e) => (
                <tr key={e.id}>
                  <td>
                    <div className="strong">{e.full_name}</div>
                    <div className="muted small">
                      {e.employee_code}
                      {e.job_title && ` · ${e.job_title}`}
                      {e.user && ' · 🔑'}
                    </div>
                  </td>
                  <td>{e.department?.name ?? <span className="muted">—</span>}</td>
                  {isAdmin && <td>{e.manager?.full_name ?? <span className="muted">—</span>}</td>}
                  <td>{formatDate(e.hire_date)}</td>
                  <td>
                    <StatusBadge status={e.status} />
                  </td>
                  {isAdmin && (
                    <td className="actions">
                      <button className="link" onClick={() => setEditing(e)}>
                        Edit
                      </button>
                      <button className="link danger" onClick={() => remove(e)}>
                        Delete
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          <div className="table-foot muted small">
            <span>
              {result.meta.total} employee{result.meta.total === 1 ? '' : 's'}
            </span>
            {result.meta.last_page > 1 && (
              <span className="pager">
                <button className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                  ‹ Prev
                </button>
                Page {result.meta.current_page} of {result.meta.last_page}
                <button className="secondary" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)}>
                  Next ›
                </button>
              </span>
            )}
          </div>
        </div>
      )}

      {editing && (
        <EmployeeForm
          employee={editing === 'new' ? null : editing}
          departments={departments}
          users={users}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
          }}
        />
      )}
    </section>
  )
}

function EmployeeForm({
  employee,
  departments,
  users,
  onClose,
  onSaved,
}: {
  employee: Employee | null
  departments: Department[]
  users: User[]
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState<EmployeeInput>({
    employee_code: employee?.employee_code ?? '',
    first_name: employee?.first_name ?? '',
    last_name: employee?.last_name ?? '',
    email: employee?.email ?? '',
    phone: employee?.phone ?? '',
    job_title: employee?.job_title ?? '',
    hire_date: employee?.hire_date ?? '',
    status: employee?.status ?? 'active',
    department_id: employee?.department?.id ?? '',
    manager_id: employee?.manager?.id ?? '',
    user_id: employee?.user?.id ?? '',
  })
  const [managers, setManagers] = useState<Employee[]>([])

  useEffect(() => {
    api.employees
      .list({ per_page: 200 })
      .then((r) => setManagers(r.data.filter((m) => m.id !== employee?.id && m.status !== 'terminated')))
      .catch(() => {})
  }, [employee?.id])
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const set = (field: keyof EmployeeInput) => (value: string) => setForm((f) => ({ ...f, [field]: value }))

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    try {
      if (employee) await api.employees.update(employee.id, form)
      else await api.employees.create(form)
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  const field = (name: keyof EmployeeInput, label: string, props: Record<string, unknown> = {}) => (
    <label>
      {label}
      <input value={form[name]} onChange={(e) => set(name)(e.target.value)} aria-invalid={!!errors?.field(name)} {...props} />
      <FieldError message={errors?.field(name)} />
    </label>
  )

  return (
    <Modal title={employee ? `Edit ${employee.full_name}` : 'New employee'} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <div className="form-grid">
          {field('first_name', 'First name', { required: true, autoFocus: true, maxLength: 80 })}
          {field('last_name', 'Last name', { required: true, maxLength: 80 })}
          {field('employee_code', 'Employee code', { required: true, maxLength: 30, placeholder: 'e.g. EMP-010' })}
          {field('job_title', 'Job title', { maxLength: 120 })}
          {field('email', 'Email', { type: 'email' })}
          {field('phone', 'Phone', { maxLength: 30 })}
          {field('hire_date', 'Hire date', { type: 'date' })}
          <label>
            Status
            <select value={form.status} onChange={(e) => set('status')(e.target.value)}>
              {STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
            <FieldError message={errors?.field('status')} />
          </label>
          <label>
            Department
            <select value={form.department_id} onChange={(e) => set('department_id')(e.target.value)}>
              <option value="">— None —</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
            <FieldError message={errors?.field('department_id')} />
          </label>
          <label>
            Reports to <span className="muted small">(team leader approves their leave)</span>
            <select value={form.manager_id} onChange={(e) => set('manager_id')(e.target.value)}>
              <option value="">— Nobody —</option>
              {managers.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.full_name}
                  {m.job_title ? ` — ${m.job_title}` : ''}
                </option>
              ))}
            </select>
            <FieldError message={errors?.field('manager_id')} />
          </label>
          <label>
            Sign-in account <span className="muted small">(for leave requests)</span>
            <select value={form.user_id} onChange={(e) => set('user_id')(e.target.value)}>
              <option value="">— None —</option>
              {users.map((u) => (
                <option key={u.id} value={u.id} disabled={!!u.employee && u.employee.id !== employee?.id}>
                  {u.name} ({u.email}){u.employee && u.employee.id !== employee?.id ? ' — linked' : ''}
                </option>
              ))}
            </select>
            <FieldError message={errors?.field('user_id')} />
          </label>
        </div>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </button>
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </button>
        </div>
      </form>
    </Modal>
  )
}
