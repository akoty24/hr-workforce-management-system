import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { ApiError, api, errorMessage } from '../api'
import type { DocumentType, EmployeeDocument } from '../types'
import { Icon } from './icons'
import { ErrorText, FieldError } from './ui'
import { formatDate } from '../format'
import { tr } from '../i18n'

export const DOCUMENT_TYPES: { value: DocumentType; label: string }[] = [
  { value: 'contract', label: 'Employment contract' },
  { value: 'national_id', label: 'National ID' },
  { value: 'passport', label: 'Passport' },
  { value: 'social_insurance', label: 'Social insurance' },
  { value: 'certificate', label: 'Certificate' },
  { value: 'medical', label: 'Medical' },
  { value: 'other', label: 'Other' },
]

export function documentTypeLabel(type: DocumentType): string {
  return DOCUMENT_TYPES.find((d) => d.value === type)?.label ?? type
}

function formatSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function DocumentStatus({ doc }: { doc: EmployeeDocument }) {
  if (doc.status === 'expired') return <span className="badge badge-rejected">{tr("Expired")}</span>
  if (doc.status === 'expiring') return <span className="badge badge-pending">{tr("Expires in")}{' '}{doc.days_left} {tr("days")}</span>
  return doc.expires_at ? <span className="badge badge-approved">{tr("Valid")}</span> : null
}

/** Files on an employee's record. HR/admin upload and delete; employees only view and download. */
export function DocumentsPanel({ employeeId, canEdit }: { employeeId: string; canEdit: boolean }) {
  const [docs, setDocs] = useState<EmployeeDocument[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [type, setType] = useState<DocumentType>('contract')
  const [title, setTitle] = useState('')
  const [expires, setExpires] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [errors, setErrors] = useState<ApiError | null>(null)
  const [saving, setSaving] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  const load = useCallback(async () => {
    try {
      setDocs(await api.documents.list(employeeId))
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [employeeId])

  useEffect(() => {
    let active = true
    api.documents
      .list(employeeId)
      .then((d) => active && setDocs(d))
      .catch((e) => active && setError(errorMessage(e)))
    return () => {
      active = false
    }
  }, [employeeId])

  async function upload(event: FormEvent) {
    event.preventDefault()
    if (!file) return
    setSaving(true)
    setErrors(null)
    const form = new FormData()
    form.append('file', file)
    form.append('type', type)
    form.append('title', title || documentTypeLabel(type))
    if (expires) form.append('expires_at', expires)
    try {
      await api.documents.upload(employeeId, form)
      setTitle('')
      setExpires('')
      setFile(null)
      if (fileInput.current) fileInput.current.value = ''
      await load()
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(e)
      else setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  async function remove(doc: EmployeeDocument) {
    if (!window.confirm(tr("Delete “{1}”? The file is removed permanently.", { 1: doc.title }))) return
    try {
      await api.documents.remove(doc.id)
      await load()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  return (
    <div className="form">
      <ErrorText message={error} />
      {docs === null && !error && <p className="muted">{tr("Loading…")}</p>}
      {docs?.length === 0 && <p className="muted">{tr("No documents yet.")}</p>}
      {docs && docs.length > 0 && (
        <ul className="list doc-list">
          {docs.map((d) => (
            <li key={d.id}>
              <div className="doc-main">
                <span className="doc-icon" aria-hidden>
                  <Icon name="file" size={18} />
                </span>
                <div>
                  <div className="strong">{d.title}</div>
                  <div className="muted small">
                    {tr(documentTypeLabel(d.type))} · {formatSize(d.size)}
                    {d.expires_at && <> {tr("· Expires")}{' '}{formatDate(d.expires_at)}</>}
                  </div>
                </div>
              </div>
              <div className="doc-actions">
                <DocumentStatus doc={d} />
                <a className="button secondary small-button" href={api.documents.downloadUrl(d.id)}>
                  <Icon name="download" size={14} /> {tr("Download")}
                </a>
                {canEdit && (
                  <button className="link danger" onClick={() => remove(d)}>
                    {tr("Delete")}
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {canEdit && (
        <form className="signin-box" onSubmit={upload} noValidate>
          <strong>{tr("Upload a document")}</strong>
          <div className="form-grid">
            <label>
              {tr("Type")}
              <select value={type} onChange={(e) => setType(e.target.value as DocumentType)}>
                {DOCUMENT_TYPES.map((d) => (
                  <option key={d.value} value={d.value}>
                    {tr(d.label)}
                  </option>
                ))}
              </select>
              <FieldError message={errors?.field('type')} />
            </label>
            <label>
              {tr("Title")}{' '}<span className="muted small">{tr("(optional)")}</span>
              <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} placeholder={tr(documentTypeLabel(type))} />
              <FieldError message={errors?.field('title')} />
            </label>
            <label>
              {tr("Expires on")}{' '}<span className="muted small">{tr("(optional)")}</span>
              <input type="date" value={expires} onChange={(e) => setExpires(e.target.value)} />
              <FieldError message={errors?.field('expires_at')} />
            </label>
          </div>
          <label>
            {tr("File")}{' '}<span className="muted small">{tr("(PDF, image, Word or Excel, up to 10 MB)")}</span>
            <input
              ref={fileInput}
              type="file"
              accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <FieldError message={errors?.field('file')} />
          </label>
          <div className="form-actions">
            <button type="submit" disabled={saving || !file}>
              {saving ? tr("Uploading…") : tr("Upload")}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
