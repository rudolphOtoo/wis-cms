import { lazy } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute from './ProtectedRoute'
import CapabilityGate  from './CapabilityGate'
import AppLayout     from '../components/layout/AppLayout'

// ── Auth pages: loaded eagerly — the user hits these before the app shell ──
import Login          from '../pages/auth/Login'
import ForgotPassword from '../pages/auth/ForgotPassword'
import ResetPassword  from '../pages/auth/ResetPassword'
import ChangePassword from '../pages/auth/ChangePassword'
import Portal         from '../pages/portal/Portal'

// ── All authenticated pages: lazy-loaded so each route becomes its own JS
//    chunk. Vite/Rolldown splits them automatically at the dynamic import
//    boundary, eliminating the monolithic bundle.
//    Suspense lives in AppLayout (wrapping <Outlet>) so the sidebar and
//    topbar stay visible while a chunk is fetching — NOT here in the route
//    tree, which would break React Router v6's route-config traversal.
const Dashboard        = lazy(() => import('../pages/dashboard/Dashboard'))
const MembersPage      = lazy(() => import('../pages/members/index'))
const MemberForm       = lazy(() => import('../pages/members/MemberForm'))
const MemberDetail     = lazy(() => import('../pages/members/MemberDetail'))
const VisitorsPage     = lazy(() => import('../pages/visitors/index'))
const VisitorForm      = lazy(() => import('../pages/visitors/VisitorForm'))
const DepartmentsPage  = lazy(() => import('../pages/departments/index'))
const DepartmentForm   = lazy(() => import('../pages/departments/DepartmentForm'))
const DepartmentDetail = lazy(() => import('../pages/departments/DepartmentDetail'))
const CellsPage        = lazy(() => import('../pages/cells/index'))
const CellForm         = lazy(() => import('../pages/cells/CellForm'))
const CellDetail       = lazy(() => import('../pages/cells/CellDetail'))
const AttendancePage   = lazy(() => import('../pages/attendance/index'))
const NewSession       = lazy(() => import('../pages/attendance/NewSession'))
const TakeAttendance   = lazy(() => import('../pages/attendance/TakeAttendance'))
const FinancePage      = lazy(() => import('../pages/finance/index'))
const TransactionForm  = lazy(() => import('../pages/finance/TransactionForm'))
const ChildrenPage     = lazy(() => import('../pages/children/index'))
const ChildDetail      = lazy(() => import('../pages/children/ChildDetail'))
const ChildForm        = lazy(() => import('../pages/children/ChildForm'))
const CommunicationPage = lazy(() => import('../pages/communication/index'))
const Compose          = lazy(() => import('../pages/communication/Compose'))
const MessageDetail    = lazy(() => import('../pages/communication/MessageDetail'))
const UsersPage        = lazy(() => import('../pages/admin/Users'))
const AuditLog         = lazy(() => import('../pages/admin/AuditLog'))
const FollowUpSettings = lazy(() => import('../pages/settings/FollowUpSettings'))
const IncomeByCategory  = lazy(() => import('../pages/reports/IncomeByCategory'))
const ExpenseByCategory = lazy(() => import('../pages/reports/ExpenseByCategory'))
const AttendanceTrends  = lazy(() => import('../pages/reports/AttendanceTrends'))
const AttendanceSummary = lazy(() => import('../pages/reports/AttendanceSummary'))
const MemberWelfare    = lazy(() => import('../pages/reports/MemberWelfare'))
const CellComparison    = lazy(() => import('../pages/reports/CellComparison'))
const LifeEventsYear    = lazy(() => import('../pages/reports/LifeEventsYear'))
const Birthdays        = lazy(() => import('../pages/birthdays/Birthdays'))
const Reminders        = lazy(() => import('../pages/reminders/Reminders'))
const Submissions      = lazy(() => import('../pages/submissions/Submissions'))
const PastoralNotes    = lazy(() => import('../pages/pastoral/PastoralNotes'))
const FollowUpQueue    = lazy(() => import('../pages/pastoral/FollowUpQueue'))
const LifeEvents       = lazy(() => import('../pages/lifeEvents/LifeEvents'))
const Confirmations    = lazy(() => import('../pages/confirmations/Confirmations'))

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        {/* Public auth routes — eagerly loaded */}
        <Route path="/login"           element={<Login />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password"  element={<ResetPassword />} />

        <Route element={<ProtectedRoute />}>
          <Route path="/portal"          element={<Portal />} />
          <Route path="/change-password" element={<ChangePassword />} />

          {/* Authenticated routes — AppLayout provides the Suspense boundary */}
          <Route element={<AppLayout />}>
            <Route path="/"                     element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard"            element={<Dashboard />} />

            <Route path="/members"              element={<MembersPage />} />
            <Route path="/members/new"          element={<MemberForm />} />
            <Route path="/members/:id"          element={<MemberDetail />} />
            <Route path="/members/:id/edit"     element={<MemberForm />} />

            <Route path="/visitors"             element={<VisitorsPage />} />
            <Route path="/visitors/new"         element={<VisitorForm />} />
            <Route path="/visitors/:id/edit"    element={<VisitorForm />} />

            <Route path="/departments"          element={<DepartmentsPage />} />
            <Route path="/departments/new"      element={<DepartmentForm />} />
            <Route path="/departments/:id"      element={<DepartmentDetail />} />
            <Route path="/departments/:id/edit" element={<DepartmentForm />} />

            <Route path="/cells"                element={<CapabilityGate path="cells.enabled"><CellsPage /></CapabilityGate>} />
            <Route path="/cells/new"            element={<CapabilityGate path="cells.enabled"><CellForm /></CapabilityGate>} />
            <Route path="/cells/:id"            element={<CapabilityGate path="cells.enabled"><CellDetail /></CapabilityGate>} />
            <Route path="/cells/:id/edit"       element={<CapabilityGate path="cells.enabled"><CellForm /></CapabilityGate>} />

            <Route path="/attendance"           element={<AttendancePage />} />
            <Route path="/attendance/new"       element={<NewSession />} />
            <Route path="/attendance/:id"       element={<TakeAttendance />} />

            <Route path="/finance"              element={<FinancePage />} />
            <Route path="/finance/new"          element={<TransactionForm />} />
            <Route path="/finance/:id/edit"     element={<TransactionForm />} />

            <Route path="/children"             element={<ChildrenPage />} />
            <Route path="/children/new"         element={<ChildForm />} />
            <Route path="/children/:id"         element={<ChildDetail />} />
            <Route path="/children/:id/edit"    element={<ChildForm />} />

            <Route path="/communication"          element={<CommunicationPage />} />
            <Route path="/communication/compose"  element={<Compose />} />
            <Route path="/communication/:id"      element={<MessageDetail />} />

            <Route path="/admin/users"                  element={<UsersPage />} />
            <Route path="/admin/audit"                  element={<AuditLog />} />
            <Route path="/admin/settings/follow-up"     element={<FollowUpSettings />} />
            <Route path="/admin/submissions"            element={<Submissions />} />

            <Route path="/reports/finance/income-by-category"  element={<IncomeByCategory />} />
            <Route path="/reports/finance/expense-by-category" element={<ExpenseByCategory />} />
            <Route path="/reports/attendance/trends"            element={<AttendanceTrends />} />
            <Route path="/reports/attendance/summary"           element={<AttendanceSummary />} />
            <Route path="/reports/members/welfare"              element={<MemberWelfare />} />
            <Route path="/reports/cells/comparison"             element={<CellComparison />} />
            <Route path="/reports/life-events/year"             element={<LifeEventsYear />} />

            <Route path="/birthdays" element={<Birthdays />} />
            <Route path="/reminders" element={<Reminders />} />
            <Route path="/pastoral-notes" element={<PastoralNotes />} />
            <Route path="/pastoral/follow-ups" element={<FollowUpQueue />} />
            <Route path="/life-events" element={<LifeEvents />} />
            <Route path="/confirmations" element={<CapabilityGate path="modules.confirmations"><Confirmations /></CapabilityGate>} />
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
