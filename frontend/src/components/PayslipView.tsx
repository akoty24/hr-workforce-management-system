import type { Payslip } from '../types'
import { formatDuration, formatMoney, monthName } from '../format'
import { tr } from '../i18n'

/** Printable breakdown of one payslip. */
export function PayslipView({ slip, company }: { slip: Payslip; company?: string }) {
  const money = (n: number) => formatMoney(n, slip.currency)
  const additions = slip.items.filter((i) => i.type !== 'deduction')
  const deductions = slip.items.filter((i) => i.type === 'deduction')

  return (
    <div className="payslip">
      <div className="payslip-head">
        <div>
          <div className="strong">{slip.employee.full_name}</div>
          <div className="muted small">{slip.employee.employee_code}</div>
        </div>
        <div className="payslip-period">
          {company && <div className="muted small">{company}</div>}
          {slip.period && (
            <div className="strong">
              {monthName(slip.period.month)} {slip.period.year}
            </div>
          )}
        </div>
      </div>

      <div className="payslip-facts muted small">
        <span>
          {tr("Working days:")}{' '}<strong>{slip.working_days}</strong>
        </span>
        <span>
          {tr("Absent:")}{' '}<strong>{slip.absent_days}</strong>
        </span>
        <span>
          {tr("Unpaid leave:")}{' '}<strong>{slip.unpaid_leave_days}</strong>
        </span>
        <span>
          {tr("Late:")}{' '}<strong>{formatDuration(slip.late_minutes)}</strong>
        </span>
        <span>
          {tr("Overtime:")}{' '}<strong>{formatDuration(slip.overtime_minutes)}</strong>
        </span>
      </div>

      <table className="payslip-table">
        <tbody>
          <tr>
            <td>{tr("Basic salary")}</td>
            <td className="num">{money(slip.base_salary)}</td>
          </tr>
          {slip.overtime_pay > 0 && (
            <tr className="plus">
              <td>{tr("Overtime")}</td>
              <td className="num">+ {money(slip.overtime_pay)}</td>
            </tr>
          )}
          {additions.map((i, n) => (
            <tr key={`a${n}`} className="plus">
              <td>{tr(i.label)}</td>
              <td className="num">+ {money(i.amount)}</td>
            </tr>
          ))}
          {slip.absence_deduction > 0 && (
            <tr className="minus">
              <td>{tr("Absence (")}{slip.absent_days} {tr("days)")}</td>
              <td className="num">− {money(slip.absence_deduction)}</td>
            </tr>
          )}
          {slip.unpaid_leave_deduction > 0 && (
            <tr className="minus">
              <td>{tr("Unpaid leave (")}{slip.unpaid_leave_days} {tr("days)")}</td>
              <td className="num">− {money(slip.unpaid_leave_deduction)}</td>
            </tr>
          )}
          {slip.late_deduction > 0 && (
            <tr className="minus">
              <td>{tr("Lateness")}</td>
              <td className="num">− {money(slip.late_deduction)}</td>
            </tr>
          )}
          <tr className="subtotal">
            <td>{tr("Gross pay")}</td>
            <td className="num">{money(slip.gross)}</td>
          </tr>
          {slip.social_insurance > 0 && (
            <tr className="minus">
              <td>{tr("Social insurance")}</td>
              <td className="num">− {money(slip.social_insurance)}</td>
            </tr>
          )}
          {slip.income_tax > 0 && (
            <tr className="minus">
              <td>{tr("Income tax")}</td>
              <td className="num">− {money(slip.income_tax)}</td>
            </tr>
          )}
          {deductions.map((i, n) => (
            <tr key={`d${n}`} className="minus">
              <td>{tr(i.label)}</td>
              <td className="num">− {money(i.amount)}</td>
            </tr>
          ))}
          <tr className="total">
            <td>{tr("Net pay")}</td>
            <td className="num">{money(slip.net)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  )
}
