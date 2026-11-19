import { StrictMode, useEffect, useState } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { applyLang, lang, onLangChange } from './i18n'

applyLang()

/** Re-renders the whole app when the language changes (no reload, state kept). */
function Root() {
  const [, setCurrent] = useState(lang())
  useEffect(() => onLangChange(() => setCurrent(lang())), [])
  return <App />
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Root />
  </StrictMode>,
)
