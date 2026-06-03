import React, { useState, useEffect } from 'react'
import { Outlet, Navigate, useLocation } from 'react-router-dom'
import Sidebar from './Sidebar'
import TopBar  from './TopBar'
import { useAuth } from '../../context/AuthContext'

export default function AppLayout() {
  const { user } = useAuth()
  const { pathname } = useLocation()
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false)

  // Auto-close mobile sidebar on route change. Without this, tapping a
  // nav link leaves the sidebar overlay covering the new page.
  useEffect(() => {
    setMobileSidebarOpen(false)
  }, [pathname])

  // Lock body scroll when mobile sidebar is open so the user can't
  // accidentally scroll the background behind the overlay.
  useEffect(() => {
    if (mobileSidebarOpen) {
      document.body.style.overflow = 'hidden'
    } else {
      document.body.style.overflow = ''
    }
    return () => { document.body.style.overflow = '' }
  }, [mobileSidebarOpen])

  // Locked out until password is changed — bounce to the change screen
  if (user?.must_change_password) {
    return <Navigate to="/change-password" replace />
  }

  return (
    <div className="flex h-screen overflow-hidden" style={{backgroundColor:'var(--color-surface)'}}>
      {/* Sidebar: fixed-overlay on mobile, static on desktop */}
      <Sidebar
        isMobileOpen={mobileSidebarOpen}
        onMobileClose={() => setMobileSidebarOpen(false)}
      />

      {/* Mobile backdrop: visible only when sidebar is open. Tap to close. */}
      {mobileSidebarOpen && (
        <div
          className="md:hidden fixed inset-0 z-30"
          style={{backgroundColor:'rgba(0,0,0,0.5)'}}
          onClick={() => setMobileSidebarOpen(false)}
          aria-label="Close sidebar"
        />
      )}

      <div className="flex-1 flex flex-col overflow-hidden">
        <TopBar onMenuClick={() => setMobileSidebarOpen(true)} />
        <main className="flex-1 overflow-y-auto p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
