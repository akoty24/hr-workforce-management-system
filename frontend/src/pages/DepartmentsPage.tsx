import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { Department, User } from '../types'
import { ErrorText, FieldError, Modal, PageHead } from '../components/ui'

export function DepartmentsPage({ user }: { user: User }) {
  const [departments, setDepartments] = useState<Department[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<Department | 'new' | null>(null)
  const isAdmin = user.role === 'admin'

  const load = useCallback(async () => {
    try {
      setDepartments(await api.departments.list())
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [])

  useEffect(() => {
    let active = true
    api.departments
      .list()
      .then((list) => active && setDepartments(list))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [])

  async function remove(department: Department) {
    if (!window.confirm(`Delete the "${department.name}" department?`)) return
    try {
      await api.departments.remove(department.id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  return (
    <section>
      <PageHead
        title="Departments"
        subtitle={isAdmin ? 'Create and organise the departments of your company.' : 'Departments of your company.'}
        actions={isAdmin && <button onClick={() => setEditing('new')}>+ New department</button>}
      />

      <ErrorText message={error} />
      {departments === null && !error && <p className="muted">Loading…</p>}

      {departments?.length === 0 && (
        <div className="card empty">
          <p>No departments yet.</p>
        </div>
      )}

      {departments && departments.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Description</th>
                <th className="num">Employees</th>
                {isAdmin && <th className="actions-col" aria-label="Actions" />}
              </tr>
            </thead>
            <tbody>
              {departments.map((d) => (
                <tr key={d.id}>
                  <td className="strong">{d.name}</td>
                  <td className="muted">{d.description ?? '—'}</td>
                  <td className="num">{d.employees_count ?? 0}</td>
                  {isAdmin && (
                    <td className="actions">
                      <button className="link" onClick={() => setEditing(d)}>
                        Edit
                      </button>
                      <button className="link danger" onClick={() => remove(d)}>
                        Delete
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <DepartmentForm
          department={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            load()
          }}
        />
      )}
    </section>
  )
}

function DepartmentForm({ department, onClose, onSaved }: { department: Department | null; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(department?.name ?? '')
  const [description, setDescription] = useState(department?.description ?? '')
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors(null)
    setGeneral(null)
    try {
      if (department) await api.departments.update(department.id, { name, description })
      else await api.departments.create({ name, description })
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setGeneral(errorMessage(e))
      setSaving(false)
    }
  }

  return (
    <Modal title={department ? `Edit “${department.name}”` : 'New department'} onClose={onClose}>
      <form className="form" onSubmit={submit} noValidate>
        <label>
          Name
          <input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoFocus aria-invalid={!!errors?.field('name')} />
          <FieldError message={errors?.field('name')} />
        </label>
        <label>
          Description <span className="muted small">(optional)</span>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} maxLength={1000} rows={3} />
          <FieldError message={errors?.field('description')} />
        </label>
        <ErrorText message={general} />
        <div className="form-actions">
          <button type="submit" disabled={saving || !name.trim()}>
            {saving ? 'Saving…' : 'Save'}
          </button>
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </button>
        </div>
      </form>
    </Modal>
  )
}
