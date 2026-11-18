import { useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import type { Department, DepartmentInput } from '../types'

interface Props {
  title: string
  initial?: Department
  onSubmit: (input: DepartmentInput) => Promise<void>
  onCancel: () => void
}

export function DepartmentForm({ title, initial, onSubmit, onCancel }: Props) {
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [errors, setErrors] = useState<{ name?: string; description?: string; general?: string }>({})
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})

    try {
      await onSubmit({ name, description })
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        setErrors({ name: e.field('name'), description: e.field('description') })
      } else {
        setErrors({ general: e instanceof ApiError ? e.message : 'Cannot reach the server.' })
      }
      setSaving(false)
    }
  }

  return (
    <form className="card form" onSubmit={submit} noValidate>
      <h2>{title}</h2>

      <label>
        Name
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          maxLength={120}
          required
          autoFocus
          aria-invalid={!!errors.name}
        />
        {errors.name && <span className="field-error">{errors.name}</span>}
      </label>

      <label>
        Description <span className="muted small">(optional)</span>
        <textarea
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          maxLength={1000}
          rows={3}
          aria-invalid={!!errors.description}
        />
        {errors.description && <span className="field-error">{errors.description}</span>}
      </label>

      {errors.general && (
        <p className="error" role="alert">
          {errors.general}
        </p>
      )}

      <div className="form-actions">
        <button type="submit" disabled={saving || !name.trim()}>
          {saving ? 'Saving…' : 'Save'}
        </button>
        <button type="button" className="secondary" onClick={onCancel} disabled={saving}>
          Cancel
        </button>
      </div>
    </form>
  )
}
