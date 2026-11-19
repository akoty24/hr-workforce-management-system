import { isRtl, locale, tr } from './i18n'

/**
 * Dates, times and amounts follow the interface language (not the browser's),
 * so Arabic month names never mix into English sentences or the other way round.
 */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value.length === 10 ? `${value}T00:00:00` : value)
  return date.toLocaleDateString(locale(), { year: 'numeric', month: 'short', day: 'numeric' })
}

export function dateRange(start: string, end: string): string {
  return start === end ? formatDate(start) : `${formatDate(start)} ${isRtl() ? '←' : '→'} ${formatDate(end)}`
}

/** 3 -> "March" / "مارس". */
export function monthName(month: number): string {
  return new Date(2000, month - 1, 1).toLocaleDateString(locale(), { month: 'long' })
}

/** Today's date in the user's local time zone, as YYYY-MM-DD. */
export function localToday(): string {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** "13:05" -> "1:05 PM" / "1:05 م". */
export function formatTime(value: string): string {
  const [h, m] = value.split(':').map(Number)
  return new Date(2000, 0, 1, h, m).toLocaleTimeString(isRtl() ? locale() : 'en-US', { hour: 'numeric', minute: '2-digit' })
}

/** 105 -> "1h 45m" / "1س 45د". */
export function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return [h && `${h}${tr('h')}`, m && `${m}${tr('m')}`].filter(Boolean).join(' ') || `0${tr('m')}`
}

/** 12500.5, "EGP" -> "EGP 12,500.50" */
export function formatMoney(amount: number, currency: string): string {
  try {
    return new Intl.NumberFormat(isRtl() ? locale() : 'en-US', { style: 'currency', currency, minimumFractionDigits: 2 }).format(amount)
  } catch {
    return `${currency} ${amount.toFixed(2)}`
  }
}
