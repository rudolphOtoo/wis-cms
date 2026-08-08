import { useEffect } from 'react'
import { Toaster } from 'sonner'
import { AuthProvider } from './context/AuthContext'
import AppRouter from './routes/AppRouter'
import { loadDiocese } from './diocese/registry'

export default function App() {
  // Warm the diocese profile (capabilities + strings) at startup so the
  // sidebar/router capability gates are decided before the user navigates.
  useEffect(() => { loadDiocese() }, [])

  return (
    <AuthProvider>
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
    </AuthProvider>
  )
}
