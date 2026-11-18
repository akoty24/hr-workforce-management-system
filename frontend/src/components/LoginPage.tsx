import { useState, type FormEvent } from 'react'
import { ApiError, api } from '../api'
import type { Tenant, User } from '../types'

interface Props {
  tenant: Tenant
  onLogin: (user: User) => void
}

export function LoginPage({ tenant, onLogin }: Props) {
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
        setError('Cannot reach the server.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="center">
      <form className="card narrow" onSubmit={submit} noValidate>
        <div className="login-head">
          <span className="logo" aria-hidden>
            HR
          </span>
          <div>
            <h1>Sign in</h1>
            <p className="muted">{tenant.name}</p>
          </div>
        </div>

        <label>
          Email
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
          Password
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
            {error}
          </p>
        )}

        <button type="submit" disabled={submitting || !email || !password}>
          {submitting ? 'Signing in…' : 'Sign in'}
        </button>

        {import.meta.env.DEV && (
          <div className="hint small">
            <strong>Local demo accounts</strong> (password <code>DemoPassword123</code>)
            <div className="hint-buttons">
              <button type="button" className="link" onClick={() => setEmail('admin@demo.test')}>
                admin@demo.test
              </button>
              <button type="button" className="link" onClick={() => setEmail('employee@demo.test')}>
                employee@demo.test
              </button>
            </div>
          </div>
        )}
      </form>
    </div>
  )
}
