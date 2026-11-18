import { useEffect, useState } from 'react'
import { api, errorMessage } from '../api'
import type { Dashboard, LeaveBalance, LeaveRequest, User } from '../types'
import { Link } from '../router'
import { ErrorText, PageHead, StatusBadge } from '../components/ui'
import { dateRange } from '../format'

export function DashboardPage({ user }: { user: User }) {
  const [dashboard, setDashboard] = useState<Dashboard | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    api
      .dashboard()
      .then((d) => active && setDashboard(d))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [])

  const greeting = `Welcome, ${user.name.split(' ')[0]}`

  if (error) return <ErrorText message={error} />
  if (!dashboard) return <p className="muted">Loading…</p>

  if (dashboard.role === 'admin') {
    const s = dashboard.stats
    return (
      <>
        <PageHead title={greeting} subtitle="Here is what is happening in your company today." />
        <div className="stats">
          <Stat label="Active employees" value={s.employees_active} hint={`${s.employees_total} total`} to="/employees" />
          <Stat label="Departments" value={s.departments} to="/departments" />
          <Stat label="Pending leave requests" value={s.pending_requests} highlight={s.pending_requests > 0} to="/leave" />
          <Stat label="On leave today" value={s.on_leave_today} to="/leave" />
        </div>
        <div className="grid-2">
          <RequestList title="Waiting for your decision" requests={dashboard.pending_requests} empty="No pending requests." />
          <RequestList title="On leave today" requests={dashboard.on_leave_today} empty="Everyone is in today." />
        </div>
      </>
    )
  }

  return (
    <>
      <PageHead
        title={greeting}
        subtitle={
          dashboard.employee
            ? [
                dashboard.employee.job_title,
                dashboard.employee.department,
                dashboard.employee.manager && `Reports to ${dashboard.employee.manager}`,
              ]
                .filter(Boolean)
                .join(' · ')
            : undefined
        }
        actions={<Link to="/leave" className="button">Request leave</Link>}
      />
      {!dashboard.employee && (
        <div className="card notice">Your account is not linked to an employee record yet. Ask an administrator.</div>
      )}

      {dashboard.team && (
        <>
          <h2 className="section-title">My team</h2>
          <div className="stats">
            <Stat label="Team members" value={dashboard.team.size} to="/employees" />
            <Stat
              label="Waiting for your decision"
              value={dashboard.team.pending_requests.length}
              highlight={dashboard.team.pending_requests.length > 0}
              to="/leave"
            />
            <Stat label="On leave today" value={dashboard.team.on_leave_today.length} to="/leave" />
          </div>
          <div className="grid-2 section">
            <RequestList title="Team requests to decide" requests={dashboard.team.pending_requests} empty="Nothing waiting for you." />
            <RequestList title="Team on leave today" requests={dashboard.team.on_leave_today} empty="Your whole team is in today." />
          </div>
          <h2 className="section-title">My leave</h2>
        </>
      )}

      {dashboard.balances.length > 0 && <Balances balances={dashboard.balances} />}
      <RequestList title="My recent requests" requests={dashboard.recent_requests} empty="You have not requested leave yet." />
    </>
  )
}

function Stat({ label, value, hint, to, highlight }: { label: string; value: number; hint?: string; to: string; highlight?: boolean }) {
  return (
    <Link to={to} className={`card stat ${highlight ? 'highlight' : ''}`}>
      <div className="stat-value">{value}</div>
      <div className="stat-label">{label}</div>
      {hint && <div className="muted small">{hint}</div>}
    </Link>
  )
}

export function Balances({ balances }: { balances: LeaveBalance[] }) {
  return (
    <div className="stats">
      {balances.map((b) => (
        <div key={b.leave_type.id} className="card stat">
          <div className="stat-value">{b.remaining ?? '∞'}</div>
          <div className="stat-label">{b.leave_type.name} remaining</div>
          <div className="muted small">
            {b.allowance === null ? 'No annual limit' : `${b.allowance} per year`} · {b.approved} taken
            {b.pending > 0 && ` · ${b.pending} pending`}
          </div>
        </div>
      ))}
    </div>
  )
}

function RequestList({ title, requests, empty }: { title: string; requests: LeaveRequest[]; empty: string }) {
  return (
    <div className="card">
      <h2>{title}</h2>
      {requests.length === 0 ? (
        <p className="muted">{empty}</p>
      ) : (
        <ul className="list">
          {requests.map((r) => (
            <li key={r.id}>
              <div>
                <div className="strong">{r.employee.full_name}</div>
                <div className="muted small">
                  {r.leave_type.name} · {dateRange(r.start_date, r.end_date)} · {r.days} day{r.days === 1 ? '' : 's'}
                </div>
              </div>
              <StatusBadge status={r.status} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
