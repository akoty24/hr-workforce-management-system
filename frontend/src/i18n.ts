import { AR } from './i18n-ar'

/**
 * Interface language. English text is the translation key: tr('Save') returns
 * the Arabic from i18n-ar.ts when Arabic is on, or the English as is (also
 * for anything not translated yet, e.g. data typed by users).
 *
 * The choice is remembered per browser; the first visit follows the browser
 * language. Arabic switches the whole page to right-to-left.
 */
export type Lang = 'en' | 'ar'

const STORAGE_KEY = 'hr-lang'
const listeners = new Set<() => void>()

function initialLang(): Lang {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'en' || saved === 'ar') return saved
  } catch {
    // storage blocked: fall back to the browser language
  }
  return navigator.language?.toLowerCase().startsWith('ar') ? 'ar' : 'en'
}

let current: Lang = initialLang()

export function lang(): Lang {
  return current
}

export function isRtl(): boolean {
  return current === 'ar'
}

/** Sets <html lang dir> for the current language. */
export function applyLang(): void {
  document.documentElement.lang = current
  document.documentElement.dir = current === 'ar' ? 'rtl' : 'ltr'
}

export function setLang(next: Lang): void {
  if (next === current) return
  current = next
  try {
    localStorage.setItem(STORAGE_KEY, next)
  } catch {
    // not remembered; still switches for this visit
  }
  applyLang()
  listeners.forEach((listener) => listener())
}

export function onLangChange(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** Translate; "{name}" placeholders are filled from vars. */
export function tr(text: string, vars?: Record<string, string | number | null | undefined>): string {
  let out = current === 'ar' ? (AR[text] ?? text) : text
  if (vars) {
    for (const [key, value] of Object.entries(vars)) out = out.split(`{${key}}`).join(String(value ?? ""))
  }
  return out
}

/** Locale for dates and numbers: Arabic month names with Western digits, as common in Egypt. */
export function locale(): string {
  return current === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB'
}
