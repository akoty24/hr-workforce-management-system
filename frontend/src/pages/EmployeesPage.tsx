import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import {
  ROLE_LABELS,
  managesHr,
  type Department,
  type Employee,
  type EmployeeInput,
  type EmployeeStatus,
  type Paginated,
  type User,
  type UserRole,
} from '../types'
import { ErrorText, FieldError, Modal, PageHead, Person, StatusBadge } from '../components/ui'
import { SalaryPanel } from '../components/SalaryPanel'
import { DocumentsPanel } from '../components/DocumentsPanel'
import { formatDate } from '../format'
import { tr } from '../i18n'

const STATUSES: { value: EmployeeStatus; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'on_leave', label: 'On leave' },
  { value: 'terminated', label: 'Terminated' },
]

export function EmployeesPage({ user, tenantSlug }: { user: User; tenantSlug: string }) {
  // Team leaders get a read-only view of their direct reports (filtered server-side).
  const isAdmin = managesHr(user)
  const [result, setResult] = useState<Paginated<Employee> | null>(null)
  const [departments, setDepartments] = useState<Department[]>([])
  const [users, setUsers] = useState<User[]>([])
  const [search, setSearch] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<{ employee: Employee | null; createLogin?: boolean } | null>(null)
  const [salaryOf, setSalaryOf] = useState<Employee | null>(null)
  const [docsOf, setDocsOf] = useState<Employee | null>(null)

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
    if (!window.confirm(tr("Delete {1}? This cannot be undone.", { 1: employee.full_name }))) return
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
        title={isAdmin ? tr("Employees") : tr("My team")}
        subtitle={isAdmin ? tr("Personal and job records of everyone in your company.") : tr("People who report directly to you.")}
        actions={isAdmin && <button onClick={() => setEditing({ employee: null })}>{tr("+ New employee")}</button>}
      />

      <div className="filters">
        <input
          type="search"
          placeholder={tr("Search name, code, email, title…")}
          value={search}
          onChange={(e) => filterChange(setSearch)(e.target.value)}
        />
        <select value={departmentId} onChange={(e) => filterChange(setDepartmentId)(e.target.value)}>
          <option value="">{tr("All departments")}</option>
          {departments.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </select>
        <select value={status} onChange={(e) => filterChange(setStatus)(e.target.value)}>
          <option value="">{tr("All statuses")}</option>
          {STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {tr(s.label)}
            </option>
          ))}
        </select>
      </div>

      <ErrorText message={error} />

      {!result && !error && <p className="muted">{tr("Loading…")}</p>}

      {result && result.data.length === 0 && (
        <div className="card empty">
          <p>{tr("No employees found.")}</p>
        </div>
      )}

      {result && result.data.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>{tr("Employee")}</th>
                <th>{tr("Department")}</th>
                <th>{tr("Hired")}</th>
                <th>{tr("Status")}</th>
                {isAdmin && <th>{tr("Sign-in")}</th>}
                {isAdmin && <th className="actions-col" aria-label={tr("Actions")} />}
              </tr>
            </thead>
            <tbody>
              {result.data.map((e) => (
                <tr key={e.id}>
                  <td>
                    <Person
                      name={e.full_name}
                      detail={
                        <>
                          {e.employee_code}
                          {e.job_title && ` · ${e.job_title}`}
                        </>
                      }
                    />
                  </td>
                  <td>
                    {e.department?.name ?? <span className="muted">—</span>}
                    {isAdmin && e.manager && <div className="muted small">{tr("Reports to")}{' '}{e.manager.full_name}</div>}
                  </td>
                  <td>{formatDate(e.hire_date)}</td>
                  <td>
                    <StatusBadge status={e.status} />
                  </td>
                  {isAdmin && (
                    <td>
                      {e.user ? (
                        <span className="badge badge-approved" title={tr("Signs in as {1}", { 1: e.user.email })}>
                          {tr("Can sign in")}
                        </span>
                      ) : (
                        <button className="link" onClick={() => setEditing({ employee: e, createLogin: true })}>
                          {tr("+ Create login")}
                        </button>
                      )}
                    </td>
                  )}
                  {isAdmin && (
                    <td className="actions">
                      <button className="link" onClick={() => setSalaryOf(e)}>
                        {tr("Salary")}
                      </button>
                      <button className="link" onClick={() => setDocsOf(e)}>
                        {tr("Documents")}
                      </button>
                      <button className="link" onClick={() => setEditing({ employee: e })}>
                        {tr("Edit")}
                      </button>
                      <button className="link danger" onClick={() => remove(e)}>
                        {tr("Delete")}
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          <div className="table-foot muted small">
            <span>
              {result.meta.total} {tr("employee")}{result.meta.total === 1 ? '' : tr("s")}
            </span>
            {result.meta.last_page > 1 && (
              <span className="pager">
                <button className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                  {tr("‹ Prev")}
                </button>
                {tr("Page")}{' '}{result.meta.current_page} {tr("of")}{' '}{result.meta.last_page}
                <button className="secondary" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)}>
                  {tr("Next ›")}
                </button>
              </span>
            )}
          </div>
        </div>
      )}

      {salaryOf && (
        <Modal title={tr("Salary — {1}", { 1: salaryOf.full_name })} onClose={() => setSalaryOf(null)}>
          <SalaryPanel employeeId={salaryOf.id} canEdit={isAdmin} />
        </Modal>
      )}

      {docsOf && (
        <Modal title={tr("Documents — {1}", { 1: docsOf.full_name })} onClose={() => setDocsOf(null)} wide>
          <DocumentsPanel employeeId={docsOf.id} canEdit={isAdmin} />
        </Modal>
      )}

      {editing && (
        <EmployeeForm
          employee={editing.employee}
          createLogin={editing.createLogin}
          canGrantAnyRole={user.role === 'admin'}
          defaultPassword={`${tenantSlug}Password123`}
          departments={departments}
          users={users}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
            api.users.list().then(setUsers).catch(() => {})
          }}
        />
      )}
    </section>
  )
}

type SignInMode = 'none' | 'new' | 'existing'
type TextField = Exclude<keyof EmployeeInput, 'account' | 'new_password'>

/** A readable random password that meets the server rule (12+ chars, letters and numbers). */
function generatePassword(): string {
  const letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ'
  const digits = '23456789'
  const pick = (set: string, n: number) =>
    Array.from(crypto.getRandomValues(new Uint32Array(n)), (x) => set[x % set.length]).join('')
  return `${pick(letters, 5)}-${pick(digits, 4)}-${pick(letters, 5)}`
}

function EmployeeForm({
  employee,
  createLogin = false,
  canGrantAnyRole,
  defaultPassword,
  departments,
  users,
  onClose,
  onSaved,
}: {
  employee: Employee | null
  createLogin?: boolean
  canGrantAnyRole: boolean
  /** The company's starting password, e.g. "elnadyPassword123". */
  defaultPassword: string
  departments: Department[]
  users: User[]
  onClose: () => void
  onSaved: () => void
}) {
  const [mode, setMode] = useState<SignInMode>(
    employee?.user ? 'existing' : !employee || createLogin ? 'new' : 'none',
  )
  const [accountRole, setAccountRole] = useState<UserRole>('employee')
  const [password, setPassword] = useState(defaultPassword)
  const roles: UserRole[] = canGrantAnyRole ? ['employee', 'team_leader', 'hr', 'admin'] : ['employee', 'team_leader']
  const freeUsers = users.filter((u) => !u.employee || u.employee.id === employee?.id)
  const [resetPassword, setResetPassword] = useState(false)
  // Only the account already linked to this employee, and HR cannot reset HR/admin logins.
  const linkedUser = users.find((u) => u.id === employee?.user?.id)
  const mayResetLinked = !!employee?.user && (!linkedUser || roles.includes(linkedUser.role))
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
  const canResetPassword = mayResetLinked && form.user_id === employee?.user?.id
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

  const set = (field: TextField) => (value: string) => setForm((f) => ({ ...f, [field]: value }))

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    const input: EmployeeInput = {
      ...form,
      user_id: mode === 'existing' ? form.user_id : '',
      account: mode === 'new' ? { role: accountRole, password } : null,
      new_password: mode === 'existing' && canResetPassword && resetPassword ? password : null,
    }
    try {
      if (employee) await api.employees.update(employee.id, input)
      else await api.employees.create(input)
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  const field = (name: TextField, label: string, props: Record<string, unknown> = {}) => (
    <label>
      {label}
      <input value={form[name]} onChange={(e) => set(name)(e.target.value)} aria-invalid={!!errors?.field(name)} {...props} />
      <FieldError message={errors?.field(name)} />
    </label>
  )

  return (
    <Modal title={employee ? tr("Edit {1}", { 1: employee.full_name }) : tr("New employee")} onClose={onClose} wide>
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
            {tr("Status")}
            <select value={form.status} onChange={(e) => set('status')(e.target.value)}>
              {STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {tr(s.label)}
                </option>
              ))}
            </select>
            <FieldError message={errors?.field('status')} />
          </label>
          <label>
            {tr("Department")}
            <select value={form.department_id} onChange={(e) => set('department_id')(e.target.value)}>
              <option value="">{tr("— None —")}</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
            <FieldError message={errors?.field('department_id')} />
          </label>
          <label>
            {tr("Reports to")}{' '}<span className="muted small">{tr("(team leader approves their leave)")}</span>
            <select value={form.manager_id} onChange={(e) => set('manager_id')(e.target.value)}>
              <option value="">{tr("— Nobody —")}</option>
              {managers.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.full_name}
                  {m.job_title ? ` — ${m.job_title}` : ''}
                </option>
              ))}
            </select>
            <FieldError message={errors?.field('manager_id')} />
          </label>
        </div>

        <div className="signin-box">
          <div className="signin-head">
            <div>
              <strong>{tr("Sign-in")}</strong>
              <div className="muted small">{tr("Lets the employee open the system to check in and request leave.")}</div>
            </div>
            <div className="tabs" role="tablist">
              {(
                [
                  ['none', 'No login'],
                  ['new', employee?.user ? 'New login' : 'Create login'],
                  ['existing', 'Existing account'],
                ] as [SignInMode, string][]
              )
                .filter(([value]) => !(value === 'new' && employee?.user))
                .map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    role="tab"
                    aria-selected={mode === value}
                    className={`tab ${mode === value ? 'active' : ''}`}
                    onClick={() => setMode(value)}
                  >
                    {label}
                  </button>
                ))}
            </div>
          </div>

          {mode === 'none' && (
            <div className="muted small">
              {tr("This employee won't sign in. HR can still record their leave, attendance and salary.")}
            </div>
          )}

          {mode === 'new' && (
            <>
              <div className="form-grid">
                <label>
                  {tr("Signs in with")}
                  <input value={form.email || 'Enter the email above'} disabled />
                  <FieldError message={errors?.field('account')} />
                </label>
                <label>
                  {tr("Role")}
                  <select value={accountRole} onChange={(e) => setAccountRole(e.target.value as UserRole)}>
                    {roles.map((r) => (
                      <option key={r} value={r}>
                        {tr(ROLE_LABELS[r])}
                      </option>
                    ))}
                  </select>
                  <FieldError message={errors?.field('account.role')} />
                </label>
                <label>
                  {tr("Password")}
                  <span className="password-row">
                    <input value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="off" />
                    <button type="button" className="secondary" onClick={() => setPassword(generatePassword())}>
                      {tr("New")}
                    </button>
                  </span>
                  <FieldError message={errors?.field('account.password')} />
                </label>
              </div>
              <div className="muted small">
                {tr("The company's starting password is filled in. Give it to the employee: they choose their own at first sign-in.")}
              </div>
            </>
          )}

          {mode === 'existing' && (
            <label>
              {tr("Account")}
              <select value={form.user_id} onChange={(e) => set('user_id')(e.target.value)}>
                <option value="">{tr("— Choose an account —")}</option>
                {freeUsers.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name} ({u.email}) — {tr(ROLE_LABELS[u.role])}
                  </option>
                ))}
              </select>
              <FieldError message={errors?.field('user_id')} />
            </label>
          )}

          {mode === 'existing' && canResetPassword && (
            <>
              <label className="checkbox">
                <input type="checkbox" checked={resetPassword} onChange={(e) => setResetPassword(e.target.checked)} />
                {tr("Set a new password for")}{' '}{employee?.user?.email}
              </label>
              {resetPassword && (
                <label>
                  {tr("New password")}
                  <span className="password-row">
                    <input value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="off" />
                    <button type="button" className="secondary" onClick={() => setPassword(generatePassword())}>
                      {tr("New")}
                    </button>
                  </span>
                  <FieldError message={errors?.field('new_password')} />
                </label>
              )}
            </>
          )}
        </div>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving}>
            {saving ? tr("Saving…") : tr("Save")}
          </button>
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            {tr("Cancel")}
          </button>
        </div>
      </form>
    </Modal>
  )
}
