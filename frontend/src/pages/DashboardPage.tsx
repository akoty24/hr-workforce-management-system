import { useEffect, useState } from 'react'
import { api, errorMessage } from '../api'
import type { Dashboard, EmployeeDocument, LeaveBalance, LeaveRequest, UpcomingHoliday, User } from '../types'
import { Link } from '../router'
import { Icon } from '../components/icons'
import { ErrorText, PageHead, Person, StatusBadge } from '../components/ui'
import { dateRange, formatDate } from '../format'
import { tr } from '../i18n'

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

  const greeting = tr('Welcome, {1}', { 1: user.name.split(' ')[0] })

  if (error) return <ErrorText message={error} />
  if (!dashboard) return <p className="muted">{tr("Loading…")}</p>

  if (dashboard.role === 'overview') {
    const s = dashboard.stats
    return (
      <>
        <PageHead title={greeting} subtitle={tr("Here is what is happening in your company today.")} />
        <div className="stats">
          <Stat label={tr("Active employees")} value={s.employees_active} hint={tr("{1} total", { 1: s.employees_total })} to="/employees" />
          <Stat label={tr("Departments")} value={s.departments} to="/departments" />
          <Stat label={tr("Pending leave requests")} value={s.pending_requests} highlight={s.pending_requests > 0} to="/leave" />
          <Stat label={tr("Pending permissions")} value={s.pending_permissions} highlight={s.pending_permissions > 0} to="/permissions" />
          <Stat label={tr("On leave today")} value={s.on_leave_today} to="/leave" />
        </div>
        <div className="grid-2">
          <RequestList title={tr("Waiting for your decision")} requests={dashboard.pending_requests} empty={tr("No pending requests.")} />
          <RequestList title={tr("On leave today")} requests={dashboard.on_leave_today} empty={tr("Everyone is in today.")} />
        </div>
        <ExpiringDocuments />
        <Holidays holidays={dashboard.upcoming_holidays} />
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
        actions={<Link to="/leave" className="button">{tr("Request leave")}</Link>}
      />
      {!dashboard.employee && (
        <div className="card notice">{tr("Your account is not linked to an employee record yet. Ask an administrator.")}</div>
      )}

      {dashboard.team && (
        <>
          <h2 className="section-title">{tr("My team")}</h2>
          <div className="stats">
            <Stat label={tr("Team members")} value={dashboard.team.size} to="/employees" />
            <Stat
              label={tr("Waiting for your decision")}
              value={dashboard.team.pending_requests.length}
              highlight={dashboard.team.pending_requests.length > 0}
              to="/leave"
            />
            <Stat
              label={tr("Permissions to decide")}
              value={dashboard.team.pending_permissions}
              highlight={dashboard.team.pending_permissions > 0}
              to="/permissions"
            />
            <Stat label={tr("On leave today")} value={dashboard.team.on_leave_today.length} to="/leave" />
          </div>
          <div className="grid-2 section">
            <RequestList title={tr("Team requests to decide")} requests={dashboard.team.pending_requests} empty={tr("Nothing waiting for you.")} />
            <RequestList title={tr("Team on leave today")} requests={dashboard.team.on_leave_today} empty={tr("Your whole team is in today.")} />
          </div>
          <h2 className="section-title">{tr("My leave")}</h2>
        </>
      )}

      {dashboard.balances.length > 0 && <Balances balances={dashboard.balances} />}
      <RequestList title={tr("My recent requests")} requests={dashboard.recent_requests} empty={tr("You have not requested leave yet.")} own />
      <Holidays holidays={dashboard.upcoming_holidays} />
    </>
  )
}

/** Admins/HR: documents expired or expiring within 30 days. */
function ExpiringDocuments() {
  const [docs, setDocs] = useState<EmployeeDocument[]>([])

  useEffect(() => {
    let active = true
    api.documents
      .expiring()
      .then((d) => active && setDocs(d))
      .catch(() => {})
    return () => {
      active = false
    }
  }, [])

  if (docs.length === 0) return null
  return (
    <div className="alert alert-warning" role="note">
      <span className="alert-icon" aria-hidden>
        <Icon name="file" size={20} />
      </span>
      <div className="alert-body">
        <div className="alert-title">{tr("Documents expiring soon")}</div>
        <ul className="alert-list">
          {docs.map((d) => (
            <li key={d.id}>
              <span>
                <span className="strong">{d.employee?.full_name}</span> · {d.title}
              </span>
              <span className="alert-date">
                {d.status === 'expired' ? tr("Expired") : tr("Expires in {1} days", { 1: d.days_left })} · {formatDate(d.expires_at)}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function Holidays({ holidays }: { holidays: UpcomingHoliday[] }) {
  if (holidays.length === 0) return null
  return (
    <div className="alert alert-holiday" role="note">
      <span className="alert-icon" aria-hidden>
        <Icon name="sun" size={20} />
      </span>
      <div className="alert-body">
        <div className="alert-title">{tr("Upcoming public holidays")}</div>
        <ul className="alert-list">
          {holidays.map((h) => (
            <li key={`${h.name}-${h.start_date}`}>
              <span className="strong">{h.name}</span>
              <span className="alert-date">{dateRange(h.start_date, h.end_date)}</span>
            </li>
          ))}
        </ul>
      </div>
    </div>
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
          <div className="stat-label">{tr("{1} remaining", { 1: tr(b.leave_type.name) })}</div>
          <div className="muted small">
            {b.allowance === null
              ? tr("No annual limit")
              : b.allowance !== b.full_allowance
                ? tr("{1} this year (prorated from {2})", { 1: b.allowance, 2: b.full_allowance })
                : tr("{1} per year", { 1: b.allowance })}{' '}
            · {b.approved} {tr("taken")}
            {b.pending > 0 && tr("· {1} pending", { 1: b.pending })}
          </div>
        </div>
      ))}
    </div>
  )
}

function RequestList({
  title,
  requests,
  empty,
  own = false,
}: {
  title: string
  requests: LeaveRequest[]
  empty: string
  /** The viewer's own requests: no need to repeat their name and photo. */
  own?: boolean
}) {
  return (
    <div className="card">
      <div className="card-head">
        <h2>{title}</h2>
        {requests.length > 0 && (
          <Link to="/leave" className="small">
            {tr("Open leave →")}
          </Link>
        )}
      </div>
      {requests.length === 0 ? (
        <p className="muted">{empty}</p>
      ) : (
        <ul className="list">
          {requests.map((r) => (
            <li key={r.id}>
              {own ? (
                <div>
                  <div className="strong">{tr(r.leave_type.name)}</div>
                  <div className="muted small">
                    {dateRange(r.start_date, r.end_date)} · {r.days} {tr("day")}{r.days === 1 ? '' : tr("s")}
                  </div>
                </div>
              ) : (
                <Person
                  name={r.employee.full_name}
                  detail={tr("{1} · {2} · {3} day{4}", { 1: tr(r.leave_type.name), 2: dateRange(r.start_date, r.end_date), 3: r.days, 4: r.days === 1 ? '' : 's' })}
                />
              )}
              <StatusBadge status={r.status} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
