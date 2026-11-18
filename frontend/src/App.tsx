import { useEffect, useState } from 'react'
import { ApiError, api } from './api'
import type { Tenant, User } from './types'
import { LoginPage } from './components/LoginPage'
import { DepartmentsPage } from './components/DepartmentsPage'
import { HealthBadge } from './components/HealthBadge'

type Boot =
  | { state: 'loading' }
  | { state: 'no-tenant' }
  | { state: 'error'; message: string }
  | { state: 'ready'; tenant: Tenant }

export default function App() {
  const [boot, setBoot] = useState<Boot>({ state: 'loading' })
  // undefined = still checking the session, null = signed out
  const [user, setUser] = useState<User | null | undefined>(undefined)

  useEffect(() => {
    let cancelled = false

    async function start() {
      try {
        const tenant = await api.tenant() // also issues the CSRF cookie
        if (cancelled) return
        setBoot({ state: 'ready', tenant })

        try {
          const me = await api.me()
          if (!cancelled) setUser(me)
        } catch (e) {
          if (!cancelled) setUser(null)
          if (!(e instanceof ApiError && e.status === 401)) throw e
        }
      } catch (e) {
        if (cancelled) return
        if (e instanceof ApiError && e.status === 404) setBoot({ state: 'no-tenant' })
        else if (e instanceof ApiError && e.status === 403) setBoot({ state: 'error', message: 'This workspace is suspended.' })
        else setBoot({ state: 'error', message: 'Cannot reach the server. Is the backend running?' })
      }
    }

    start()
    return () => {
      cancelled = true
    }
  }, [])

  async function logout() {
    try {
      await api.logout()
    } finally {
      setUser(null)
    }
  }

  if (boot.state === 'loading' || (boot.state === 'ready' && user === undefined)) {
    return <div className="center muted">Loading…</div>
  }

  if (boot.state === 'no-tenant') {
    return (
      <div className="center">
        <div className="card narrow">
          <h1>Workspace not found</h1>
          <p className="muted">No company is set up for this address.</p>
          <p className="muted small">
            Create one with <code>php artisan tenant:install</code>.
          </p>
        </div>
      </div>
    )
  }

  if (boot.state === 'error') {
    return (
      <div className="center">
        <div className="card narrow">
          <h1>Something went wrong</h1>
          <p className="muted">{boot.message}</p>
          <button onClick={() => window.location.reload()}>Try again</button>
        </div>
      </div>
    )
  }

  const { tenant } = boot

  if (!user) {
    return <LoginPage tenant={tenant} onLogin={setUser} />
  }

  return (
    <div className="layout">
      <header className="topbar">
        <div className="brand">
          <span className="logo" aria-hidden>
            HR
          </span>
          <div>
            <div className="brand-name">{tenant.name}</div>
            <div className="muted small">HR Workforce</div>
          </div>
        </div>
        <div className="user">
          <div className="user-meta">
            <div>{user.name}</div>
            <div className="muted small">
              {user.email} · <span className={`role role-${user.role}`}>{user.role}</span>
            </div>
          </div>
          <button className="secondary" onClick={logout}>
            Sign out
          </button>
        </div>
      </header>

      <main className="content">
        <DepartmentsPage user={user} onSessionExpired={() => setUser(null)} />
      </main>

      <footer className="footer muted small">
        <HealthBadge />
      </footer>
    </div>
  )
}
