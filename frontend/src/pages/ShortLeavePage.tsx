import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import { managesHr, type Employee, type LeaveStatus, type ShortLeave, type User } from '../types'
import { ErrorText, FieldError, Modal, PageHead, Person, StatusBadge } from '../components/ui'
import { formatDate, formatDuration, formatTime, localToday } from '../format'
import { tr } from '../i18n'

/** Must match HR_SHORT_LEAVE_MAX_MINUTES on the server (default 120). */
const MAX_MINUTES = 120

const FILTERS: { value: LeaveStatus | ''; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: '', label: 'All' },
]

type Decision = { item: ShortLeave; action: 'approve' | 'reject' }

function minutesBetween(from: string, to: string): number {
  if (!from || !to) return 0
  const [fh, fm] = from.split(':').map(Number)
  const [th, tm] = to.split(':').map(Number)
  return th * 60 + tm - (fh * 60 + fm)
}

export function ShortLeavePage({ user }: { user: User }) {
  const isAdmin = managesHr(user)
  const isLeader = user.role === 'team_leader'
  const isOwn = (p: ShortLeave) => p.employee.id === user.employee?.id
  const canDecide = (p: ShortLeave) => (isAdmin || isLeader) && !isOwn(p)
  const [status, setStatus] = useState<LeaveStatus | ''>(isAdmin || isLeader ? 'pending' : '')
  const [items, setItems] = useState<ShortLeave[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [deciding, setDeciding] = useState<Decision | null>(null)

  const load = useCallback(async () => {
    try {
      setItems(await api.shortLeaves.list({ status }))
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [status])

  useEffect(() => {
    let active = true
    api.shortLeaves
      .list({ status })
      .then((list) => active && setItems(list))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [status])

  async function cancel(item: ShortLeave) {
    if (!window.confirm(tr("Cancel this permission request?"))) return
    try {
      await api.shortLeaves.cancel(item.id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  const canRequest = isAdmin || !!user.employee
  const showEmployee = isAdmin || isLeader

  return (
    <section>
      <PageHead
        title={tr("Permissions")}
        subtitle={tr("Leave for part of a working day, up to {1}. Not deducted from leave balance.", { 1: formatDuration(MAX_MINUTES) })}
        actions={canRequest && <button onClick={() => setCreating(true)}>{tr("+ Request permission")}</button>}
      />

      {!canRequest && (
        <div className="card notice">{tr("Your account is not linked to an employee record yet. Ask an administrator.")}</div>
      )}

      <div className="tabs" role="tablist">
        {FILTERS.map((f) => (
          <button
            key={f.value || 'all'}
            role="tab"
            aria-selected={status === f.value}
            className={`tab ${status === f.value ? 'active' : ''}`}
            onClick={() => setStatus(f.value)}
          >
            {tr(f.label)}
          </button>
        ))}
      </div>

      <ErrorText message={error} />
      {items === null && !error && <p className="muted">{tr("Loading…")}</p>}

      {items?.length === 0 && (
        <div className="card empty">
          <p>{tr("No permissions here.")}</p>
        </div>
      )}

      {items && items.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                {showEmployee && <th>{tr("Employee")}</th>}
                <th>{tr("Date")}</th>
                <th>{tr("Time")}</th>
                <th>{tr("Duration")}</th>
                <th>{tr("Status")}</th>
                <th className="actions-col" aria-label={tr("Actions")} />
              </tr>
            </thead>
            <tbody>
              {items.map((p) => (
                <tr key={p.id}>
                  {showEmployee && (
                    <td>
                      <Person name={p.employee.full_name} detail={p.employee.employee_code} you={isOwn(p)} />
                    </td>
                  )}
                  <td>
                    {formatDate(p.date)}
                    {p.reason && <div className="muted small cell-note">{p.reason}</div>}
                  </td>
                  <td>
                    {formatTime(p.start_time)} → {formatTime(p.end_time)}
                  </td>
                  <td>{formatDuration(p.minutes)}</td>
                  <td>
                    <StatusBadge status={p.status} />
                    {p.decision_note && <div className="muted small">“{p.decision_note}”</div>}
                    {p.decided_by && (
                      <div className="muted small">
                        {tr("by")}{' '}{p.decided_by}, {formatDate(p.decided_at)}
                      </div>
                    )}
                  </td>
                  <td className="actions">
                    {p.status === 'pending' && canDecide(p) && (
                      <>
                        <button className="link" onClick={() => setDeciding({ item: p, action: 'approve' })}>
                          {tr("Approve")}
                        </button>
                        <button className="link danger" onClick={() => setDeciding({ item: p, action: 'reject' })}>
                          {tr("Reject")}
                        </button>
                      </>
                    )}
                    {p.status === 'pending' && isOwn(p) && (
                      <button className="link danger" onClick={() => cancel(p)}>
                        {tr("Cancel")}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {creating && (
        <PermissionForm
          user={user}
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false)
            load()
          }}
        />
      )}

      {deciding && (
        <DecisionForm
          decision={deciding}
          onClose={() => setDeciding(null)}
          onDone={() => {
            setDeciding(null)
            load()
          }}
        />
      )}
    </section>
  )
}

function PermissionForm({ user, onClose, onSaved }: { user: User; onClose: () => void; onSaved: () => void }) {
  const isAdmin = managesHr(user)
  const [today] = useState(localToday)
  const [employees, setEmployees] = useState<Employee[]>([])
  const [employeeId, setEmployeeId] = useState('')
  const [date, setDate] = useState(today)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!isAdmin) return
    api.employees
      .list({ status: 'active', per_page: 200 })
      .then((r) => setEmployees(r.data))
      .catch(() => {})
  }, [isAdmin])

  const minutes = minutesBetween(from, to)
  const tooLong = minutes > MAX_MINUTES
  const invalidRange = !!from && !!to && minutes <= 0

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    try {
      await api.shortLeaves.create({
        employee_id: isAdmin ? employeeId || undefined : undefined,
        date,
        start_time: from,
        end_time: to,
        reason,
      })
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={tr("Request permission")} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        {isAdmin && (
          <label>
            {tr("Employee")}
            <select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} autoFocus>
              <option value="">{user.employee ? tr("Myself ({1})", { 1: user.employee.full_name }) : tr("— Select employee —")}</option>
              {employees
                .filter((e) => e.id !== user.employee?.id)
                .map((e) => (
                  <option key={e.id} value={e.id}>
                    {e.full_name} ({e.employee_code})
                  </option>
                ))}
            </select>
            <FieldError message={errors?.field('employee_id')} />
          </label>
        )}
        <label>
          {tr("Date")}
          <input type="date" value={date} min={isAdmin ? undefined : today} onChange={(e) => setDate(e.target.value)} />
          <FieldError message={errors?.field('date')} />
        </label>
        <div className="form-grid">
          <label>
            {tr("From")}
            <input type="time" step={60} value={from} onChange={(e) => setFrom(e.target.value)} />
            <FieldError message={errors?.field('start_time')} />
          </label>
          <label>
            {tr("To")}
            <input type="time" step={60} value={to} onChange={(e) => setTo(e.target.value)} />
            <FieldError message={errors?.field('end_time')} />
          </label>
        </div>
        <p className={`small ${tooLong || invalidRange ? 'field-error' : 'muted'}`}>
          {invalidRange
            ? tr("The end time must be after the start time.")
            : minutes > 0
              ? tr("Duration: {1}{2}", { 1: formatDuration(minutes), 2: tooLong ? ` — the maximum is ${formatDuration(MAX_MINUTES)}.` : '' })
              : tr("Choose the hours and minutes. Maximum {1}.", { 1: formatDuration(MAX_MINUTES) })}
        </p>
        <label>
          {tr("Reason")}{' '}<span className="muted small">{tr("(optional)")}</span>
          <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={1000} />
        </label>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !from || !to || tooLong || invalidRange}>
            {saving ? tr("Submitting…") : tr("Submit request")}
          </button>
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            {tr("Cancel")}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function DecisionForm({ decision, onClose, onDone }: { decision: Decision; onClose: () => void; onDone: () => void }) {
  const { item, action } = decision
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    try {
      if (action === 'approve') await api.shortLeaves.approve(item.id, note || undefined)
      else await api.shortLeaves.reject(item.id, note || undefined)
      onDone()
    } catch (e) {
      setError(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={action === 'approve' ? tr("Approve permission") : tr("Reject permission")} onClose={onClose}>
      <form className="form" onSubmit={submit}>
        <div className="summary">
          <div className="strong">{item.employee.full_name}</div>
          <div className="muted">
            {formatDate(item.date)} · {formatTime(item.start_time)} → {formatTime(item.end_time)} · {formatDuration(item.minutes)}
          </div>
          {item.reason && <div className="muted small">“{item.reason}”</div>}
        </div>
        <label>
          {tr("Note to the employee")}{' '}<span className="muted small">{tr("(optional)")}</span>
          <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={1000} autoFocus />
        </label>
        <ErrorText message={error} />
        <div className="form-actions">
          <button type="submit" className={action === 'reject' ? 'danger' : ''} disabled={saving}>
            {saving ? tr("Saving…") : action === 'approve' ? tr("Approve") : tr("Reject")}
          </button>
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            {tr("Cancel")}
          </button>
        </div>
      </form>
    </Modal>
  )
}
