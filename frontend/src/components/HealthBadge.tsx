import { useEffect, useState } from 'react'
import { api } from '../api'

/** Small live indicator backed by /health/ready (checks the database). */
export function HealthBadge() {
  const [ok, setOk] = useState<boolean | null>(null)

  useEffect(() => {
    let active = true
    const check = () => api.health().then((result) => active && setOk(result))

    check()
    const timer = window.setInterval(check, 30_000)
    return () => {
      active = false
      window.clearInterval(timer)
    }
  }, [])

  const label = ok === null ? 'Checking…' : ok ? 'System healthy' : 'Database unavailable'

  return (
    <span className="health">
      <span className={`dot ${ok === null ? '' : ok ? 'ok' : 'fail'}`} aria-hidden />
      {label}
    </span>
  )
}
