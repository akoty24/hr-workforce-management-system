import { useState, type FormEvent } from 'react'
import { ApiError, api } from '../api'
import type { Tenant, User } from '../types'
import { Link } from '../router'
import { LanguageSwitch } from './ui'
import { tr } from '../i18n'

interface Props {
  tenant: Tenant
  onLogin: (user: User) => void
  /** Shown above the form, e.g. after a password reset. */
  notice?: string | null
}

export function LoginPage({ tenant, onLogin, notice }: Props) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)

    try {
      onLogin(await api.login(email, password))
    } catch (e) {
      if (e instanceof ApiError) {
        setError(e.field('email') ?? e.field('password') ?? e.message)
      } else {
        setError(tr("Cannot reach the server."))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="center">
      <LanguageSwitch className="lang-floating" />
      <form className="card narrow" onSubmit={submit} noValidate>
        <div className="login-head">
          <span className="logo" aria-hidden>
            {tr("HR")}
          </span>
          <div>
            <h1>{tr("Sign in")}</h1>
            <p className="muted">{tenant.name}</p>
          </div>
        </div>

        {notice && (
          <p className="success" role="status">
            {tr(notice)}
          </p>
        )}

        <label>
          {tr("Email")}
          <input
            type="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoFocus
          />
        </label>

        <label>
          <span className="label-row">
            {tr("Password")}
            <Link to="/forgot-password" className="small">
              {tr("Forgot password?")}
            </Link>
          </span>
          <input
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>

        {error && (
          <p className="error" role="alert">
            {tr(error)}
          </p>
        )}

        <button type="submit" disabled={submitting || !email || !password}>
          {submitting ? tr("Signing in…") : tr("Sign in")}
        </button>

        {import.meta.env.DEV && tenant.demo_accounts && tenant.demo_accounts.length > 0 && (
          <div className="hint small">
            <strong>{tr("Local demo accounts")}</strong> {tr("(password")}{' '}<code>{tr("DemoPassword123")}</code>)
            <div className="hint-buttons">
              {tenant.demo_accounts.map((account) => (
                <button key={account} type="button" className="link" onClick={() => setEmail(account)}>
                  {account}
                </button>
              ))}
            </div>
          </div>
        )}
      </form>
    </div>
  )
}
