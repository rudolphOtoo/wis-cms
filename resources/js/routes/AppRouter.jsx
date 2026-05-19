import React from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute    from './ProtectedRoute'
import AppLayout         from '../components/layout/AppLayout'
import Login             from '../pages/auth/Login'
import Dashboard         from '../pages/dashboard/Dashboard'
import MembersPage       from '../pages/members/index'
import MemberForm        from '../pages/members/MemberForm'
import VisitorsPage      from '../pages/visitors/index'
import VisitorForm       from '../pages/visitors/VisitorForm'
import DepartmentsPage   from '../pages/departments/index'
import DepartmentForm    from '../pages/departments/DepartmentForm'
import DepartmentDetail  from '../pages/departments/DepartmentDetail'
import AttendancePage    from '../pages/attendance/index'
import NewSession        from '../pages/attendance/NewSession'
import TakeAttendance    from '../pages/attendance/TakeAttendance'
import FinancePage       from '../pages/finance/index'
import TransactionForm   from '../pages/finance/TransactionForm'

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route element={<ProtectedRoute />}>
          <Route element={<AppLayout />}>
            <Route path="/"                        element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard"               element={<Dashboard />} />
            <Route path="/members"                 element={<MembersPage />} />
            <Route path="/members/new"             element={<MemberForm />} />
            <Route path="/members/:id/edit"        element={<MemberForm />} />
            <Route path="/visitors"                element={<VisitorsPage />} />
            <Route path="/visitors/new"            element={<VisitorForm />} />
            <Route path="/visitors/:id/edit"       element={<VisitorForm />} />
            <Route path="/departments"             element={<DepartmentsPage />} />
            <Route path="/departments/new"         element={<DepartmentForm />} />
            <Route path="/departments/:id"         element={<DepartmentDetail />} />
            <Route path="/departments/:id/edit"    element={<DepartmentForm />} />
            <Route path="/attendance"              element={<AttendancePage />} />
            <Route path="/attendance/new"          element={<NewSession />} />
            <Route path="/attendance/:id"          element={<TakeAttendance />} />
            <Route path="/finance"                 element={<FinancePage />} />
            <Route path="/finance/new"             element={<TransactionForm />} />
            <Route path="/finance/:id/edit"        element={<TransactionForm />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
