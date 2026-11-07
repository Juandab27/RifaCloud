import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
import { AuthProvider } from './auth/AuthContext'
import { ToastProvider } from './components/ToastProvider'
// Design system layers, in cascade order:
// tokens → base → components → ui primitives → shell → pages → admin.
import './styles/tokens.css'
import './styles/base.css'
import './styles/components.css'
import './styles/ui.css'
import './styles/shell.css'
import './styles/pages.css'
import './styles/admin.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <ToastProvider>
          <App />
        </ToastProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
