import { useEffect, useState } from 'react'
import { api, errorMessage } from '../api'
import type { Department, Report, ReportType } from '../types'
import { Icon } from '../components/icons'
import { ErrorText, PageHead } from '../components/ui'
import { localToday } from '../format'
import { tr } from '../i18n'

const TYPES: { value: ReportType; label: string; hint: string }[] = [
  { value: 'summary', label: 'Summary', hint: 'Per employee: days attended, lateness, leave and permissions.' },
  { value: 'attendance', label: 'Attendance', hint: 'Every check-in and check-out with location links.' },
  { value: 'leave', label: 'Leave', hint: 'Leave requests overlapping the period.' },
  { value: 'permissions', label: 'Permissions', hint: 'Hourly permissions in the period.' },
  { value: 'salaries', label: 'Salaries', hint: 'Salary in force on the “To” date.' },
]

export function ReportsPage() {
  const [type, setType] = useState<ReportType>('summary')
  const [from, setFrom] = useState(() => `${localToday().slice(0, 8)}01`)
  const [to, setTo] = useState(localToday)
  const [departmentId, setDepartmentId] = useState('')
  const [departments, setDepartments] = useState<Department[]>([])
  const [report, setReport] = useState<Report | null>(null)
  const [loadedKey, setLoadedKey] = useState('')
  const [error, setError] = useState<string | null>(null)
  const key = `${type}|${from}|${to}|${departmentId}`
  const loading = key !== loadedKey

  useEffect(() => {
    api.departments.list().then(setDepartments).catch(() => {})
  }, [])

  useEffect(() => {
    if (!from || !to || from > to) return
    let active = true
    const requested = `${type}|${from}|${to}|${departmentId}`
    api.reports
      .get(type, { from, to, department_id: departmentId })
      .then((r) => {
        if (!active) return
        setReport(r)
        setError(null)
        setLoadedKey(requested)
      })
      .catch((e) => {
        if (!active) return
        setError(errorMessage(e))
        setLoadedKey(requested)
      })
    return () => {
      active = false
    }
  }, [type, from, to, departmentId])

  const current = TYPES.find((t) => t.value === type)!

  return (
    <section>
      <PageHead
        title={tr("Reports")}
        subtitle={tr(current.hint)}
        actions={
          <a className="button" href={api.reports.downloadUrl(type, { from, to, department_id: departmentId })}>
            <Icon name="download" size={16} /> {tr("Export to Excel")}
          </a>
        }
      />

      <div className="tabs" role="tablist">
        {TYPES.map((t) => (
          <button
            key={t.value}
            role="tab"
            aria-selected={type === t.value}
            className={`tab ${type === t.value ? 'active' : ''}`}
            onClick={() => setType(t.value)}
          >
            {tr(t.label)}
          </button>
        ))}
      </div>

      <div className="filters">
        <label className="inline">
          {tr("From")}{' '}<input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label className="inline">
          {tr("To")}{' '}<input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        </label>
        <select value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
          <option value="">{tr("All departments")}</option>
          {departments.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </select>
      </div>

      <ErrorText message={error} />
      {loading && !report && <p className="muted">{tr("Loading…")}</p>}

      {report && report.rows.length === 0 && (
        <div className="card empty">
          <p>{tr("No data for this period.")}</p>
        </div>
      )}

      {report && report.rows.length > 0 && (
        <div className={`card table-card table-scroll ${loading ? 'loading' : ''}`}>
          <table>
            <thead>
              <tr>
                {report.headers.map((h) => (
                  <th key={h}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {report.rows.map((row, i) => (
                <tr key={i}>
                  {row.map((cell, j) => (
                    <td key={j}>
                      {typeof cell === 'string' && cell.startsWith('https://') ? (
                        <a className="map-link" href={cell} target="_blank" rel="noreferrer noopener">
                          <Icon name="pin" size={14} /> {tr("Map")}
                        </a>
                      ) : (
                        (cell ?? '—')
                      )}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
          <div className="table-foot muted small">
            <span>
              {report.rows.length} {tr("row")}{report.rows.length === 1 ? '' : tr("s")}
            </span>
          </div>
        </div>
      )}
    </section>
  )
}
