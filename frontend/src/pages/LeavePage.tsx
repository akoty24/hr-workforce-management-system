import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import { managesHr, type Employee, type LeaveBalance, type LeaveRequest, type LeaveStatus, type LeaveType, type User } from '../types'
import { ErrorText, FieldError, Modal, PageHead, Person, StatusBadge } from '../components/ui'
import { dateRange, formatDate, localToday } from '../format'
import { Balances } from './DashboardPage'
import { tr } from '../i18n'

const FILTERS: { value: LeaveStatus | ''; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: '', label: 'All' },
]

type Decision = { request: LeaveRequest; action: 'approve' | 'reject' }

export function LeavePage({ user }: { user: User }) {
  const isAdmin = managesHr(user)
  const isLeader = user.role === 'team_leader'
  // Admins and team leaders decide requests of others (never their own).
  const canDecide = (r: LeaveRequest) => (isAdmin || isLeader) && r.employee.id !== user.employee?.id
  const isOwn = (r: LeaveRequest) => r.employee.id === user.employee?.id
  const [status, setStatus] = useState<LeaveStatus | ''>(isAdmin || isLeader ? 'pending' : '')
  const [requests, setRequests] = useState<LeaveRequest[] | null>(null)
  const [balances, setBalances] = useState<LeaveBalance[]>([])
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [deciding, setDeciding] = useState<Decision | null>(null)

  const load = useCallback(async () => {
    try {
      const [list, own] = await Promise.all([
        api.leaveRequests.list({ status }),
        user.employee ? api.leaveBalances() : Promise.resolve([]),
      ])
      setRequests(list)
      setBalances(own)
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [status, user.employee])

  useEffect(() => {
    let active = true
    Promise.all([api.leaveRequests.list({ status }), user.employee ? api.leaveBalances() : Promise.resolve([])])
      .then(([list, own]) => {
        if (!active) return
        setRequests(list)
        setBalances(own)
      })
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [status, user.employee])

  async function cancel(request: LeaveRequest) {
    if (!window.confirm(tr("Cancel this leave request?"))) return
    try {
      await api.leaveRequests.cancel(request.id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  const canRequest = isAdmin || !!user.employee

  return (
    <section>
      <PageHead
        title={tr("Leave")}
        subtitle={
          isAdmin
            ? tr("Review and decide leave requests.")
            : isLeader
              ? tr("Your requests and those of your team. Your decision on your team’s requests is final.")
              : tr("Your leave balance and requests.")
        }
        actions={canRequest && <button onClick={() => setCreating(true)}>{tr("+ Request leave")}</button>}
      />

      {!canRequest && (
        <div className="card notice">{tr("Your account is not linked to an employee record yet. Ask an administrator.")}</div>
      )}

      {balances.length > 0 && <Balances balances={balances} />}

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
      {requests === null && !error && <p className="muted">{tr("Loading…")}</p>}

      {requests?.length === 0 && (
        <div className="card empty">
          <p>{tr("No requests here.")}</p>
        </div>
      )}

      {requests && requests.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                {(isAdmin || isLeader) && <th>{tr("Employee")}</th>}
                <th>{tr("Type")}</th>
                <th>{tr("Dates")}</th>
                <th className="num">{tr("Days")}</th>
                <th>{tr("Status")}</th>
                <th className="actions-col" aria-label={tr("Actions")} />
              </tr>
            </thead>
            <tbody>
              {requests.map((r) => (
                <tr key={r.id}>
                  {(isAdmin || isLeader) && (
                    <td>
                      <Person name={r.employee.full_name} detail={r.employee.employee_code} you={isOwn(r)} />
                    </td>
                  )}
                  <td>
                    {tr(r.leave_type.name)}
                    {r.reason && <div className="muted small cell-note">{r.reason}</div>}
                  </td>
                  <td>{dateRange(r.start_date, r.end_date)}</td>
                  <td className="num">{r.days}</td>
                  <td>
                    <StatusBadge status={r.status} />
                    {r.decision_note && <div className="muted small">“{r.decision_note}”</div>}
                    {r.decided_by && (
                      <div className="muted small">
                        {tr("by")}{' '}{r.decided_by}, {formatDate(r.decided_at)}
                      </div>
                    )}
                  </td>
                  <td className="actions">
                    {r.status === 'pending' && canDecide(r) && (
                      <>
                        <button className="link" onClick={() => setDeciding({ request: r, action: 'approve' })}>
                          {tr("Approve")}
                        </button>
                        <button className="link danger" onClick={() => setDeciding({ request: r, action: 'reject' })}>
                          {tr("Reject")}
                        </button>
                      </>
                    )}
                    {r.status === 'pending' && isOwn(r) && (
                      <button className="link danger" onClick={() => cancel(r)}>
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
        <RequestForm
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

function RequestForm({ user, onClose, onSaved }: { user: User; onClose: () => void; onSaved: () => void }) {
  const isAdmin = managesHr(user)
  const [today] = useState(localToday)
  const [types, setTypes] = useState<LeaveType[]>([])
  const [employees, setEmployees] = useState<Employee[]>([])
  const [employeeId, setEmployeeId] = useState(isAdmin ? '' : (user.employee?.id ?? ''))
  const [leaveTypeId, setLeaveTypeId] = useState('')
  const [start, setStart] = useState(today)
  const [end, setEnd] = useState(today)
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.leaveTypes
      .list()
      .then((list) => {
        const active = list.filter((t) => t.is_active)
        setTypes(active)
        if (active[0]) setLeaveTypeId(active[0].id)
      })
      .catch((e) => setGeneral(errorMessage(e)))
    if (isAdmin) {
      api.employees
        .list({ status: 'active' })
        .then((r) => setEmployees(r.data))
        .catch(() => {})
    }
  }, [isAdmin])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    try {
      await api.leaveRequests.create({
        employee_id: isAdmin ? employeeId || undefined : undefined,
        leave_type_id: leaveTypeId,
        start_date: start,
        end_date: end,
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
    <Modal title={tr("Request leave")} onClose={onClose}>
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
          {tr("Leave type")}
          <select value={leaveTypeId} onChange={(e) => setLeaveTypeId(e.target.value)}>
            {types.map((t) => (
              <option key={t.id} value={t.id}>
                {tr(t.name)}
                {t.days_per_year !== null ? tr("({1} days/year)", { 1: t.days_per_year }) : ''}
              </option>
            ))}
          </select>
          <FieldError message={errors?.field('leave_type_id')} />
        </label>
        <div className="form-grid">
          <label>
            {tr("From")}
            <input type="date" value={start} min={isAdmin ? undefined : today} onChange={(e) => {
              setStart(e.target.value)
              if (e.target.value > end) setEnd(e.target.value)
            }} />
            <FieldError message={errors?.field('start_date')} />
          </label>
          <label>
            {tr("To")}
            <input type="date" value={end} min={start} onChange={(e) => setEnd(e.target.value)} />
            <FieldError message={errors?.field('end_date')} />
          </label>
        </div>
        <p className="muted small">{tr("Weekends are not counted. The number of working days is calculated for you.")}</p>
        <label>
          {tr("Reason")}{' '}<span className="muted small">{tr("(optional)")}</span>
          <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={1000} />
        </label>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !leaveTypeId}>
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
  const { request, action } = decision
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    try {
      if (action === 'approve') await api.leaveRequests.approve(request.id, note || undefined)
      else await api.leaveRequests.reject(request.id, note || undefined)
      onDone()
    } catch (e) {
      setError(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={action === 'approve' ? tr("Approve request") : tr("Reject request")} onClose={onClose}>
      <form className="form" onSubmit={submit}>
        <div className="summary">
          <div className="strong">{request.employee.full_name}</div>
          <div className="muted">
            {tr(request.leave_type.name)} · {dateRange(request.start_date, request.end_date)} · {request.days} {tr("working day")}
            {request.days === 1 ? '' : tr("s")}
          </div>
          {request.reason && <div className="muted small">“{request.reason}”</div>}
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
