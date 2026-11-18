import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import { managesHr, type Holiday, type User } from '../types'
import { ErrorText, FieldError, Modal, PageHead } from '../components/ui'
import { dateRange } from '../format'

export function HolidaysPage({ user }: { user: User }) {
  const canEdit = managesHr(user)
  const [year, setYear] = useState(() => new Date().getFullYear())
  const [holidays, setHolidays] = useState<Holiday[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<Holiday | 'new' | null>(null)

  const load = useCallback(async () => {
    try {
      setHolidays(await api.holidays.list(year))
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [year])

  useEffect(() => {
    let active = true
    api.holidays
      .list(year)
      .then((list) => active && setHolidays(list))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [year])

  async function remove(holiday: Holiday) {
    if (!window.confirm(`Delete "${holiday.name}"?`)) return
    try {
      await api.holidays.remove(holiday.id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  return (
    <section>
      <PageHead
        title="Public holidays"
        subtitle={
          canEdit
            ? 'Days off for everyone. They are not counted in leave requests submitted after you add them.'
            : 'Official days off for the whole company.'
        }
        actions={canEdit && <button onClick={() => setEditing('new')}>+ New holiday</button>}
      />

      <div className="filters">
        <span className="pager">
          <button className="secondary" onClick={() => setYear(year - 1)}>
            ‹ {year - 1}
          </button>
          <strong>{year}</strong>
          <button className="secondary" onClick={() => setYear(year + 1)}>
            {year + 1} ›
          </button>
        </span>
      </div>

      <ErrorText message={error} />
      {holidays === null && !error && <p className="muted">Loading…</p>}

      {holidays?.length === 0 && (
        <div className="card empty">
          <p>No public holidays in {year}.</p>
        </div>
      )}

      {holidays && holidays.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>Holiday</th>
                <th>Dates</th>
                <th className="num">Days</th>
                {canEdit && <th className="actions-col" aria-label="Actions" />}
              </tr>
            </thead>
            <tbody>
              {holidays.map((h) => (
                <tr key={h.id}>
                  <td className="strong">{h.name}</td>
                  <td>{dateRange(h.start_date, h.end_date)}</td>
                  <td className="num">{h.days}</td>
                  {canEdit && (
                    <td className="actions">
                      <button className="link" onClick={() => setEditing(h)}>
                        Edit
                      </button>
                      <button className="link danger" onClick={() => remove(h)}>
                        Delete
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <HolidayForm
          holiday={editing === 'new' ? null : editing}
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

function HolidayForm({ holiday, onClose, onSaved }: { holiday: Holiday | null; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(holiday?.name ?? '')
  const [start, setStart] = useState(holiday?.start_date ?? '')
  const [end, setEnd] = useState(holiday?.end_date ?? '')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    const input = { name, start_date: start, end_date: end || start }
    try {
      if (holiday) await api.holidays.update(holiday.id, input)
      else await api.holidays.create(input)
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={holiday ? `Edit “${holiday.name}”` : 'New public holiday'} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <label>
          Name
          <input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoFocus placeholder="e.g. Eid al-Fitr" />
          <FieldError message={errors?.field('name')} />
        </label>
        <div className="form-grid">
          <label>
            From
            <input
              type="date"
              value={start}
              onChange={(e) => {
                setStart(e.target.value)
                if (!end || e.target.value > end) setEnd(e.target.value)
              }}
            />
            <FieldError message={errors?.field('start_date')} />
          </label>
          <label>
            To <span className="muted small">(same day for a one-day holiday)</span>
            <input type="date" value={end} min={start} onChange={(e) => setEnd(e.target.value)} />
            <FieldError message={errors?.field('end_date')} />
          </label>
        </div>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !name.trim() || !start}>
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
