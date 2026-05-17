import React from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute from './ProtectedRoute'
import AppLayout      from '../components/layout/AppLayout'
import Login          from '../pages/auth/Login'
import Dashboard      from '../pages/dashboard/Dashboard'
import MembersPage    from '../pages/members/index'
import MemberForm     from '../pages/members/MemberForm'
import VisitorsPage   from '../pages/visitors/index'
import VisitorForm    from '../pages/visitors/VisitorForm'

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route element={<ProtectedRoute />}>
          <Route element={<AppLayout />}>
            <Route path="/"                    element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard"           element={<Dashboard />} />
            <Route path="/members"             element={<MembersPage />} />
            <Route path="/members/new"         element={<MemberForm />} />
            <Route path="/members/:id/edit"    element={<MemberForm />} />
            <Route path="/visitors"            element={<VisitorsPage />} />
            <Route path="/visitors/new"        element={<VisitorForm />} />
            <Route path="/visitors/:id/edit"   element={<VisitorForm />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
