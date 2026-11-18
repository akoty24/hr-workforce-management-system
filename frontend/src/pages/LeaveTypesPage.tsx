import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { LeaveType } from '../types'
import { ErrorText, FieldError, Modal, PageHead } from '../components/ui'

export function LeaveTypesPage() {
  const [types, setTypes] = useState<LeaveType[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<LeaveType | 'new' | null>(null)

  const load = useCallback(async () => {
    try {
      setTypes(await api.leaveTypes.list())
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [])

  useEffect(() => {
    let active = true
    api.leaveTypes
      .list()
      .then((list) => active && setTypes(list))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [])

  async function remove(type: LeaveType) {
    if (!window.confirm(`Delete the "${type.name}" leave type?`)) return
    try {
      await api.leaveTypes.remove(type.id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  return (
    <section>
      <PageHead
        title="Leave types"
        subtitle="Kinds of leave employees can request, with optional yearly allowance."
        actions={<button onClick={() => setEditing('new')}>+ New leave type</button>}
      />
      <ErrorText message={error} />
      {types === null && !error && <p className="muted">Loading…</p>}
      {types && types.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Allowance per year</th>
                <th>Status</th>
                <th className="actions-col" aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {types.map((t) => (
                <tr key={t.id}>
                  <td className="strong">{t.name}</td>
                  <td>
                    {t.days_per_year === null ? 'Unlimited' : `${t.days_per_year} working days`}
                    {t.days_per_year !== null && t.prorate_first_year && (
                      <div className="muted small">Prorated in the hire year</div>
                    )}
                  </td>
                  <td>{t.is_active ? <span className="badge badge-active">Active</span> : <span className="badge badge-cancelled">Inactive</span>}</td>
                  <td className="actions">
                    <button className="link" onClick={() => setEditing(t)}>
                      Edit
                    </button>
                    <button className="link danger" onClick={() => remove(t)}>
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {types?.length === 0 && (
        <div className="card empty">
          <p>No leave types yet. Create one so employees can request leave.</p>
        </div>
      )}
      {editing && (
        <LeaveTypeForm
          type={editing === 'new' ? null : editing}
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

function LeaveTypeForm({ type, onClose, onSaved }: { type: LeaveType | null; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(type?.name ?? '')
  const [days, setDays] = useState(type?.days_per_year?.toString() ?? '')
  const [prorate, setProrate] = useState(type?.prorate_first_year ?? false)
  const [isActive, setIsActive] = useState(type?.is_active ?? true)
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    const input = { name, days_per_year: days === '' ? null : Number(days), prorate_first_year: prorate, is_active: isActive }
    try {
      if (type) await api.leaveTypes.update(type.id, input)
      else await api.leaveTypes.create(input)
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={type ? `Edit “${type.name}”` : 'New leave type'} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <label>
          Name
          <input value={name} onChange={(e) => setName(e.target.value)} maxLength={80} autoFocus placeholder="e.g. Annual Leave" />
          <FieldError message={errors?.field('name')} />
        </label>
        <label>
          Working days per year <span className="muted small">(leave empty for unlimited)</span>
          <input type="number" min={1} max={365} value={days} onChange={(e) => setDays(e.target.value)} />
          <FieldError message={errors?.field('days_per_year')} />
        </label>
        {days !== '' && (
          <label className="checkbox">
            <input type="checkbox" checked={prorate} onChange={(e) => setProrate(e.target.checked)} />
            <span>
              Prorate for new joiners
              <span className="muted small" style={{ display: 'block' }}>
                Employees hired during the year get a share for the months left (hire month counts if they joined by
                the 15th). Example: 21 days, hired in July → 11 days that year.
              </span>
            </span>
          </label>
        )}
        <label className="checkbox">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          Available for new requests
        </label>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !name.trim()}>
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
