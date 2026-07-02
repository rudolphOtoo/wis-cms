import React, { useState, useEffect, Suspense } from 'react'
import { Outlet, Navigate, useLocation } from 'react-router-dom'
import Sidebar from './Sidebar'
import TopBar  from './TopBar'
import ErrorBoundary from '../ErrorBoundary'
import { useAuth } from '../../context/AuthContext'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
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
          {/*
            Suspense lives here — NOT in the route tree.
            React Router v6 requires all children of <Route> to be <Route>
            elements; wrapping them in <Suspense> breaks the route-config
            traversal and produces a blank page.
            Placing Suspense around the Outlet means the sidebar and topbar
            stay mounted while any lazy page chunk is fetching.
          */}
          <ErrorBoundary>
            <Suspense fallback={
              <div style={{ display:'flex', alignItems:'center', justifyContent:'center', minHeight:'60vh' }}>
                <svg style={{ width:32, height:32, color:NAVY, animation:'spin 1s linear infinite' }}
                     fill="none" viewBox="0 0 24 24">
                  <circle style={{ opacity:0.25 }} cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                  <path  style={{ opacity:0.75 }} fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
              </div>
            }>
              <Outlet />
            </Suspense>
          </ErrorBoundary>
        </main>
      </div>
    </div>
  )
}
