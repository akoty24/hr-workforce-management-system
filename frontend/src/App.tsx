import { useEffect, useState } from 'react'
import { ApiError, api, setUnauthenticatedHandler } from './api'
import { ROLE_LABELS, type Tenant, type User, type UserRole } from './types'
import { Link } from './router'
import { navigate, usePath } from './navigation'
import { LoginPage } from './components/LoginPage'
import { HealthBadge } from './components/HealthBadge'
import { DashboardPage } from './pages/DashboardPage'
import { EmployeesPage } from './pages/EmployeesPage'
import { DepartmentsPage } from './pages/DepartmentsPage'
import { ShortLeavePage } from './pages/ShortLeavePage'
import { LeavePage } from './pages/LeavePage'
import { LeaveTypesPage } from './pages/LeaveTypesPage'
import { UsersPage } from './pages/UsersPage'
import { AccountPage } from './pages/AccountPage'

type Boot =
  | { state: 'loading' }
  | { state: 'no-tenant' }
  | { state: 'error'; message: string }
  | { state: 'ready'; tenant: Tenant }

interface NavItem {
  path: string
  label: string
  icon: string
  /** Roles that see this item; all roles when omitted. */
  roles?: UserRole[]
}

const NAV: NavItem[] = [
  { path: '/', label: 'Dashboard', icon: '▦' },
  { path: '/employees', label: 'Employees', icon: '👥', roles: ['admin', 'team_leader'] },
  { path: '/departments', label: 'Departments', icon: '🏢', roles: ['admin'] },
  { path: '/leave', label: 'Leave', icon: '🗓' },
  { path: '/permissions', label: 'Permissions', icon: '⏱' },
  { path: '/leave-types', label: 'Leave types', icon: '⚙', roles: ['admin'] },
  { path: '/users', label: 'Users', icon: '🔑', roles: ['admin'] },
  { path: '/account', label: 'My account', icon: '👤' },
]

export default function App() {
  const [boot, setBoot] = useState<Boot>({ state: 'loading' })
  // undefined = still checking the session, null = signed out
  const [user, setUser] = useState<User | null | undefined>(undefined)
  const [menuOpen, setMenuOpen] = useState(false)
  const path = usePath()

  // Any 401 from the API (expired session, deactivated account) signs out.
  useEffect(() => {
    setUnauthenticatedHandler(() => setUser(null))
    return () => setUnauthenticatedHandler(null)
  }, [])

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
      navigate('/')
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

  const items = NAV.filter((item) => !item.roles || item.roles.includes(user.role)).map((item) =>
    item.path === '/employees' && user.role === 'team_leader' ? { ...item, label: 'My team' } : item,
  )
  const current = items.find((item) => item.path === path) ? path : '/'

  let page
  switch (current) {
    case '/employees':
      page = <EmployeesPage user={user} />
      break
    case '/departments':
      page = <DepartmentsPage user={user} />
      break
    case '/leave':
      page = <LeavePage user={user} />
      break
    case '/permissions':
      page = <ShortLeavePage user={user} />
      break
    case '/leave-types':
      page = <LeaveTypesPage />
      break
    case '/users':
      page = <UsersPage currentUser={user} />
      break
    case '/account':
      page = <AccountPage user={user} />
      break
    default:
      page = <DashboardPage user={user} />
  }

  return (
    <div className="shell">
      <aside className={`sidebar ${menuOpen ? 'open' : ''}`} onClick={() => setMenuOpen(false)}>
        <div className="brand">
          <span className="logo" aria-hidden>
            HR
          </span>
          <div>
            <div className="brand-name">{tenant.name}</div>
            <div className="muted small">HR Workforce</div>
          </div>
        </div>
        <nav>
          {items.map((item) => (
            <Link key={item.path} to={item.path} className={`nav-item ${current === item.path ? 'active' : ''}`}>
              <span className="nav-icon" aria-hidden>
                {item.icon}
              </span>
              {item.label}
            </Link>
          ))}
        </nav>
        <div className="sidebar-foot small">
          <HealthBadge />
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <button className="icon menu-button" onClick={() => setMenuOpen((open) => !open)} aria-label="Menu">
            ☰
          </button>
          <div className="user">
            <div className="user-meta">
              <div>{user.name}</div>
              <div className="muted small">
                {user.email} · <span className={`role role-${user.role}`}>{ROLE_LABELS[user.role]}</span>
              </div>
            </div>
            <button className="secondary" onClick={logout}>
              Sign out
            </button>
          </div>
        </header>
        <main className="content">{page}</main>
      </div>
    </div>
  )
}
