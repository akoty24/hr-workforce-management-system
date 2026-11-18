import { useCallback, useEffect, useState } from 'react'
import { ApiError, api } from '../api'
import type { Department, DepartmentInput, User } from '../types'
import { DepartmentForm } from './DepartmentForm'

interface Props {
  user: User
  onSessionExpired: () => void
}

type Editing = { mode: 'create' } | { mode: 'edit'; department: Department } | null

export function DepartmentsPage({ user, onSessionExpired }: Props) {
  const [departments, setDepartments] = useState<Department[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<Editing>(null)
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const isAdmin = user.role === 'admin'

  const handleError = useCallback(
    (e: unknown) => {
      if (e instanceof ApiError && e.status === 401) onSessionExpired()
      else setError(e instanceof ApiError ? e.message : 'Cannot reach the server.')
    },
    [onSessionExpired],
  )

  const load = useCallback(async () => {
    try {
      setDepartments(await api.departments.list())
      setError(null)
    } catch (e) {
      handleError(e)
    }
  }, [handleError])

  // Initial load (ignores the result if the component unmounted meanwhile).
  useEffect(() => {
    let active = true
    api.departments
      .list()
      .then((list) => active && setDepartments(list))
      .catch((e) => active && handleError(e))
    return () => {
      active = false
    }
  }, [handleError])

  async function save(input: DepartmentInput) {
    // Errors (incl. validation) propagate to the form, which displays them.
    try {
      if (editing?.mode === 'edit') await api.departments.update(editing.department.id, input)
      else await api.departments.create(input)
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) onSessionExpired()
      throw e
    }
    setEditing(null)
    await load()
  }

  async function remove(department: Department) {
    if (!window.confirm(`Delete the "${department.name}" department?`)) return

    setDeletingId(department.id)
    try {
      await api.departments.remove(department.id)
      await load()
    } catch (e) {
      handleError(e)
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <section>
      <div className="page-head">
        <div>
          <h1>Departments</h1>
          <p className="muted">
            {isAdmin ? 'Create and organise the departments of your company.' : 'Departments of your company (read-only).'}
          </p>
        </div>
        {isAdmin && !editing && <button onClick={() => setEditing({ mode: 'create' })}>+ New department</button>}
      </div>

      {editing && (
        <DepartmentForm
          key={editing.mode === 'edit' ? editing.department.id : 'new'}
          title={editing.mode === 'edit' ? `Edit “${editing.department.name}”` : 'New department'}
          initial={editing.mode === 'edit' ? editing.department : undefined}
          onSubmit={save}
          onCancel={() => setEditing(null)}
        />
      )}

      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}

      {departments === null && !error && <p className="muted">Loading departments…</p>}

      {departments?.length === 0 && (
        <div className="card empty">
          <p>No departments yet.</p>
          {isAdmin && <p className="muted small">Use “New department” to add the first one.</p>}
        </div>
      )}

      {departments && departments.length > 0 && (
        <div className="card table-card">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Description</th>
                {isAdmin && <th className="actions-col" aria-label="Actions" />}
              </tr>
            </thead>
            <tbody>
              {departments.map((department) => (
                <tr key={department.id}>
                  <td className="strong">{department.name}</td>
                  <td className="muted">{department.description ?? '—'}</td>
                  {isAdmin && (
                    <td className="actions">
                      <button className="link" onClick={() => setEditing({ mode: 'edit', department })}>
                        Edit
                      </button>
                      <button
                        className="link danger"
                        disabled={deletingId === department.id}
                        onClick={() => remove(department)}
                      >
                        {deletingId === department.id ? 'Deleting…' : 'Delete'}
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          <div className="table-foot muted small">
            {departments.length} department{departments.length === 1 ? '' : 's'}
          </div>
        </div>
      )}
    </section>
  )
}
