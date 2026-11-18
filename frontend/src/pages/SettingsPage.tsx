import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { CompanySettings } from '../types'
import { ErrorText, FieldError, PageHead } from '../components/ui'

const COMMON_TIMEZONES = ['Africa/Cairo', 'Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Qatar', 'Europe/London', 'UTC']

export function SettingsPage() {
  const [form, setForm] = useState<CompanySettings | null>(null)
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.settings
      .get()
      .then(setForm)
      .catch((e) => setGeneral(errorMessage(e)))
  }, [])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!form) return
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    setSaved(false)
    try {
      setForm(await api.settings.update(form))
      setSaved(true)
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  const zones = form && !COMMON_TIMEZONES.includes(form.timezone) ? [form.timezone, ...COMMON_TIMEZONES] : COMMON_TIMEZONES

  return (
    <section>
      <PageHead title="Settings" subtitle="Company work schedule used for attendance and lateness." />
      {!form && !general && <p className="muted">Loading…</p>}
      <ErrorText message={general} />
      {form && (
        <form className="card form narrow-form" onSubmit={submit} noValidate>
          <div className="form-grid">
            <label>
              Work starts
              <input type="time" value={form.work_start} onChange={(e) => setForm({ ...form, work_start: e.target.value })} />
              <FieldError message={errors?.field('work_start')} />
            </label>
            <label>
              Work ends
              <input type="time" value={form.work_end} onChange={(e) => setForm({ ...form, work_end: e.target.value })} />
              <FieldError message={errors?.field('work_end')} />
            </label>
            <label>
              Grace period (minutes)
              <input
                type="number"
                min={0}
                max={240}
                value={form.grace_minutes}
                onChange={(e) => setForm({ ...form, grace_minutes: Number(e.target.value) })}
              />
              <span className="muted small">Arriving within this time after the start is not counted as late.</span>
              <FieldError message={errors?.field('grace_minutes')} />
            </label>
            <label>
              Time zone
              <select value={form.timezone} onChange={(e) => setForm({ ...form, timezone: e.target.value })}>
                {zones.map((z) => (
                  <option key={z} value={z}>
                    {z}
                  </option>
                ))}
              </select>
              <FieldError message={errors?.field('timezone')} />
            </label>
          </div>
          <label className="checkbox">
            <input
              type="checkbox"
              checked={form.require_location}
              onChange={(e) => setForm({ ...form, require_location: e.target.checked })}
            />
            Require the employee's location to check in and out
          </label>
          {saved && <p className="success">Settings saved.</p>}
          <div className="form-actions">
            <button type="submit" disabled={saving}>
              {saving ? 'Saving…' : 'Save settings'}
            </button>
          </div>
        </form>
      )}
    </section>
  )
}
