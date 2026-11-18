import { useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import { ROLE_LABELS, type User } from '../types'
import { ErrorText, FieldError, PageHead } from '../components/ui'

export function AccountPage({ user }: { user: User }) {
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    setSaved(false)
    try {
      await api.changePassword(current, password, confirmation)
      setSaved(true)
      setCurrent('')
      setPassword('')
      setConfirmation('')
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <section>
      <PageHead title="My account" />
      <div className="grid-2">
        <div className="card">
          <h2>Profile</h2>
          <dl className="details">
            <dt>Name</dt>
            <dd>{user.name}</dd>
            <dt>Email</dt>
            <dd>{user.email}</dd>
            <dt>Role</dt>
            <dd>
              <span className={`role role-${user.role}`}>{ROLE_LABELS[user.role]}</span>
            </dd>
            <dt>Employee record</dt>
            <dd>{user.employee?.full_name ?? '—'}</dd>
          </dl>
        </div>

        <form className="card form" onSubmit={submit} noValidate>
          <h2>Change password</h2>
          <label>
            Current password
            <input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} />
            <FieldError message={errors?.field('current_password')} />
          </label>
          <label>
            New password <span className="muted small">(min. 12 characters, letters and numbers)</span>
            <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
            <FieldError message={errors?.field('password')} />
          </label>
          <label>
            Confirm new password
            <input type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
          </label>
          <ErrorText message={general} />
          {saved && <p className="success">Password changed. Other devices have been signed out.</p>}
          <div className="form-actions">
            <button type="submit" disabled={saving || !current || !password}>
              {saving ? 'Saving…' : 'Change password'}
            </button>
          </div>
        </form>
      </div>
    </section>
  )
}
