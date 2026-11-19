import { useState, type FormEvent, type ReactNode } from 'react'
import { ApiError, api } from '../api'
import type { Tenant } from '../types'
import { Link } from '../router'
import { LanguageSwitch } from './ui'
import { tr } from '../i18n'

function AuthCard({ tenant, title, children, onSubmit }: { tenant: Tenant; title: string; children: ReactNode; onSubmit: (e: FormEvent) => void }) {
  return (
    <div className="center">
      <LanguageSwitch className="lang-floating" />
      <form className="card narrow" onSubmit={onSubmit} noValidate>
        <div className="login-head">
          <span className="logo" aria-hidden>
            {tr("HR")}
          </span>
          <div>
            <h1>{title}</h1>
            <p className="muted">{tenant.name}</p>
          </div>
        </div>
        {children}
      </form>
    </div>
  )
}

/**
 * First sign-in with a password someone else set (e.g. the company's starting
 * password): the user must choose their own before using anything else.
 */
export function ForcePasswordChange({
  tenant,
  email,
  onChanged,
  onLogout,
}: {
  tenant: Tenant
  email: string
  onChanged: () => void
  onLogout: () => void
}) {
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setErrors(null)
    setGeneral(null)
    try {
      await api.changePassword(current, password, confirmation)
      onChanged()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(e instanceof ApiError ? e.message : tr("Cannot reach the server."))
      setSubmitting(false)
    }
  }

  return (
    <AuthCard tenant={tenant} title={tr("Choose your password")} onSubmit={submit}>
      <p className="muted">
        {tr("Welcome,")}{' '}{email}{tr(". You signed in with a password someone else gave you. Choose your own to continue.")}
      </p>
      <label>
        {tr("Current password")}{' '}<span className="muted small">{tr("(the one you just used)")}</span>
        <input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} autoFocus />
        {errors?.field('current_password') && <span className="field-error">{tr(errors.field('current_password') ?? '')}</span>}
      </label>
      <label>
        {tr("New password")}
        <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        <span className="muted small">{tr("At least 12 characters, with letters and numbers.")}</span>
        {errors?.field('password') && <span className="field-error">{tr(errors.field('password') ?? '')}</span>}
      </label>
      <label>
        {tr("Repeat the new password")}
        <input type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
      </label>
      {general && (
        <p className="error" role="alert">
          {tr(general)}
        </p>
      )}
      <button type="submit" disabled={submitting || !current || !password || !confirmation}>
        {submitting ? tr("Saving…") : tr("Save and continue")}
      </button>
      <button type="button" className="link small" onClick={onLogout}>
        {tr("Sign out")}
      </button>
    </AuthCard>
  )
}

/** Step 1: ask for a reset link by email. */
export function ForgotPasswordPage({ tenant }: { tenant: Tenant }) {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      setSent((await api.forgotPassword(email)).message)
    } catch (e) {
      setError(e instanceof ApiError ? (e.field('email') ?? e.message) : tr("Cannot reach the server."))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthCard tenant={tenant} title={tr("Forgot password")} onSubmit={submit}>
      {sent ? (
        <>
          <p className="success" role="status">
            {tr(sent)}
          </p>
          <p className="muted small">{tr("The link works for 60 minutes. No email? Check spam, or ask your HR to reset the password.")}</p>
        </>
      ) : (
        <>
          <p className="muted">{tr("Enter the email you sign in with. We will email you a link to choose a new password.")}</p>
          <label>
            {tr("Email")}
            <input type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
          </label>
          {error && (
            <p className="error" role="alert">
              {tr(error)}
            </p>
          )}
          <button type="submit" disabled={submitting || !email}>
            {submitting ? tr("Sending…") : tr("Send reset link")}
          </button>
        </>
      )}
      <Link to="/" className="small">
        {tr("← Back to sign in")}
      </Link>
    </AuthCard>
  )
}

/** Step 2: the emailed link opens this page with ?token=…&email=… */
export function ResetPasswordPage({ tenant, onDone }: { tenant: Tenant; onDone: (message: string) => void }) {
  const params = new URLSearchParams(window.location.search)
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setErrors(null)
    setGeneral(null)
    try {
      const { message } = await api.resetPassword({ token, email, password, password_confirmation: confirmation })
      onDone(message)
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(e instanceof ApiError ? e.message : tr("Cannot reach the server."))
      setSubmitting(false)
    }
  }

  const linkProblem = !token || !email ? 'This reset link is incomplete. Open the link from the email again.' : errors?.field('token')

  return (
    <AuthCard tenant={tenant} title={tr("Choose a new password")} onSubmit={submit}>
      {email && <p className="muted">{tr("For")}{' '}{email}</p>}
      <label>
        {tr("New password")}
        <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} autoFocus />
        <span className="muted small">{tr("At least 12 characters, with letters and numbers.")}</span>
        {errors?.field('password') && <span className="field-error">{tr(errors.field('password') ?? '')}</span>}
      </label>
      <label>
        {tr("Repeat the new password")}
        <input type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
      </label>
      {(linkProblem || general || errors?.field('email')) && (
        <p className="error" role="alert">
          {tr(linkProblem ?? general ?? errors?.field('email') ?? '')}
        </p>
      )}
      {errors?.field('token') ? (
        <Link to="/forgot-password">{tr("Ask for a new link")}</Link>
      ) : (
        <button type="submit" disabled={submitting || !token || !email || !password || !confirmation}>
          {submitting ? tr("Saving…") : tr("Change password")}
        </button>
      )}
      <Link to="/" className="small">
        {tr("← Back to sign in")}
      </Link>
    </AuthCard>
  )
}
