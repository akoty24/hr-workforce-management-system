import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { CompanySettings } from '../types'
import { ErrorText, FieldError, PageHead } from '../components/ui'
import { tr } from '../i18n'

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
      <PageHead title={tr("Settings")} subtitle={tr("Company work schedule used for attendance and lateness.")} />
      {!form && !general && <p className="muted">{tr("Loading…")}</p>}
      <ErrorText message={general} />
      {form && (
        <form className="card form narrow-form" onSubmit={submit} noValidate>
          <div className="form-grid">
            <label>
              {tr("Work starts")}
              <input type="time" value={form.work_start} onChange={(e) => setForm({ ...form, work_start: e.target.value })} />
              <FieldError message={errors?.field('work_start')} />
            </label>
            <label>
              {tr("Work ends")}
              <input type="time" value={form.work_end} onChange={(e) => setForm({ ...form, work_end: e.target.value })} />
              <FieldError message={errors?.field('work_end')} />
            </label>
            <label>
              {tr("Grace period (minutes)")}
              <input
                type="number"
                min={0}
                max={240}
                value={form.grace_minutes}
                onChange={(e) => setForm({ ...form, grace_minutes: Number(e.target.value) })}
              />
              <span className="muted small">{tr("Arriving within this time after the start is not counted as late.")}</span>
              <FieldError message={errors?.field('grace_minutes')} />
            </label>
            <label>
              {tr("Time zone")}
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
            {tr("Require the employee's location to check in and out")}
          </label>

          <h2 className="form-section">{tr("Payroll")}</h2>
          <p className="muted small">
            {tr("How the monthly payroll is calculated. Defaults follow common practice in Egypt; check them with your accountant.")}
          </p>
          <div className="form-grid">
            <label>
              {tr("Days per month for the day rate")}
              <input
                type="number"
                min={20}
                max={31}
                value={form.payroll_day_divisor}
                onChange={(e) => setForm({ ...form, payroll_day_divisor: Number(e.target.value) })}
              />
              <span className="muted small">{tr("Day rate = monthly salary ÷ this number (usually 30).")}</span>
              <FieldError message={errors?.field('payroll_day_divisor')} />
            </label>
            <label>
              {tr("Lateness deduction multiplier")}
              <input
                type="number"
                min={0}
                max={10}
                step={0.05}
                value={form.late_multiplier}
                onChange={(e) => setForm({ ...form, late_multiplier: Number(e.target.value) })}
              />
              <span className="muted small">{tr("Each late minute costs this many minutes of pay. 0 turns it off.")}</span>
              <FieldError message={errors?.field('late_multiplier')} />
            </label>
            <label>
              {tr("Overtime multiplier")}
              <input
                type="number"
                min={0}
                max={10}
                step={0.05}
                value={form.overtime_multiplier}
                onChange={(e) => setForm({ ...form, overtime_multiplier: Number(e.target.value) })}
              />
              <span className="muted small">{tr("Minutes worked beyond the day are paid at this rate. 0 turns it off.")}</span>
              <FieldError message={errors?.field('overtime_multiplier')} />
            </label>
            <label>
              {tr("Social insurance, employee share (%)")}
              <input
                type="number"
                min={0}
                max={50}
                step={0.25}
                value={form.social_insurance_rate}
                onChange={(e) => setForm({ ...form, social_insurance_rate: Number(e.target.value) })}
              />
              <FieldError message={errors?.field('social_insurance_rate')} />
            </label>
            <label>
              {tr("Minimum insurable wage")}
              <input
                type="number"
                min={0}
                value={form.insurable_min}
                onChange={(e) => setForm({ ...form, insurable_min: Number(e.target.value) })}
              />
              <FieldError message={errors?.field('insurable_min')} />
            </label>
            <label>
              {tr("Maximum insurable wage")}
              <input
                type="number"
                min={0}
                value={form.insurable_max}
                onChange={(e) => setForm({ ...form, insurable_max: Number(e.target.value) })}
              />
              <FieldError message={errors?.field('insurable_max')} />
            </label>
          </div>
          <label className="checkbox">
            <input type="checkbox" checked={form.deduct_absence} onChange={(e) => setForm({ ...form, deduct_absence: e.target.checked })} />
            {tr("Deduct a day's pay for each working day without check-in or approved leave")}
          </label>
          <label className="checkbox">
            <input
              type="checkbox"
              checked={form.social_insurance_enabled}
              onChange={(e) => setForm({ ...form, social_insurance_enabled: e.target.checked })}
            />
            {tr("Deduct social insurance")}
          </label>
          <label className="checkbox">
            <input
              type="checkbox"
              checked={form.income_tax_enabled}
              onChange={(e) => setForm({ ...form, income_tax_enabled: e.target.checked })}
            />
            {tr("Deduct income tax (Egyptian brackets, 20,000 annual exemption)")}
          </label>
          {saved && <p className="success">{tr("Settings saved.")}</p>}
          <div className="form-actions">
            <button type="submit" disabled={saving}>
              {saving ? tr("Saving…") : tr("Save settings")}
            </button>
          </div>
        </form>
      )}
    </section>
  )
}
