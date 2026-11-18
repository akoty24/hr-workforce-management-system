import { useEffect, useState } from 'react'

/**
 * Minimal History-API navigation: the app has a handful of top-level pages
 * and no nested routes, so a router dependency is not needed. Laravel's SPA
 * fallback serves index.html for any path, so deep links and reloads work.
 */
const EVENT = 'app:navigate'

export function navigate(to: string) {
  if (to === window.location.pathname) return
  window.history.pushState(null, '', to)
  window.dispatchEvent(new Event(EVENT))
}

export function usePath(): string {
  const [path, setPath] = useState(window.location.pathname)

  useEffect(() => {
    const update = () => setPath(window.location.pathname)
    window.addEventListener('popstate', update)
    window.addEventListener(EVENT, update)
    return () => {
      window.removeEventListener('popstate', update)
      window.removeEventListener(EVENT, update)
    }
  }, [])

  return path
}
