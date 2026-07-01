import React from 'react'
import { Toaster } from 'sonner'
import { AuthProvider } from './context/AuthContext'
import AppRouter from './routes/AppRouter'

export default function App() {
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
