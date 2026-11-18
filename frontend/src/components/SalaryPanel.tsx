import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { SalaryHistory } from '../types'
import { ErrorText, FieldError } from './ui'
import { formatDate, formatMoney, localToday } from '../format'

/** Salary history of one employee; HR/admin may add and delete entries. */
export function SalaryPanel({ employeeId, canEdit }: { employeeId: string; canEdit: boolean }) {
  const [data, setData] = useState<SalaryHistory | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [amount, setAmount] = useState('')
  const [currency, setCurrency] = useState('EGP')
  const [effective, setEffective] = useState(localToday)
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    try {
      setData(await api.salaries.list(employeeId))
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [employeeId])

  useEffect(() => {
    let active = true
    api.salaries
      .list(employeeId)
      .then((d) => {
        if (!active) return
        setData(d)
        if (d.current) setCurrency(d.current.currency)
      })
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [employeeId])

  async function add(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    try {
      await api.salaries.create(employeeId, { amount: Number(amount), currency, effective_from: effective, note: note || null })
      setAmount('')
      setNote('')
      await load()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  async function remove(id: string) {
    if (!window.confirm('Delete this salary entry?')) return
    try {
      await api.salaries.remove(id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  return (
    <div className="form">
      <ErrorText message={error} />
      {data === null && !error && <p className="muted">Loading…</p>}
      {data && (
        <div className="summary">
          <div className="muted small">Current salary</div>
          <div className="stat-value">{data.current ? formatMoney(data.current.amount, data.current.currency) : '—'}</div>
          {data.current && <div className="muted small">since {formatDate(data.current.effective_from)}</div>}
        </div>
      )}

      {data && data.history.length > 0 && (
        <table className="compact">
          <thead>
            <tr>
              <th>Effective from</th>
              <th>Amount</th>
              <th>Note</th>
              {canEdit && <th aria-label="Actions" />}
            </tr>
          </thead>
          <tbody>
            {data.history.map((s) => (
              <tr key={s.id}>
                <td>{formatDate(s.effective_from)}</td>
                <td className="strong">{formatMoney(s.amount, s.currency)}</td>
                <td className="muted small">
                  {s.note}
                  {s.created_by && <div>by {s.created_by}</div>}
                </td>
                {canEdit && (
                  <td className="actions">
                    <button className="link danger" onClick={() => remove(s.id)}>
                      Delete
                    </button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {canEdit && (
        <form className="form" onSubmit={add} noValidate>
          <h3>New salary / raise</h3>
          <div className="form-grid">
            <label>
              Monthly amount
              <input type="number" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
              <FieldError message={errors?.field('amount')} />
            </label>
            <label>
              Currency
              <input value={currency} maxLength={3} onChange={(e) => setCurrency(e.target.value.toUpperCase())} />
              <FieldError message={errors?.field('currency')} />
            </label>
            <label>
              Effective from
              <input type="date" value={effective} onChange={(e) => setEffective(e.target.value)} />
              <FieldError message={errors?.field('effective_from')} />
            </label>
            <label>
              Note <span className="muted small">(optional)</span>
              <input value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Annual raise" />
            </label>
          </div>
          <div className="form-actions">
            <button type="submit" disabled={saving || amount === ''}>
              {saving ? 'Saving…' : 'Add salary'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
