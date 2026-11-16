import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import LocaleProvider from './LocaleProvider'
import { installDialogTranslation } from './i18n-core'
import { installAuthFetch } from './auth-token'

installDialogTranslation()
installAuthFetch()

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <LocaleProvider>
      <App />
    </LocaleProvider>
  </StrictMode>,
)
