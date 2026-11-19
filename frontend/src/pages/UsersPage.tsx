import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import { ROLE_LABELS, type User, type UserInput, type UserRole } from '../types'
import { ErrorText, FieldError, Modal, PageHead } from '../components/ui'
import { formatDate } from '../format'
import { tr } from '../i18n'

export function UsersPage({ currentUser, tenantSlug }: { currentUser: User; tenantSlug: string }) {
  const [users, setUsers] = useState<User[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<User | 'new' | null>(null)

  const load = useCallback(async () => {
    try {
      setUsers(await api.users.list())
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [])

  useEffect(() => {
    let active = true
    api.users
      .list()
      .then((list) => active && setUsers(list))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [])

  return (
    <section>
      <PageHead
        title={tr("Users")}
        subtitle={tr("People who can sign in to this workspace.")}
        actions={<button onClick={() => setEditing('new')}>{tr("+ New user")}</button>}
      />
      <ErrorText message={error} />
      {users === null && !error && <p className="muted">{tr("Loading…")}</p>}
      {users && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>{tr("Name")}</th>
                <th>{tr("Role")}</th>
                <th>{tr("Employee record")}</th>
                <th>{tr("Status")}</th>
                <th>{tr("Created")}</th>
                <th className="actions-col" aria-label={tr("Actions")} />
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>
                    <div className="strong">
                      {u.name} {u.id === currentUser.id && <span className="muted small">{tr("(you)")}</span>}
                    </div>
                    <div className="muted small">{u.email}</div>
                  </td>
                  <td>
                    <span className={`role role-${u.role}`}>{tr(ROLE_LABELS[u.role])}</span>
                  </td>
                  <td>{u.employee?.full_name ?? <span className="muted">—</span>}</td>
                  <td>{u.is_active ? <span className="badge badge-active">{tr("Active")}</span> : <span className="badge badge-cancelled">{tr("Disabled")}</span>}</td>
                  <td>{formatDate(u.created_at)}</td>
                  <td className="actions">
                    <button className="link" onClick={() => setEditing(u)}>
                      {tr("Edit")}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {editing && (
        <UserForm
          user={editing === 'new' ? null : editing}
          isSelf={editing !== 'new' && editing.id === currentUser.id}
          defaultPassword={`${tenantSlug}Password123`}
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

function UserForm({
  user,
  isSelf,
  defaultPassword,
  onClose,
  onSaved,
}: {
  user: User | null
  isSelf: boolean
  /** The company's starting password, e.g. "elnadyPassword123". */
  defaultPassword: string
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState<UserInput>({
    name: user?.name ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'employee',
    is_active: user?.is_active ?? true,
    password: user ? '' : defaultPassword,
  })
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    const input = { ...form, password: form.password || undefined }
    try {
      if (user) await api.users.update(user.id, input)
      else await api.users.create(input)
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={user ? tr("Edit {1}", { 1: user.name }) : tr("New user")} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <label>
          {tr("Name")}
          <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoFocus />
          <FieldError message={errors?.field('name')} />
        </label>
        <label>
          {tr("Email")}
          <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <FieldError message={errors?.field('email')} />
        </label>
        <label>
          {tr("Role")}
          <select value={form.role} disabled={isSelf} onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })}>
            <option value="employee">{tr("Employee — sees own data, requests leave")}</option>
            <option value="team_leader">{tr("Team leader — also approves leave of their direct reports")}</option>
            <option value="hr">{tr("HR — manages employees, departments, leave; decides all requests")}</option>
            <option value="admin">{tr("Admin — everything, including user accounts and roles")}</option>
          </select>
          <FieldError message={errors?.field('role')} />
        </label>
        <label>
          {user ? tr("New password") : tr("Password")}{' '}
          <span className="muted small">
            {user ? tr("(leave empty to keep)") : tr("(the company's starting password; they choose their own at first sign-in)")}
          </span>
          {user && !isSelf && (
            <button type="button" className="link small" onClick={() => setForm({ ...form, password: defaultPassword })}>
              {tr("Use the starting password")}
            </button>
          )}
          <input type={user ? 'password' : 'text'} autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          <FieldError message={errors?.field('password')} />
        </label>
        {user && (
          <label className="checkbox">
            <input type="checkbox" checked={form.is_active} disabled={isSelf} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            {tr("Account active (can sign in)")}
          </label>
        )}
        <FieldError message={errors?.field('is_active')} />
        {isSelf && <p className="muted small">{tr("You cannot change your own role or disable your own account.")}</p>}
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
