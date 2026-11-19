import { useEffect, useState } from 'react'
import { ApiError, api, setUnauthenticatedHandler } from './api'
import { ROLE_LABELS, type PlatformOwner, type Tenant, type User, type UserRole } from './types'
import { PlatformApp } from './platform/PlatformApp'
import { Link } from './router'
import { navigate, usePath } from './navigation'
import { LoginPage } from './components/LoginPage'
import { ForcePasswordChange, ForgotPasswordPage, ResetPasswordPage } from './components/PasswordResetPages'
import { HealthBadge } from './components/HealthBadge'
import { Icon, type IconName } from './components/icons'
import { Avatar, LanguageSwitch } from './components/ui'
import { DashboardPage } from './pages/DashboardPage'
import { EmployeesPage } from './pages/EmployeesPage'
import { DepartmentsPage } from './pages/DepartmentsPage'
import { ShortLeavePage } from './pages/ShortLeavePage'
import { AttendancePage } from './pages/AttendancePage'
import { ReportsPage } from './pages/ReportsPage'
import { PayrollPage } from './pages/PayrollPage'
import { SettingsPage } from './pages/SettingsPage'
import { HolidaysPage } from './pages/HolidaysPage'
import { LeavePage } from './pages/LeavePage'
import { LeaveTypesPage } from './pages/LeaveTypesPage'
import { UsersPage } from './pages/UsersPage'
import { AccountPage } from './pages/AccountPage'
import { tr } from './i18n'

type Boot =
  | { state: 'loading' }
  | { state: 'no-tenant' }
  | { state: 'error'; message: string }
  | { state: 'ready'; tenant: Tenant }
  | { state: 'platform'; owner: PlatformOwner | null }

interface NavItem {
  path: string
  label: string
  icon: IconName
  /** Roles that see this item; all roles when omitted. */
  roles?: UserRole[]
}

const HR: UserRole[] = ['admin', 'hr']

/** Sidebar, grouped: personal work first, then company management. */
const NAV: { title: string | null; items: NavItem[] }[] = [
  { title: null, items: [{ path: '/', label: 'Dashboard', icon: 'dashboard' }] },
  {
    title: 'My work',
    items: [
      { path: '/attendance', label: 'Attendance', icon: 'clock' },
      { path: '/leave', label: 'Leave', icon: 'calendar' },
      { path: '/permissions', label: 'Permissions', icon: 'timer' },
      { path: '/holidays', label: 'Public holidays', icon: 'sun' },
    ],
  },
  {
    title: 'Company',
    items: [
      { path: '/employees', label: 'Employees', icon: 'users', roles: ['admin', 'hr', 'team_leader'] },
      { path: '/departments', label: 'Departments', icon: 'building', roles: HR },
      { path: '/payroll', label: 'Payroll', icon: 'wallet', roles: HR },
      { path: '/reports', label: 'Reports', icon: 'chart', roles: HR },
    ],
  },
  {
    title: 'Administration',
    items: [
      { path: '/leave-types', label: 'Leave types', icon: 'tag', roles: HR },
      { path: '/users', label: 'Users & roles', icon: 'key', roles: ['admin'] },
      { path: '/settings', label: 'Settings', icon: 'settings', roles: HR },
    ],
  },
]

/** "acme.localhost:5173" -> "localhost:5173" (a bare host is returned as is). */
function baseHost(): string {
  const host = window.location.host
  const rest = host.slice(host.indexOf('.') + 1)
  const restName = rest.split(':')[0]
  // Strip the first label only when what remains is still a domain.
  return host.includes('.') && (restName === 'localhost' || restName.includes('.')) ? rest : host
}

/** No company at this address: it may be the owner console host instead. */
async function ownerConsoleOrMissing(): Promise<Boot> {
  try {
    return { state: 'platform', owner: await api.platform.me() }
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) return { state: 'platform', owner: null }
    return { state: 'no-tenant' }
  }
}

export default function App() {
  const [boot, setBoot] = useState<Boot>({ state: 'loading' })
  // undefined = still checking the session, null = signed out
  const [user, setUser] = useState<User | null | undefined>(undefined)
  const [menuOpen, setMenuOpen] = useState(false)
  const [loginNotice, setLoginNotice] = useState<string | null>(null)
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
        if (e instanceof ApiError && e.status === 404) setBoot(await ownerConsoleOrMissing())
        else if (e instanceof ApiError && e.status === 403) setBoot({ state: 'error', message: 'This workspace is suspended.' })
        else setBoot({ state: 'error', message: 'Cannot reach the server. Is the backend running?' })
      }
    }

    start()
    return () => {
      cancelled = true
    }
  }, [])

  // Keep the signed-in user fresh: an admin may link an employee record or change
  // the role while the app is open. Re-check on navigation and when the tab regains focus.
  const signedIn = !!user
  useEffect(() => {
    if (!signedIn) return
    let active = true
    const refresh = () =>
      api
        .me()
        .then((me) => active && setUser((prev) => (JSON.stringify(prev) === JSON.stringify(me) ? prev : me)))
        .catch(() => {}) // a 401 is handled globally
    refresh()
    window.addEventListener('focus', refresh)
    return () => {
      active = false
      window.removeEventListener('focus', refresh)
    }
  }, [path, signedIn])

  async function logout() {
    try {
      await api.logout()
    } finally {
      setUser(null)
      navigate('/')
    }
  }

  if (boot.state === 'loading' || (boot.state === 'ready' && user === undefined)) {
    return <div className="center muted">{tr("Loading…")}</div>
  }

  if (boot.state === 'platform') {
    return <PlatformApp initialOwner={boot.owner} />
  }

  if (boot.state === 'no-tenant') {
    return (
      <div className="center">
        <LanguageSwitch className="lang-floating" />
        <div className="card narrow">
          <h1>{tr("Company not found")}</h1>
          <p className="muted">
            {tr("No company uses the address")}{' '}<strong>{window.location.host}</strong>.
          </p>
          <p className="muted small">
            {tr("Each company has its own address, like")}{' '}<code>{tr("yourcompany.")}{baseHost()}</code>{tr(". Check the address you were given, or ask your administrator. The company may have moved to a new address.")}
          </p>
          {import.meta.env.DEV && (
            <p className="hint small">
              {tr("Local development: companies are created in the owner console at")}{' '}
              <a href={`${window.location.protocol}//admin.${baseHost()}`}>{tr("admin.")}{baseHost()}</a>.
            </p>
          )}
        </div>
      </div>
    )
  }

  if (boot.state === 'error') {
    return (
      <div className="center">
        <LanguageSwitch className="lang-floating" />
        <div className="card narrow">
          <h1>{tr("Something went wrong")}</h1>
          <p className="muted">{boot.message}</p>
          <button onClick={() => window.location.reload()}>{tr("Try again")}</button>
        </div>
      </div>
    )
  }

  const { tenant } = boot

  if (!user) {
    if (path === '/forgot-password') return <ForgotPasswordPage tenant={tenant} />
    if (path === '/reset-password') {
      return (
        <ResetPasswordPage
          tenant={tenant}
          onDone={(message) => {
            setLoginNotice(message)
            navigate('/')
          }}
        />
      )
    }
    return (
      <LoginPage
        tenant={tenant}
        notice={loginNotice}
        onLogin={(me) => {
          setLoginNotice(null)
          setUser(me)
        }}
      />
    )
  }

  // Password set by someone else (e.g. the company's starting password):
  // nothing else is available until the user chooses their own.
  if (user.must_change_password) {
    return (
      <ForcePasswordChange
        tenant={tenant}
        email={user.email}
        onLogout={logout}
        onChanged={() =>
          api
            .me()
            .then(setUser)
            .catch(() => setUser(null))
        }
      />
    )
  }

  const groups = NAV.map((group) => ({
    ...group,
    items: group.items
      .filter((item) => !item.roles || item.roles.includes(user.role))
      .map((item) => (item.path === '/employees' && user.role === 'team_leader' ? { ...item, label: 'My team' } : item)),
  })).filter((group) => group.items.length > 0)
  const allPaths = [...groups.flatMap((g) => g.items.map((i) => i.path)), '/account']
  const current = allPaths.includes(path) ? path : '/'

  let page
  switch (current) {
    case '/employees':
      page = <EmployeesPage user={user} tenantSlug={tenant.slug} />
      break
    case '/departments':
      page = <DepartmentsPage user={user} />
      break
    case '/leave':
      page = <LeavePage user={user} />
      break
    case '/attendance':
      page = <AttendancePage user={user} />
      break
    case '/payroll':
      page = <PayrollPage />
      break
    case '/reports':
      page = <ReportsPage />
      break
    case '/settings':
      page = <SettingsPage />
      break
    case '/holidays':
      page = <HolidaysPage user={user} />
      break
    case '/permissions':
      page = <ShortLeavePage user={user} />
      break
    case '/leave-types':
      page = <LeaveTypesPage />
      break
    case '/users':
      page = <UsersPage currentUser={user} tenantSlug={tenant.slug} />
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
            {tr("HR")}
          </span>
          <div className="brand-text">
            <div className="brand-name">{tenant.name}</div>
            <div className="muted small">{tr("HR Workforce")}</div>
          </div>
        </div>
        <nav>
          {groups.map((group) => (
            <div key={group.title ?? 'main'} className="nav-group">
              {group.title && <div className="nav-title">{tr(group.title)}</div>}
              {group.items.map((item) => (
                <Link key={item.path} to={item.path} className={`nav-item ${current === item.path ? 'active' : ''}`}>
                  <Icon name={item.icon} />
                  {tr(item.label)}
                </Link>
              ))}
            </div>
          ))}
        </nav>
        <div className="sidebar-foot">
          <Link to="/account" className={`nav-item account-link ${current === '/account' ? 'active' : ''}`}>
            <Avatar name={user.name} size={30} />
            <span className="account-text">
              <span className="strong">{user.name}</span>
              <span className={`role role-${user.role}`}>{tr(ROLE_LABELS[user.role])}</span>
            </span>
          </Link>
          <HealthBadge />
        </div>
      </aside>
      {menuOpen && <div className="scrim" onClick={() => setMenuOpen(false)} />}

      <div className="main">
        <header className="topbar">
          <button className="icon menu-button" onClick={() => setMenuOpen((open) => !open)} aria-label={tr("Menu")}>
            <Icon name="menu" size={20} />
          </button>
          <div className="topbar-spacer" />
          <LanguageSwitch />
          <div className="user">
            <span className="muted small user-email">{user.email}</span>
            <button className="ghost" onClick={logout}>
              <Icon name="logout" size={16} /> {tr("Sign out")}
            </button>
          </div>
        </header>
        <main className="content">{page}</main>
      </div>
    </div>
  )
}
