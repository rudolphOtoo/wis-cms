import React from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute from './ProtectedRoute'
import AppLayout      from '../components/layout/AppLayout'
import Login          from '../pages/auth/Login'
import Dashboard      from '../pages/dashboard/Dashboard'

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route element={<ProtectedRoute />}>
          <Route element={<AppLayout />}>
            <Route path="/"          element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard" element={<Dashboard />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
