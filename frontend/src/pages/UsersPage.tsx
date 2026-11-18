import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { User, UserInput, UserRole } from '../types'
import { ErrorText, FieldError, Modal, PageHead } from '../components/ui'
import { formatDate } from '../format'

export function UsersPage({ currentUser }: { currentUser: User }) {
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
        title="Users"
        subtitle="People who can sign in to this workspace."
        actions={<button onClick={() => setEditing('new')}>+ New user</button>}
      />
      <ErrorText message={error} />
      {users === null && !error && <p className="muted">Loading…</p>}
      {users && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Employee record</th>
                <th>Status</th>
                <th>Created</th>
                <th className="actions-col" aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>
                    <div className="strong">
                      {u.name} {u.id === currentUser.id && <span className="muted small">(you)</span>}
                    </div>
                    <div className="muted small">{u.email}</div>
                  </td>
                  <td>
                    <span className={`role role-${u.role}`}>{u.role}</span>
                  </td>
                  <td>{u.employee?.full_name ?? <span className="muted">—</span>}</td>
                  <td>{u.is_active ? <span className="badge badge-active">Active</span> : <span className="badge badge-cancelled">Disabled</span>}</td>
                  <td>{formatDate(u.created_at)}</td>
                  <td className="actions">
                    <button className="link" onClick={() => setEditing(u)}>
                      Edit
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

function UserForm({ user, isSelf, onClose, onSaved }: { user: User | null; isSelf: boolean; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState<UserInput>({
    name: user?.name ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'employee',
    is_active: user?.is_active ?? true,
    password: '',
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
    <Modal title={user ? `Edit ${user.name}` : 'New user'} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <label>
          Name
          <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoFocus />
          <FieldError message={errors?.field('name')} />
        </label>
        <label>
          Email
          <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <FieldError message={errors?.field('email')} />
        </label>
        <label>
          Role
          <select value={form.role} disabled={isSelf} onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })}>
            <option value="employee">Employee — sees own data, requests leave</option>
            <option value="admin">Admin — manages the company</option>
          </select>
          <FieldError message={errors?.field('role')} />
        </label>
        <label>
          {user ? 'New password' : 'Password'}{' '}
          <span className="muted small">{user ? '(leave empty to keep)' : '(min. 12 characters, letters and numbers)'}</span>
          <input type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          <FieldError message={errors?.field('password')} />
        </label>
        {user && (
          <label className="checkbox">
            <input type="checkbox" checked={form.is_active} disabled={isSelf} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            Account active (can sign in)
          </label>
        )}
        <FieldError message={errors?.field('is_active')} />
        {isSelf && <p className="muted small">You cannot change your own role or disable your own account.</p>}
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
