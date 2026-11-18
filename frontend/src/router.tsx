import type { MouseEvent, ReactNode } from 'react'
import { navigate } from './navigation'

/** In-app link: plain <a> for accessibility, client-side navigation on click. */
export function Link({ to, className, children }: { to: string; className?: string; children: ReactNode }) {
  function click(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return
    event.preventDefault()
    navigate(to)
  }

  return (
    <a href={to} className={className} onClick={click}>
      {children}
    </a>
  )
}
