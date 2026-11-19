import { useEffect, type ReactNode } from 'react'
import type { EmployeeStatus, LeaveStatus } from '../types'
import { Icon } from './icons'
import { lang, setLang, tr } from '../i18n'

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
          <button className="icon" onClick={onClose} aria-label={tr("Close")}>
            <Icon name="x" />
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
  return <span className={`badge badge-${status}`}>{tr(statusLabels[status])}</span>
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
      {tr(message)}
    </p>
  )
}

export function FieldError({ message }: { message?: string }) {
  return message ? <span className="field-error">{tr(message)}</span> : null
}

/** English ⇄ العربية. */
export function LanguageSwitch({ className = '' }: { className?: string }) {
  const next = lang() === 'ar' ? 'en' : 'ar'
  return (
    <button type="button" className={`ghost lang-switch ${className}`} onClick={() => setLang(next)} lang={next}>
      <Icon name="globe" size={16} /> {next === 'ar' ? 'العربية' : 'English'}
    </button>
  )
}


/** Initials in a coloured circle; the colour is stable per name. */
export function Avatar({ name, size = 32 }: { name: string; size?: number }) {
  const parts = name.trim().split(/\s+/)
  const initials = ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : '')).toUpperCase()
  let hash = 0
  for (const ch of name) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0
  return (
    <span className={`avatar avatar-${hash % 6}`} style={{ width: size, height: size, fontSize: size * 0.4 }} aria-hidden="true">
      {initials || '?'}
    </span>
  )
}

/** Name with avatar and a secondary line (code, title...). */
export function Person({ name, detail, you = false }: { name: string; detail?: ReactNode; you?: boolean }) {
  return (
    <div className="person">
      <Avatar name={name} />
      <div className="person-text">
        <div className="strong">
          {name} {you && <span className="you">{tr("You")}</span>}
        </div>
        {detail && <div className="muted small">{detail}</div>}
      </div>
    </div>
  )
}
