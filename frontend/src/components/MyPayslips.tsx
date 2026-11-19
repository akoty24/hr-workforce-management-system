import { useEffect, useState } from 'react'
import { api, errorMessage } from '../api'
import type { Payslip } from '../types'
import { ErrorText, Modal } from './ui'
import { PayslipView } from './PayslipView'
import { formatMoney, monthName } from '../format'
import { tr } from '../i18n'

/** The signed-in employee's finalized payslips. */
export function MyPayslips() {
  const [slips, setSlips] = useState<Payslip[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [open, setOpen] = useState<Payslip | null>(null)

  useEffect(() => {
    let active = true
    api.payroll
      .mine()
      .then((s) => active && setSlips(s))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [])

  return (
    <>
      <ErrorText message={error} />
      {slips === null && !error && <p className="muted">{tr("Loading…")}</p>}
      {slips?.length === 0 && <p className="muted">{tr("No payslips yet. They appear here once HR finalizes the month's payroll.")}</p>}
      {slips && slips.length > 0 && (
        <ul className="list">
          {slips.map((s) => (
            <li key={s.id}>
              <div>
                <div className="strong">{s.period ? `${monthName(s.period.month)} ${s.period.year}` : ''}</div>
                <div className="muted small">{tr("Net pay")}{' '}{formatMoney(s.net, s.currency)}</div>
              </div>
              <button className="link" onClick={() => setOpen(s)}>
                {tr("View")}
              </button>
            </li>
          ))}
        </ul>
      )}
      {open && (
        <Modal title={tr("Payslip")} onClose={() => setOpen(null)} wide>
          <div className="form">
            <PayslipView slip={open} />
            <div className="form-actions">
              <button className="secondary" onClick={() => window.print()}>
                {tr("Print")}
              </button>
              <button className="secondary" onClick={() => setOpen(null)}>
                {tr("Close")}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </>
  )
}
