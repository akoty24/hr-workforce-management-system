import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { PlatformOwner, PlatformTenant, PlatformTenantInput } from '../types'
import { Icon } from '../components/icons'
import { Avatar, ErrorText, FieldError, LanguageSwitch, Modal, PageHead } from '../components/ui'
import { formatDate } from '../format'
import { tr } from '../i18n'

/**
 * Owner console (admin.<domain>): the system owner creates companies with
 * their first administrator and suspends / reactivates them.
 */
export function PlatformApp({ initialOwner }: { initialOwner: PlatformOwner | null }) {
  const [owner, setOwner] = useState(initialOwner)

  if (!owner) return <OwnerLogin onLogin={setOwner} />

  return <Console owner={owner} onSignedOut={() => setOwner(null)} />
}

function OwnerLogin({ onLogin }: { onLogin: (owner: PlatformOwner) => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      onLogin(await api.platform.login(email, password))
    } catch (e) {
      setError(e instanceof ApiError ? (e.field('email') ?? e.message) : tr("Cannot reach the server."))
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
            <h1>{tr("Owner console")}</h1>
            <p className="muted">{tr("Create and manage companies")}</p>
          </div>
        </div>
        <label>
          {tr("Email")}
          <input type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} autoFocus />
        </label>
        <label>
          {tr("Password")}
          <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        {error && (
          <p className="error" role="alert">
            {tr(error)}
          </p>
        )}
        <button type="submit" disabled={submitting || !email || !password}>
          {submitting ? tr("Signing in…") : tr("Sign in")}
        </button>
      </form>
    </div>
  )
}

function Console({ owner, onSignedOut }: { owner: PlatformOwner; onSignedOut: () => void }) {
  const [tenants, setTenants] = useState<PlatformTenant[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [created, setCreated] = useState<(PlatformTenant & { admin_email: string; admin_password: string | null }) | null>(null)
  const [changingPassword, setChangingPassword] = useState(false)
  const [editing, setEditing] = useState<PlatformTenant | null>(null)

  const load = useCallback(async () => {
    try {
      setTenants(await api.platform.tenants())
      setError(null)
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) onSignedOut()
      else setError(errorMessage(e))
    }
  }, [onSignedOut])

  useEffect(() => {
    let active = true
    api.platform
      .tenants()
      .then((list) => active && setTenants(list))
      .catch((e) => {
        if (!active) return
        if (e instanceof ApiError && e.status === 401) onSignedOut()
        else setError(errorMessage(e))
      })
    return () => {
      active = false
    }
  }, [onSignedOut])

  async function setStatus(tenant: PlatformTenant, status: 'active' | 'suspended') {
    if (status === 'suspended' && !window.confirm(tr("Suspend {1}? Nobody in this company can use the system until you reactivate it.", { 1: tenant.name }))) return
    try {
      await api.platform.updateTenant(tenant.id, { status })
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  async function logout() {
    try {
      await api.platform.logout()
    } finally {
      onSignedOut()
    }
  }

  return (
    <div className="platform">
      <header className="topbar platform-bar">
        <div className="brand">
          <span className="logo" aria-hidden>
            {tr("HR")}
          </span>
          <div className="brand-text">
            <div className="brand-name">{tr("Owner console")}</div>
            <div className="muted small">{tr("HR Workforce")}</div>
          </div>
        </div>
        <div className="topbar-spacer" />
        <div className="user">
          <LanguageSwitch />
          <Avatar name={owner.name} size={28} />
          <span className="muted small user-email">{owner.email}</span>
          <button className="ghost" onClick={() => setChangingPassword(true)}>
            <Icon name="key" size={16} /> {tr("Password")}
          </button>
          <button className="ghost" onClick={logout}>
            <Icon name="logout" size={16} /> {tr("Sign out")}
          </button>
        </div>
      </header>

      <main className="content">
        <PageHead
          title={tr("Companies")}
          subtitle={tr("Each company has its own address, users and data. Nothing is shared between companies.")}
          actions={
            <button onClick={() => setCreating(true)}>
              <Icon name="plus" size={16} /> {tr("New company")}
            </button>
          }
        />

        <ErrorText message={error} />
        {!tenants && !error && <p className="muted">{tr("Loading…")}</p>}

        {tenants && (
          <div className="card table-card">
            <table>
              <thead>
                <tr>
                  <th>{tr("Company")}</th>
                  <th>{tr("Address")}</th>
                  <th>{tr("Users")}</th>
                  <th>{tr("Employees")}</th>
                  <th>{tr("Created")}</th>
                  <th>{tr("Status")}</th>
                  <th className="actions-col" aria-label={tr("Actions")} />
                </tr>
              </thead>
              <tbody>
                {tenants.map((t) => (
                  <tr key={t.id}>
                    <td>
                      <div className="strong">{t.name}</div>
                      <div className="muted small">{t.slug}</div>
                    </td>
                    <td>
                      <a href={t.url} target="_blank" rel="noreferrer noopener">
                        {t.url.replace(/^https?:\/\//, '')}
                      </a>
                    </td>
                    <td>{t.users_count}</td>
                    <td>{t.employees_count}</td>
                    <td>{t.created_at ? formatDate(t.created_at.slice(0, 10)) : '—'}</td>
                    <td>
                      <span className={`badge ${t.status === 'active' ? 'badge-approved' : 'badge-rejected'}`}>
                        {t.status === 'active' ? tr("Active") : tr("Suspended")}
                      </span>
                    </td>
                    <td className="actions">
                      <button className="link" onClick={() => setEditing(t)}>
                        {tr("Edit")}
                      </button>
                      {t.status === 'active' ? (
                        <button className="link danger" onClick={() => setStatus(t, 'suspended')}>
                          {tr("Suspend")}
                        </button>
                      ) : (
                        <button className="link" onClick={() => setStatus(t, 'active')}>
                          {tr("Reactivate")}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="table-foot muted small">
              <span>
                {tenants.length} {tr("compan")}{tenants.length === 1 ? tr("y") : tr("ies")}
              </span>
            </div>
          </div>
        )}
      </main>

      {creating && (
        <NewCompanyForm
          onClose={() => setCreating(false)}
          onCreated={(result) => {
            setCreating(false)
            setCreated(result)
            load()
          }}
        />
      )}
      {created && <CreatedDialog result={created} onClose={() => setCreated(null)} />}
      {editing && (
        <EditCompanyForm
          tenant={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
          }}
        />
      )}
      {changingPassword && <PasswordForm onClose={() => setChangingPassword(false)} />}
    </div>
  )
}

/** "Acme Trading Co." -> "acme-trading-co" */
function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 63)
}

function NewCompanyForm({
  onClose,
  onCreated,
}: {
  onClose: () => void
  onCreated: (result: PlatformTenant & { admin_email: string; admin_password: string | null }) => void
}) {
  const [form, setForm] = useState<PlatformTenantInput>({ name: '', slug: '', admin_name: '', admin_email: '', admin_password: '' })
  const [slugTouched, setSlugTouched] = useState(false)
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const set = (field: keyof PlatformTenantInput) => (value: string) =>
    setForm((f) => ({ ...f, [field]: value, ...(field === 'name' && !slugTouched ? { slug: slugify(value) } : {}) }))

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    try {
      onCreated(await api.platform.createTenant(form))
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  const host = window.location.host.replace(/^admin\./, '')

  return (
    <Modal title={tr("New company")} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <label>
          {tr("Company name")}
          <input value={form.name} onChange={(e) => set('name')(e.target.value)} maxLength={255} autoFocus />
          <FieldError message={errors?.field('name')} />
        </label>
        <label>
          {tr("Address")}
          <span className="slug-row">
            <input
              value={form.slug}
              onChange={(e) => {
                setSlugTouched(true)
                set('slug')(e.target.value.toLowerCase())
              }}
              maxLength={63}
            />
            <span className="muted">.{host}</span>
          </span>
          <span className="muted small">{tr("Lowercase English letters, numbers and hyphens.")}</span>
          <FieldError message={errors?.field('slug')} />
        </label>

        <div className="signin-box">
          <div>
            <strong>{tr("First administrator")}</strong>
            <div className="muted small">{tr("Signs in first and sets up departments, employees and logins.")}</div>
          </div>
          <div className="form-grid">
            <label>
              {tr("Name")}
              <input value={form.admin_name} onChange={(e) => set('admin_name')(e.target.value)} maxLength={255} />
              <FieldError message={errors?.field('admin_name')} />
            </label>
            <label>
              {tr("Email")}
              <input type="email" value={form.admin_email} onChange={(e) => set('admin_email')(e.target.value)} />
              <FieldError message={errors?.field('admin_email')} />
            </label>
          </div>
          <label>
            {tr("Password")}{' '}
            <span className="muted small">
              {tr("(leave empty to use")}{' '}<code>{(form.slug || 'name') + 'Password123'}</code>{tr("; they choose their own at first sign-in)")}
            </span>
            <input value={form.admin_password} onChange={(e) => set('admin_password')(e.target.value)} autoComplete="off" />
            <FieldError message={errors?.field('admin_password')} />
          </label>
        </div>

        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !form.name || !form.slug || !form.admin_name || !form.admin_email}>
            {saving ? tr("Creating…") : tr("Create company")}
          </button>
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            {tr("Cancel")}
          </button>
        </div>
      </form>
    </Modal>
  )
}

type CompanyAdmin = { id: string; name: string; email: string; is_active: boolean }

function EditCompanyForm({ tenant, onClose, onSaved }: { tenant: PlatformTenant; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(tenant.name)
  const [slug, setSlug] = useState(tenant.slug)
  const host = window.location.host.replace(/^admin\./, '')
  const [status, setStatus] = useState(tenant.status)
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [admins, setAdmins] = useState<CompanyAdmin[] | null>(null)
  const [reset, setReset] = useState<{ email: string; password: string } | null>(null)
  const [resetting, setResetting] = useState<string | null>(null)

  useEffect(() => {
    api.platform
      .admins(tenant.id)
      .then(setAdmins)
      .catch((e) => setGeneral(errorMessage(e)))
  }, [tenant.id])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    try {
      await api.platform.updateTenant(tenant.id, { name, status, slug: slug.trim() })
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  async function resetPassword(admin: CompanyAdmin) {
    if (!window.confirm(tr("Reset {1} to the starting password {2}Password123? Their current password stops working, they are signed out, and they choose a new one at next sign-in.", { 1: admin.email, 2: tenant.slug }))) return
    setResetting(admin.id)
    setGeneral(null)
    try {
      const { password } = await api.platform.resetAdminPassword(tenant.id, admin.id)
      if (password) setReset({ email: admin.email, password })
    } catch (e) {
      setGeneral(errorMessage(e))
    } finally {
      setResetting(null)
    }
  }

  return (
    <Modal title={tr("Edit {1}", { 1: tenant.name })} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <label>
          {tr("Company name")}
          <input value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus />
          <FieldError message={errors?.field('name')} />
        </label>
        <label>
          {tr("Address")}
          <span className="slug-row">
            <input value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase())} maxLength={63} />
            <span className="muted">.{host}</span>
          </span>
          <FieldError message={errors?.field('slug')} />
          {slug.trim() !== tenant.slug && (
            <span className="field-warning small">
              {tr("The company moves to the new address. The old address stops working, everyone there is signed out, and unused password-reset links stop working. Tell the company the new address.")}
            </span>
          )}
        </label>
        <div className="form-grid">
          <label>
            {tr("Status")}
            <select value={status} onChange={(e) => setStatus(e.target.value as PlatformTenant['status'])}>
              <option value="active">{tr("Active")}</option>
              <option value="suspended">{tr("Suspended (nobody can use it)")}</option>
            </select>
            <FieldError message={errors?.field('status')} />
          </label>
        </div>

        <div className="signin-box">
          <div>
            <strong>{tr("Administrators")}</strong>
            <div className="muted small">{tr("They sign in at the company address. Set a new password if one is locked out.")}</div>
          </div>
          {!admins && <p className="muted small">{tr("Loading…")}</p>}
          {admins?.length === 0 && <p className="muted small">{tr("This company has no administrator.")}</p>}
          {admins?.map((a) => (
            <div key={a.id} className="admin-row">
              <div>
                <div className="strong">{a.name}</div>
                <div className="muted small">
                  {a.email}
                  {!a.is_active && tr("· deactivated")}
                </div>
              </div>
              <button type="button" className="secondary" disabled={resetting === a.id} onClick={() => resetPassword(a)}>
                <Icon name="key" size={14} /> {resetting === a.id ? tr("Setting…") : tr("New password")}
              </button>
            </div>
          ))}
          {reset && (
            <div className="success">
              {tr("New password for")}{' '}{reset.email}: <code>{reset.password}</code>
              <div className="muted small">{tr("They choose their own password when they sign in with it.")}</div>
            </div>
          )}
        </div>

        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !name.trim()}>
            {saving ? tr("Saving…") : tr("Save")}
          </button>
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            {tr("Cancel")}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function CreatedDialog({
  result,
  onClose,
}: {
  result: PlatformTenant & { admin_email: string; admin_password: string | null }
  onClose: () => void
}) {
  const [copied, setCopied] = useState(false)
  const text = [`${result.name}`, `Address: ${result.url}`, `Email: ${result.admin_email}`, result.admin_password ? `Password: ${result.admin_password}` : null]
    .filter(Boolean)
    .join('\n')

  return (
    <Modal title={tr("Company created")} onClose={onClose}>
      <div className="form">
        <p className="success">{result.name} {tr("is ready. Give these sign-in details to its administrator.")}</p>
        <dl className="details">
          <dt>{tr("Address")}</dt>
          <dd>
            <a href={result.url} target="_blank" rel="noreferrer noopener">
              {result.url}
            </a>
          </dd>
          <dt>{tr("Email")}</dt>
          <dd>{result.admin_email}</dd>
          {result.admin_password && (
            <>
              <dt>{tr("Password")}</dt>
              <dd>
                <code>{result.admin_password}</code>
              </dd>
            </>
          )}
        </dl>
        {result.admin_password && <p className="muted small">{tr("This is the company's starting password. The administrator chooses their own at first sign-in.")}</p>}
        <div className="form-actions">
          <button
            type="button"
            onClick={() =>
              navigator.clipboard
                .writeText(text)
                .then(() => setCopied(true))
                .catch(() => {})
            }
          >
            {copied ? tr("Copied") : tr("Copy details")}
          </button>
          <button type="button" className="secondary" onClick={onClose}>
            {tr("Close")}
          </button>
        </div>
      </div>
    </Modal>
  )
}

function PasswordForm({ onClose }: { onClose: () => void }) {
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [done, setDone] = useState(false)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    try {
      await api.platform.changePassword(current, password, confirmation)
      setDone(true)
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={tr("Change your password")} onClose={onClose}>
      {done ? (
        <div className="form">
          <p className="success">{tr("Your password was changed.")}</p>
          <div className="form-actions">
            <button onClick={onClose}>{tr("Close")}</button>
          </div>
        </div>
      ) : (
        <form className="form" onSubmit={submit} noValidate>
          <label>
            {tr("Current password")}
            <input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} autoFocus />
            <FieldError message={errors?.field('current_password')} />
          </label>
          <label>
            {tr("New password")}{' '}<span className="muted small">{tr("(12+ characters, letters and numbers)")}</span>
            <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
            <FieldError message={errors?.field('password')} />
          </label>
          <label>
            {tr("Repeat the new password")}
            <input type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
          </label>
          <ErrorText message={general} />
          <div className="form-actions">
            <button type="submit" disabled={saving || !current || !password || !confirmation}>
              {saving ? tr("Saving…") : tr("Change password")}
            </button>
            <button type="button" className="secondary" onClick={onClose}>
              {tr("Cancel")}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}
