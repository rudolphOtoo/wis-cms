import { useState, useEffect, useCallback } from 'react'
import { Toaster } from 'sonner'
import { AuthProvider, useAuth } from './context/AuthContext'
import { keepAlive } from './api/auth'
import useIdleTimer from './hooks/useIdleTimer'
import IdleWarningModal from './components/IdleWarningModal'
import AppRouter from './routes/AppRouter'
import { loadDiocese } from './diocese/registry'

const SESSION_TIMEOUT = 15 * 60
const WARNING_BEFORE = 60

function IdleTimer({ children }) {
  const { isAuthenticated, logout } = useAuth()
  const [modalOpen, setModalOpen] = useState(false)
  const [remaining, setRemaining] = useState(WARNING_BEFORE)

  const handleTimeout = useCallback(() => {
    setModalOpen(false)
    logout()
    window.location.href = '/login?reason=idle_timeout'
  }, [logout])

  const handleWarning = useCallback(() => {
    setModalOpen(true)
    setRemaining(WARNING_BEFORE)
  }, [])

  const { reset } = useIdleTimer({
    timeout: SESSION_TIMEOUT,
    warningTimeout: SESSION_TIMEOUT - WARNING_BEFORE,
    onTimeout: handleTimeout,
    onWarning: handleWarning,
  })

  useEffect(() => {
    if (!modalOpen) return

    const interval = setInterval(() => {
      setRemaining((prev) => {
        if (prev <= 1) {
          clearInterval(interval)
          return 0
        }
        return prev - 1
      })
    }, 1000)

    return () => clearInterval(interval)
  }, [modalOpen])

  const handleStayLoggedIn = useCallback(async () => {
    try {
      await keepAlive()
    } catch {
      /* keep-alive failed — session may already be expired */
    }
    setModalOpen(false)
    reset()
  }, [reset])

  const handleLogoutNow = useCallback(() => {
    setModalOpen(false)
    logout()
    window.location.href = '/login?reason=idle_timeout'
  }, [logout])

  if (!isAuthenticated) {
    return children
  }

  return (
    <>
      {children}
      <IdleWarningModal
        open={modalOpen}
        remainingSeconds={remaining}
        onStayLoggedIn={handleStayLoggedIn}
        onLogoutNow={handleLogoutNow}
      />
    </>
  )
}

export default function App() {
  useEffect(() => { loadDiocese() }, [])

  return (
    <AuthProvider>
      <IdleTimer>
        <Toaster
          position="top-right"
          richColors
          closeButton
          toastOptions={{
            style: {
              fontFamily: 'Nunito, sans-serif',
              fontSize: '14px',
            },
          }}
        />
        <AppRouter />
      </IdleTimer>
    </AuthProvider>
  )
}
