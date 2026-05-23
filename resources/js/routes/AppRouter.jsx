import React from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute    from './ProtectedRoute'
import AppLayout         from '../components/layout/AppLayout'
import Login             from '../pages/auth/Login'
import Dashboard         from '../pages/dashboard/Dashboard'
import MembersPage       from '../pages/members/index'
import MemberForm        from '../pages/members/MemberForm'
import MemberDetail      from '../pages/members/MemberDetail'
import Portal            from '../pages/portal/Portal'
import ChangePassword    from '../pages/auth/ChangePassword'
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
import ChildrenPage      from '../pages/children/index'
import ChildForm         from '../pages/children/ChildForm'
import UsersPage         from '../pages/admin/Users'
import AuditLog          from '../pages/admin/AuditLog'
import CommunicationPage from '../pages/communication/index'
import Compose           from '../pages/communication/Compose'
import MessageDetail     from '../pages/communication/MessageDetail'

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route element={<ProtectedRoute />}>
          <Route path="/portal" element={<Portal />} />
          <Route path="/change-password" element={<ChangePassword />} />
          <Route element={<AppLayout />}>
            <Route path="/"                     element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard"            element={<Dashboard />} />
            <Route path="/members"              element={<MembersPage />} />
            <Route path="/members/new"          element={<MemberForm />} />
            <Route path="/members/:id"           element={<MemberDetail />} />
            <Route path="/members/:id/edit"     element={<MemberForm />} />
            <Route path="/visitors"             element={<VisitorsPage />} />
            <Route path="/visitors/new"         element={<VisitorForm />} />
            <Route path="/visitors/:id/edit"    element={<VisitorForm />} />
            <Route path="/departments"          element={<DepartmentsPage />} />
            <Route path="/departments/new"      element={<DepartmentForm />} />
            <Route path="/departments/:id"      element={<DepartmentDetail />} />
            <Route path="/departments/:id/edit" element={<DepartmentForm />} />
            <Route path="/attendance"           element={<AttendancePage />} />
            <Route path="/attendance/new"       element={<NewSession />} />
            <Route path="/attendance/:id"       element={<TakeAttendance />} />
            <Route path="/finance"              element={<FinancePage />} />
            <Route path="/finance/new"          element={<TransactionForm />} />
            <Route path="/finance/:id/edit"     element={<TransactionForm />} />
            <Route path="/children"             element={<ChildrenPage />} />
            <Route path="/children/new"         element={<ChildForm />} />
            <Route path="/children/:id/edit"    element={<ChildForm />} />
            <Route path="/communication"          element={<CommunicationPage />} />
            <Route path="/communication/compose"  element={<Compose />} />
            <Route path="/communication/:id"      element={<MessageDetail />} />
            <Route path="/admin/users"          element={<UsersPage />} />
            <Route path="/admin/audit"          element={<AuditLog />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
