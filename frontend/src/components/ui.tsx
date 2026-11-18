import { useEffect, type ReactNode } from 'react'
import type { EmployeeStatus, LeaveStatus } from '../types'

/** Accessible modal dialog (Escape / backdrop click closes). */
export function Modal({
  title,
  onClose,
  children,
  wide = false,
}: {
  title: string
  onClose: () => void
  children: ReactNode
  /** Larger dialog for long forms (3-column grid on wide screens). */
  wide?: boolean
}) {
  useEffect(() => {
    const key = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', key)
    return () => window.removeEventListener('keydown', key)
  }, [onClose])

  return (
    <div className="backdrop" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`modal ${wide ? 'modal-wide' : ''}`} role="dialog" aria-modal="true" aria-label={title}>
        <div className="modal-head">
          <h2>{title}</h2>
          <button className="icon" onClick={onClose} aria-label="Close">
            ×
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}

const statusLabels: Record<EmployeeStatus | LeaveStatus, string> = {
  active: 'Active',
  on_leave: 'On leave',
  terminated: 'Terminated',
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
}

export function StatusBadge({ status }: { status: EmployeeStatus | LeaveStatus }) {
  return <span className={`badge badge-${status}`}>{statusLabels[status]}</span>
}

export function PageHead({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="page-head">
      <div>
        <h1>{title}</h1>
        {subtitle && <p className="muted">{subtitle}</p>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </div>
  )
}

export function ErrorText({ message }: { message?: string | null }) {
  if (!message) return null
  return (
    <p className="error" role="alert">
      {message}
    </p>
  )
}

export function FieldError({ message }: { message?: string }) {
  return message ? <span className="field-error">{message}</span> : null
}

