import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import { managesHr, type AttendanceRecord, type AttendanceToday, type Employee, type GeoPoint, type User } from '../types'
import { ErrorText, FieldError, Modal, PageHead } from '../components/ui'
import { formatDate, formatDuration, formatTime, localToday } from '../format'

/** Browser location, or null when unavailable/denied (the server decides if it is required). */
function currentPosition(): Promise<{ latitude: number; longitude: number; accuracy: number } | null> {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) return resolve(null)
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  })
}

function monthStart(): string {
  return `${localToday().slice(0, 8)}01`
}

export function AttendancePage({ user }: { user: User }) {
  const canManage = managesHr(user)
  const isLeader = user.role === 'team_leader'
  const [today, setToday] = useState<AttendanceToday | null>(null)
  const [records, setRecords] = useState<AttendanceRecord[] | null>(null)
  const [from, setFrom] = useState(monthStart)
  const [to, setTo] = useState(localToday)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [editing, setEditing] = useState<AttendanceRecord | 'new' | null>(null)

  const load = useCallback(async () => {
    try {
      const [t, list] = await Promise.all([user.employee ? api.attendance.today() : Promise.resolve(null), api.attendance.list({ from, to })])
      setToday(t)
      setRecords(list)
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [from, to, user.employee])

  useEffect(() => {
    let active = true
    Promise.all([user.employee ? api.attendance.today() : Promise.resolve(null), api.attendance.list({ from, to })])
      .then(([t, list]) => {
        if (!active) return
        setToday(t)
        setRecords(list)
      })
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [from, to, user.employee])

  async function punch(action: 'in' | 'out') {
    setBusy('Getting your location…')
    setError(null)
    const position = await currentPosition()
    setBusy(action === 'in' ? 'Checking in…' : 'Checking out…')
    try {
      if (action === 'in') await api.attendance.checkIn(position ?? {})
      else await api.attendance.checkOut(position ?? {})
      await load()
    } catch (e) {
      setError(
        e instanceof ApiError ? (e.field('location') ?? e.field('attendance') ?? e.message) : errorMessage(e),
      )
    } finally {
      setBusy(null)
    }
  }

  const record = today?.record
  const showEmployee = canManage || isLeader

  return (
    <section>
      <PageHead
        title="Attendance"
        subtitle={today ? `Working hours ${formatTime(today.schedule.work_start)} – ${formatTime(today.schedule.work_end)}, ${today.schedule.grace_minutes} minutes grace.` : undefined}
        actions={canManage && <button className="secondary" onClick={() => setEditing('new')}>+ Add a record</button>}
      />

      {user.employee && today && (
        <div className="card punch">
          <div>
            <div className="muted small">{formatDate(today.date)}</div>
            {!record?.check_in && <div className="punch-status">You have not checked in yet.</div>}
            {record?.check_in && !record.check_out && (
              <div className="punch-status">
                Checked in at <strong>{formatTime(record.check_in)}</strong>
                {record.late_minutes > 0 && <span className="badge badge-rejected">{formatDuration(record.late_minutes)} late</span>}
              </div>
            )}
            {record?.check_out && (
              <div className="punch-status">
                {formatTime(record.check_in!)} → {formatTime(record.check_out)} · worked{' '}
                <strong>{formatDuration(record.worked_minutes ?? 0)}</strong>
              </div>
            )}
            {today.schedule.require_location && !record?.check_out && (
              <div className="muted small">Your location is recorded. Allow location access when the browser asks.</div>
            )}
          </div>
          <div>
            {!record?.check_in && (
              <button className="punch-button" disabled={!!busy} onClick={() => punch('in')}>
                {busy ?? 'Check in'}
              </button>
            )}
            {record?.check_in && !record.check_out && (
              <button className="punch-button danger" disabled={!!busy} onClick={() => punch('out')}>
                {busy ?? 'Check out'}
              </button>
            )}
          </div>
        </div>
      )}

      <ErrorText message={error} />

      <div className="filters">
        <label className="inline">
          From <input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label className="inline">
          To <input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        </label>
      </div>

      {records === null && !error && <p className="muted">Loading…</p>}
      {records?.length === 0 && (
        <div className="card empty">
          <p>No attendance in this period.</p>
        </div>
      )}

      {records && records.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                {showEmployee && <th>Employee</th>}
                <th>Date</th>
                <th>Check in</th>
                <th>Check out</th>
                <th>Worked</th>
                <th>Late</th>
                {canManage && <th className="actions-col" aria-label="Actions" />}
              </tr>
            </thead>
            <tbody>
              {records.map((r) => (
                <tr key={r.id}>
                  {showEmployee && (
                    <td>
                      <div className="strong">{r.employee?.full_name}</div>
                      <div className="muted small">{r.employee?.employee_code}</div>
                    </td>
                  )}
                  <td>
                    {formatDate(r.date)}
                    {r.note && <div className="muted small">{r.note}</div>}
                    {r.edited_by && <div className="muted small">Edited by {r.edited_by}</div>}
                  </td>
                  <td>
                    {r.check_in ? formatTime(r.check_in) : '—'} <MapLink point={r.check_in_location} />
                  </td>
                  <td>
                    {r.check_out ? formatTime(r.check_out) : '—'} <MapLink point={r.check_out_location} />
                  </td>
                  <td>{r.worked_minutes !== null ? formatDuration(r.worked_minutes) : '—'}</td>
                  <td>{r.late_minutes > 0 ? <span className="field-error">{formatDuration(r.late_minutes)}</span> : '—'}</td>
                  {canManage && (
                    <td className="actions">
                      <button className="link" onClick={() => setEditing(r)}>
                        Edit
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
        <RecordForm
          record={editing === 'new' ? null : editing}
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

function MapLink({ point }: { point: GeoPoint | null }) {
  if (!point) return null
  const title = point.accuracy !== null ? `Accuracy about ${point.accuracy} m` : 'Location'
  return (
    <a
      className="small"
      href={`https://www.google.com/maps?q=${point.latitude},${point.longitude}`}
      target="_blank"
      rel="noreferrer noopener"
      title={title}
    >
      📍map
    </a>
  )
}

function RecordForm({ record, onClose, onSaved }: { record: AttendanceRecord | null; onClose: () => void; onSaved: () => void }) {
  const [employees, setEmployees] = useState<Employee[]>([])
  const [employeeId, setEmployeeId] = useState('')
  const [date, setDate] = useState(record?.date ?? localToday())
  const [checkIn, setCheckIn] = useState(record?.check_in ?? '')
  const [checkOut, setCheckOut] = useState(record?.check_out ?? '')
  const [note, setNote] = useState(record?.note ?? '')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (record) return
    api.employees
      .list({ status: 'active', per_page: 200 })
      .then((r) => setEmployees(r.data))
      .catch(() => {})
  }, [record])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    const times = { check_in: checkIn, check_out: checkOut || null, note: note || null }
    try {
      if (record) await api.attendance.update(record.id, times)
      else await api.attendance.create({ employee_id: employeeId, date, ...times })
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={record ? `Correct attendance — ${record.employee?.full_name ?? ''}` : 'Add attendance record'} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        {record ? (
          <div className="summary">{formatDate(record.date)}</div>
        ) : (
          <>
            <label>
              Employee
              <select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} autoFocus>
                <option value="">— Select employee —</option>
                {employees.map((e) => (
                  <option key={e.id} value={e.id}>
                    {e.full_name} ({e.employee_code})
                  </option>
                ))}
              </select>
              <FieldError message={errors?.field('employee_id')} />
            </label>
            <label>
              Date
              <input type="date" value={date} max={localToday()} onChange={(e) => setDate(e.target.value)} />
              <FieldError message={errors?.field('date')} />
            </label>
          </>
        )}
        <div className="form-grid">
          <label>
            Check in
            <input type="time" value={checkIn} onChange={(e) => setCheckIn(e.target.value)} />
            <FieldError message={errors?.field('check_in')} />
          </label>
          <label>
            Check out <span className="muted small">(optional)</span>
            <input type="time" value={checkOut} onChange={(e) => setCheckOut(e.target.value)} />
            <FieldError message={errors?.field('check_out')} />
          </label>
        </div>
        <label>
          Note <span className="muted small">(why it was changed)</span>
          <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
        </label>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !checkIn || (!record && !employeeId)}>
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
