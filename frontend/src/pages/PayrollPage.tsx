import { useCallback, useEffect, useState } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { PayrollRun, PayrollRunDetail, Payslip, PayslipItem, PayslipItemType } from '../types'
import { Icon } from '../components/icons'
import { ErrorText, Modal, PageHead, Person } from '../components/ui'
import { PayslipView } from '../components/PayslipView'
import { formatMoney, localToday, monthName } from '../format'
import { tr } from '../i18n'

/** Totals per currency (salaries may be in different currencies). */
function totals(slips: Payslip[], field: 'gross' | 'net'): string {
  const sums = new Map<string, number>()
  for (const s of slips) sums.set(s.currency, (sums.get(s.currency) ?? 0) + s[field])
  return [...sums].map(([currency, sum]) => formatMoney(sum, currency)).join(' + ') || '—'
}

export function PayrollPage() {
  const [runs, setRuns] = useState<PayrollRun[] | null>(null)
  const [openId, setOpenId] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [period, setPeriod] = useState(() => localToday().slice(0, 7))
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setRuns(await api.payroll.list())
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [])

  useEffect(() => {
    let active = true
    api.payroll
      .list()
      .then((r) => active && setRuns(r))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [])

  async function create() {
    const [year, month] = period.split('-').map(Number)
    setBusy(true)
    setError(null)
    try {
      const run = await api.payroll.create(year, month)
      setCreating(false)
      await load()
      setOpenId(run.id)
    } catch (e) {
      setError(e instanceof ApiError ? (e.field('month') ?? e.message) : errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  if (openId) {
    return (
      <RunDetail
        id={openId}
        onBack={() => {
          setOpenId(null)
          load()
        }}
      />
    )
  }

  return (
    <section>
      <PageHead
        title={tr("Payroll")}
        subtitle={tr("Monthly salaries calculated from attendance, leave, lateness and overtime.")}
        actions={
          <button onClick={() => setCreating(true)}>
            <Icon name="plus" size={16} /> {tr("New payroll")}
          </button>
        }
      />
      <ErrorText message={error} />

      {creating && (
        <div className="card form inline-form">
          <label className="inline">
            {tr("Month")}{' '}<input type="month" value={period} onChange={(e) => setPeriod(e.target.value)} />
          </label>
          <button onClick={create} disabled={busy || !period}>
            {busy ? tr("Calculating…") : tr("Calculate payroll")}
          </button>
          <button className="secondary" onClick={() => setCreating(false)}>
            {tr("Cancel")}
          </button>
          <span className="muted small">{tr("Everyone with a salary is included. You can review and adjust before finalizing.")}</span>
        </div>
      )}

      {runs === null && !error && <p className="muted">{tr("Loading…")}</p>}
      {runs?.length === 0 && (
        <div className="card empty">
          <p>{tr("No payroll yet. Press “New payroll” to calculate this month's salaries.")}</p>
        </div>
      )}
      {runs && runs.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>{tr("Month")}</th>
                <th>{tr("Employees")}</th>
                <th className="num">{tr("Gross")}</th>
                <th className="num">{tr("Net")}</th>
                <th>{tr("Status")}</th>
                <th className="actions-col" aria-label={tr("Actions")} />
              </tr>
            </thead>
            <tbody>
              {runs.map((r) => (
                <tr key={r.id}>
                  <td className="strong">
                    {monthName(r.month)} {r.year}
                  </td>
                  <td>{r.payslips_count}</td>
                  <td className="num">{r.total_gross.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td className="num">{r.total_net.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td>
                    {r.status === 'finalized' ? (
                      <span className="badge badge-approved">{tr("Finalized")}</span>
                    ) : (
                      <span className="badge badge-pending">{tr("Draft")}</span>
                    )}
                  </td>
                  <td className="actions">
                    <button className="link" onClick={() => setOpenId(r.id)}>
                      {tr("Open")}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

function RunDetail({ id, onBack }: { id: string; onBack: () => void }) {
  const [run, setRun] = useState<PayrollRunDetail | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState<Payslip | null>(null)

  useEffect(() => {
    let active = true
    api.payroll
      .get(id)
      .then((r) => active && setRun(r))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [id])

  async function act(action: 'recalculate' | 'finalize' | 'remove') {
    if (action === 'finalize' && !window.confirm(tr("Finalize this payroll? It can no longer be changed, and employees can see their payslips."))) return
    if (action === 'remove' && !window.confirm(tr("Delete this draft payroll?"))) return
    setBusy(true)
    setError(null)
    try {
      if (action === 'remove') {
        await api.payroll.remove(id)
        onBack()
        return
      }
      setRun(action === 'finalize' ? await api.payroll.finalize(id) : await api.payroll.recalculate(id))
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const draft = run?.status === 'draft'

  return (
    <section>
      <button className="link back-link" onClick={onBack}>
        {tr("← All payrolls")}
      </button>
      <PageHead
        title={run ? tr("Payroll — {1} {2}", { 1: monthName(run.month), 2: run.year }) : tr("Payroll")}
        subtitle={
          run
            ? draft
              ? tr("Draft: review each payslip, add allowances or deductions, then finalize.")
              : tr("Finalized: locked, and employees can see their payslips.")
            : undefined
        }
        actions={
          run && (
            <div className="head-actions">
              <a className="button secondary" href={api.payroll.exportUrl(id)}>
                <Icon name="download" size={16} /> {tr("Excel")}
              </a>
              {draft && (
                <>
                  <button className="secondary" disabled={busy} onClick={() => act('recalculate')}>
                    {tr("Recalculate")}
                  </button>
                  <button disabled={busy} onClick={() => act('finalize')}>
                    {tr("Finalize")}
                  </button>
                </>
              )}
            </div>
          )
        }
      />
      <ErrorText message={error} />
      {!run && !error && <p className="muted">{tr("Loading…")}</p>}

      {run && (
        <>
          <div className="stats">
            <div className="stat">
              <div className="stat-value">{run.payslips.length}</div>
              <div className="stat-label">{tr("Employees")}</div>
            </div>
            <div className="stat">
              <div className="stat-value stat-money">{totals(run.payslips, 'gross')}</div>
              <div className="stat-label">{tr("Total gross")}</div>
            </div>
            <div className="stat">
              <div className="stat-value stat-money">{totals(run.payslips, 'net')}</div>
              <div className="stat-label">{tr("Total net")}</div>
            </div>
          </div>

          {run.payslips.length === 0 ? (
            <div className="card empty">
              <p>{tr("Nobody has a salary for this month. Add salaries from the Employees page, then recalculate.")}</p>
            </div>
          ) : (
            <div className="card table-card">
              <table>
                <thead>
                  <tr>
                    <th>{tr("Employee")}</th>
                    <th className="num">{tr("Salary")}</th>
                    <th className="num">{tr("Deductions")}</th>
                    <th className="num">{tr("Additions")}</th>
                    <th className="num">{tr("Gross")}</th>
                    <th className="num">{tr("Insurance & tax")}</th>
                    <th className="num">{tr("Net")}</th>
                    <th className="actions-col" aria-label={tr("Actions")} />
                  </tr>
                </thead>
                <tbody>
                  {run.payslips.map((p) => {
                    const deductions = p.absence_deduction + p.unpaid_leave_deduction + p.late_deduction + p.other_deductions
                    const money = (n: number) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    return (
                      <tr key={p.id}>
                        <td>
                          <Person name={p.employee.full_name} detail={`${p.employee.employee_code} · ${p.currency}`} />
                        </td>
                        <td className="num">{money(p.base_salary)}</td>
                        <td className="num minus-text">{deductions > 0 ? `− ${money(deductions)}` : '—'}</td>
                        <td className="num plus-text">{p.additions + p.overtime_pay > 0 ? `+ ${money(p.additions + p.overtime_pay)}` : '—'}</td>
                        <td className="num">{money(p.gross)}</td>
                        <td className="num minus-text">− {money(p.social_insurance + p.income_tax)}</td>
                        <td className="num strong">{money(p.net)}</td>
                        <td className="actions">
                          <button className="link" onClick={() => setOpen(p)}>
                            {draft ? tr("Review") : tr("View")}
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          {draft && (
            <button className="link danger" disabled={busy} onClick={() => act('remove')}>
              {tr("Delete this draft")}
            </button>
          )}
        </>
      )}

      {open && run && (
        <PayslipDialog
          slip={open}
          editable={draft}
          onClose={() => setOpen(null)}
          onSaved={(slip) => {
            setOpen(slip)
            setRun({ ...run, payslips: run.payslips.map((p) => (p.id === slip.id ? { ...slip, period: p.period } : p)) })
          }}
        />
      )}
    </section>
  )
}

function PayslipDialog({
  slip,
  editable,
  onClose,
  onSaved,
}: {
  slip: Payslip
  editable: boolean
  onClose: () => void
  onSaved: (slip: Payslip) => void
}) {
  const [items, setItems] = useState<PayslipItem[]>(slip.items)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const change = (index: number, patch: Partial<PayslipItem>) => setItems((list) => list.map((item, i) => (i === index ? { ...item, ...patch } : item)))

  async function save() {
    setSaving(true)
    setError(null)
    try {
      onSaved(await api.payroll.updatePayslip(slip.id, items.filter((i) => i.label.trim() && i.amount > 0)))
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={tr("Payslip — {1}", { 1: slip.employee.full_name })} onClose={onClose} wide>
      <div className="form">
        <PayslipView slip={slip} />

        {editable && (
          <div className="signin-box">
            <div>
              <strong>{tr("Allowances, bonuses and deductions")}</strong>
              <div className="muted small">{tr("For this month only, e.g. transport allowance, sales bonus, loan installment.")}</div>
            </div>
            {items.map((item, i) => (
              <div key={i} className="item-row">
                <select value={item.type} onChange={(e) => change(i, { type: e.target.value as PayslipItemType })}>
                  <option value="allowance">{tr("Allowance")}</option>
                  <option value="bonus">{tr("Bonus")}</option>
                  <option value="deduction">{tr("Deduction")}</option>
                </select>
                <input value={tr(item.label)} placeholder={tr("Description")} maxLength={100} onChange={(e) => change(i, { label: e.target.value })} />
                <input
                  type="number"
                  min={0}
                  step="0.01"
                  value={item.amount || ''}
                  placeholder={tr("Amount")}
                  onChange={(e) => change(i, { amount: Number(e.target.value) })}
                />
                <button className="icon" aria-label={tr("Remove")} onClick={() => setItems((list) => list.filter((_, n) => n !== i))}>
                  <Icon name="x" size={16} />
                </button>
              </div>
            ))}
            <div className="form-actions">
              <button className="secondary" onClick={() => setItems((list) => [...list, { type: 'allowance', label: '', amount: 0 }])}>
                <Icon name="plus" size={14} /> {tr("Add line")}
              </button>
              <button onClick={save} disabled={saving}>
                {saving ? tr("Saving…") : tr("Save and recalculate")}
              </button>
            </div>
            <ErrorText message={error} />
          </div>
        )}

        <div className="form-actions">
          <button className="secondary" onClick={() => window.print()}>
            {tr("Print")}
          </button>
          <button className="secondary" onClick={onClose}>
            {tr("Close")}
          </button>
        </div>
      </div>
    </Modal>
  )
}
