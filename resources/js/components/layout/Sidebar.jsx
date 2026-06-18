import { useState, useEffect } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { usePermission } from '../../hooks/usePermission'
import {
  LayoutDashboard, Users, GraduationCap, ClipboardCheck,
  Building2, Home, UserPlus, MessageSquare, Gift, Bell, FileText,
  UserCog, ScrollText, MessageCircle,
  Wallet, TrendingUp, TrendingDown, BarChart2, LineChart,
  ChevronDown, ChevronLeft, ChevronRight, X, LogOut,
} from 'lucide-react'

// ── Breakpoint hook — mirrors Tailwind's md: threshold (768 px) ───────────────
//
// useIsDesktop() tracks window.matchMedia so that isCollapsed is always false
// on mobile viewports, regardless of the stored collapsed toggle state.
// Without this, a desktop-collapsed sidebar bleeds its icon-only layout into
// the mobile slide-over drawer.

function useIsDesktop() {
  const [isDesktop, setIsDesktop] = useState(
    () => typeof window !== 'undefined' && window.innerWidth >= 768
  )
  useEffect(() => {
    const mq = window.matchMedia('(min-width: 768px)')
    setIsDesktop(mq.matches)
    const handler = (e) => setIsDesktop(e.matches)
    mq.addEventListener('change', handler)
    return () => mq.removeEventListener('change', handler)
  }, [])
  return isDesktop
}

// ── Nav configuration ─────────────────────────────────────────────────────────

const MAIN_NAV = [
  { to: '/dashboard',         label: 'Dashboard',   icon: LayoutDashboard, permission: null },
  { to: '/members',           label: 'Members',     icon: Users,           permission: 'view members' },
  { to: '/children',          label: 'Children',    icon: GraduationCap,   permission: 'view children' },
  { to: '/attendance',        label: 'Attendance',  icon: ClipboardCheck,  permission: 'view attendance' },
  { to: '/departments',       label: 'Departments', icon: Building2,       permission: 'view departments' },
  { to: '/cells',             label: 'Cells',       icon: Home,            permission: 'view cells' },
  { to: '/visitors',          label: 'Visitors',    icon: UserPlus,        permission: 'view visitors' },
  { to: '/communication',     label: 'Messages',    icon: MessageSquare,   permission: 'view messages' },
  { to: '/birthdays',         label: 'Birthdays',   icon: Gift,            permission: 'view birthday messages' },
  { to: '/reminders',         label: 'Reminders',   icon: Bell,            permission: 'view service reminders' },
  { to: '/admin/submissions', label: 'Submissions', icon: FileText,        permission: 'view member submissions' },
]

const FINANCE_NAV = [
  { to: '/finance',                             label: 'Overview',        icon: Wallet,       permission: 'view finance' },
  { to: '/reports/finance/income-by-category',  label: 'Income',          icon: TrendingUp,   permission: 'view finance' },
  { to: '/reports/finance/expense-by-category', label: 'Expenses',        icon: TrendingDown, permission: 'view finance' },
  { to: '/reports/cells/comparison',            label: 'Cell Comparison', icon: BarChart2,    permission: 'view finance' },
  { to: '/reports/attendance/trends',           label: 'Att. Trends',     icon: LineChart,    permission: 'view finance' },
]

const ADMIN_NAV = [
  { to: '/admin/users',              label: 'Users',         icon: UserCog,       permission: 'manage users' },
  { to: '/admin/audit',              label: 'Audit Log',     icon: ScrollText,    permission: 'view audit log' },
  { to: '/admin/settings/follow-up', label: 'Follow-up SMS', icon: MessageCircle, permission: 'manage users' },
]

// ── NavItem ───────────────────────────────────────────────────────────────────

function NavItem({ to, icon: Icon, label, isCollapsed }) {
  return (
    <NavLink to={to} title={isCollapsed ? label : undefined} className="block no-underline">
      {({ isActive }) => (
        <div className={[
          'relative flex items-center rounded-xl text-sm font-medium select-none cursor-pointer',
          'transition-all duration-200',
          isCollapsed ? 'justify-center py-[10px] px-1' : 'gap-3 px-3 py-[10px]',
          isActive
            ? 'bg-white/[0.12] text-white'
            : 'text-slate-400 hover:text-white hover:bg-white/[0.07]',
        ].join(' ')}>
          {isActive && !isCollapsed && (
            <span className="absolute left-0 top-2 bottom-2 w-[3px] bg-[#C9A84C] rounded-r-full pointer-events-none" />
          )}
          <Icon
            size={18}
            strokeWidth={1.75}
            className={`flex-shrink-0 transition-colors duration-200 ${isActive ? 'text-[#C9A84C]' : ''}`}
          />
          {!isCollapsed && <span className="truncate">{label}</span>}
        </div>
      )}
    </NavLink>
  )
}

// ── Finance accordion ─────────────────────────────────────────────────────────

function FinanceAccordion({ items, isCollapsed, isGroupActive }) {
  const [open, setOpen] = useState(isGroupActive)

  useEffect(() => {
    if (isGroupActive) setOpen(true)
  }, [isGroupActive])

  const showChildren = open && !isCollapsed

  // Icon-only mode (desktop collapsed): navigate directly to overview
  if (isCollapsed) {
    return (
      <NavLink to="/finance" end title="Finance" className="block no-underline">
        {() => (
          <div className={[
            'relative flex justify-center items-center rounded-xl py-[10px] px-1',
            'transition-all duration-200 cursor-pointer select-none',
            isGroupActive
              ? 'bg-white/[0.12] text-white'
              : 'text-slate-400 hover:text-white hover:bg-white/[0.07]',
          ].join(' ')}>
            <Wallet
              size={18}
              strokeWidth={1.75}
              className={`flex-shrink-0 ${isGroupActive ? 'text-[#C9A84C]' : ''}`}
            />
          </div>
        )}
      </NavLink>
    )
  }

  // Expanded mode (desktop full or any mobile state): accordion
  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        aria-controls="finance-subnav"
        className={[
          'relative w-full flex items-center gap-3 px-3 py-[10px] rounded-xl',
          'text-sm font-medium transition-all duration-200 cursor-pointer select-none',
          isGroupActive
            ? 'bg-white/[0.12] text-white'
            : 'text-slate-400 hover:text-white hover:bg-white/[0.07]',
        ].join(' ')}
      >
        {isGroupActive && (
          <span className="absolute left-0 top-2 bottom-2 w-[3px] bg-[#C9A84C] rounded-r-full pointer-events-none" />
        )}
        <Wallet
          size={18}
          strokeWidth={1.75}
          className={`flex-shrink-0 ${isGroupActive ? 'text-[#C9A84C]' : ''}`}
        />
        <span className="flex-1 text-left truncate">Finance</span>
        <ChevronDown
          size={14}
          strokeWidth={2}
          className={`flex-shrink-0 transition-transform duration-300 ${open ? 'rotate-180' : ''}`}
        />
      </button>

      <div
        id="finance-subnav"
        className={[
          'overflow-hidden transition-all duration-300 ease-in-out',
          showChildren ? 'max-h-[360px] opacity-100' : 'max-h-0 opacity-0',
        ].join(' ')}
      >
        <div
          className="ml-[18px] mt-1 pl-3 pb-1 space-y-0.5"
          style={{ borderLeft: '1px solid rgba(255,255,255,0.08)' }}
        >
          {items.map(({ to, label, icon: Icon }) => (
            <NavLink key={to} to={to} end className="block no-underline">
              {({ isActive }) => (
                <div className={[
                  'flex items-center gap-2.5 px-2.5 py-[7px] rounded-lg text-xs font-medium',
                  'transition-all duration-150 cursor-pointer select-none',
                  isActive
                    ? 'text-[#C9A84C] bg-white/[0.08]'
                    : 'text-slate-500 hover:text-slate-200 hover:bg-white/[0.05]',
                ].join(' ')}>
                  <Icon size={13} strokeWidth={2} className={`flex-shrink-0 ${isActive ? 'text-[#C9A84C]' : ''}`} />
                  <span className="truncate">{label}</span>
                </div>
              )}
            </NavLink>
          ))}
        </div>
      </div>
    </div>
  )
}

// ── Sidebar ───────────────────────────────────────────────────────────────────

export default function Sidebar({ isMobileOpen = false, onMobileClose }) {
  const { user, logout } = useAuth()
  const { can }          = usePermission()
  const { pathname }     = useLocation()
  const isDesktop        = useIsDesktop()
  const [collapsed, setCollapsed] = useState(false)

  // isCollapsed is ONLY true on desktop viewports — mobile always renders fully.
  // raw `collapsed` is still used for the CSS width class because that already
  // uses the md: prefix to scope the narrow width to desktop.
  const isCollapsed = collapsed && isDesktop

  const visibleMain    = MAIN_NAV.filter(i => !i.permission || can(i.permission))
  const visibleFinance = FINANCE_NAV.filter(i => can(i.permission))
  const visibleAdmin   = ADMIN_NAV.filter(i => can(i.permission))
  const showFinance    = visibleFinance.length > 0
  const showAdmin      = visibleAdmin.length > 0

  const isFinanceActive = FINANCE_NAV.some(({ to }) =>
    pathname === to || pathname.startsWith(to + '/')
  )

  const userInitial = user?.name?.charAt(0)?.toUpperCase() ?? 'U'
  const userRole    = user?.roles?.[0]?.replace(/_/g, ' ') ?? ''

  return (
    <aside
      className={[
        'flex flex-col flex-shrink-0 z-40',
        'transition-[width,transform] duration-300 ease-in-out',
        'fixed inset-y-0 left-0 md:static md:translate-x-0 md:h-full',
        isMobileOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
        // Width: mobile always w-64; desktop narrows to 72px when collapsed
        collapsed ? 'w-64 md:w-[72px]' : 'w-64',
      ].join(' ')}
      style={{ backgroundColor: 'var(--color-navy-deeper)', height: '100dvh' }}
    >

      {/* ── Header ─────────────────────────────────────────────────── */}
      <div
        className="flex items-center h-16 px-3 flex-shrink-0 gap-2"
        style={{ borderBottom: '1px solid rgba(255,255,255,0.07)' }}
      >
        <div className={`flex items-center gap-3 min-w-0 flex-1 ${isCollapsed ? 'justify-center' : ''}`}>
          <img
            src="/images/wis-logo.png"
            alt="WIS Logo"
            className={`object-contain flex-shrink-0 transition-all duration-300 ${isCollapsed ? 'w-8 h-8' : 'w-9 h-9'}`}
          />
          {!isCollapsed && (
            <div className="min-w-0">
              <div
                className="text-white text-sm font-bold leading-tight truncate"
                style={{ fontFamily: 'var(--font-display)' }}
              >
                WIS-CMS
              </div>
              <div className="text-[10px] leading-tight truncate" style={{ color: 'rgba(255,255,255,0.4)' }}>
                Methodist Church Ghana
              </div>
            </div>
          )}
        </div>

        {/* Desktop only: collapse / expand toggle */}
        <button
          type="button"
          onClick={() => setCollapsed(c => !c)}
          className="hidden md:flex items-center justify-center w-7 h-7 rounded-lg transition-colors hover:bg-white/10 flex-shrink-0"
          style={{ color: 'rgba(255,255,255,0.35)' }}
          aria-label={isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          {isCollapsed
            ? <ChevronRight size={15} strokeWidth={2} />
            : <ChevronLeft  size={15} strokeWidth={2} />
          }
        </button>

        {/* Mobile only: close drawer — 44×44 px touch target */}
        <button
          type="button"
          onClick={onMobileClose}
          className="md:hidden flex items-center justify-center w-11 h-11 rounded-xl transition-colors hover:bg-white/10 flex-shrink-0 -mr-1"
          style={{ color: 'rgba(255,255,255,0.75)' }}
          aria-label="Close menu"
        >
          <X size={20} strokeWidth={2} />
        </button>
      </div>

      {/* ── Navigation ─────────────────────────────────────────────── */}
      <nav className="flex-1 px-2 py-3 space-y-0.5 overflow-y-auto sidebar-scroll">

        {visibleMain.map(item => (
          <NavItem
            key={item.to}
            to={item.to}
            icon={item.icon}
            label={item.label}
            isCollapsed={isCollapsed}
          />
        ))}

        {showFinance && (
          <FinanceAccordion
            items={visibleFinance}
            isCollapsed={isCollapsed}
            isGroupActive={isFinanceActive}
          />
        )}

        {showAdmin && (
          <div className="pt-3 mt-1">
            {isCollapsed
              ? <div className="mx-1 mb-2 h-px" style={{ backgroundColor: 'rgba(255,255,255,0.08)' }} />
              : (
                <div className="flex items-center gap-2 px-3 pb-2">
                  <div className="h-px flex-1" style={{ backgroundColor: 'rgba(255,255,255,0.07)' }} />
                  <span
                    className="text-[9px] font-bold uppercase tracking-[0.14em]"
                    style={{ color: 'rgba(255,255,255,0.25)' }}
                  >
                    Admin
                  </span>
                  <div className="h-px flex-1" style={{ backgroundColor: 'rgba(255,255,255,0.07)' }} />
                </div>
              )
            }
            <div className="space-y-0.5">
              {visibleAdmin.map(item => (
                <NavItem
                  key={item.to}
                  to={item.to}
                  icon={item.icon}
                  label={item.label}
                  isCollapsed={isCollapsed}
                />
              ))}
            </div>
          </div>
        )}
      </nav>

      {/* ── User footer ────────────────────────────────────────────── */}
      <div
        className="flex-shrink-0 px-2 py-3"
        style={{ borderTop: '1px solid rgba(255,255,255,0.07)' }}
      >
        {isCollapsed ? (

          // Desktop icon-only footer
          <div className="flex flex-col items-center gap-2">
            <div
              className="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0"
              style={{ backgroundColor: 'rgba(201,168,76,0.2)', color: 'var(--color-gold)' }}
              title={user?.name}
            >
              {userInitial}
            </div>
            <button
              type="button"
              onClick={logout}
              title="Sign out"
              className="flex items-center justify-center w-8 h-8 rounded-lg transition-colors hover:bg-white/10"
              style={{ color: 'rgba(255,255,255,0.35)' }}
            >
              <LogOut size={14} strokeWidth={2} />
            </button>
          </div>

        ) : (

          // Mobile + desktop expanded footer — always shows full name, role, and logout
          <div className="flex items-center gap-3 px-2 py-1.5 rounded-xl hover:bg-white/[0.04] transition-colors group">
            <div
              className="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0"
              style={{ backgroundColor: 'rgba(201,168,76,0.2)', color: 'var(--color-gold)' }}
            >
              {userInitial}
            </div>
            <div className="flex-1 min-w-0">
              <div className="text-white text-xs font-semibold truncate leading-tight">
                {user?.name}
              </div>
              <div
                className="text-[10px] capitalize truncate leading-tight"
                style={{ color: 'rgba(255,255,255,0.45)' }}
              >
                {userInitial} · {userRole}
              </div>
            </div>
            {/* Logout: always visible on mobile (no hover); fades in on desktop hover */}
            <button
              type="button"
              onClick={logout}
              title="Sign out"
              className="flex items-center justify-center min-w-[44px] min-h-[44px] rounded-xl transition-all hover:bg-white/10 flex-shrink-0 opacity-100 md:opacity-0 md:group-hover:opacity-100"
              style={{ color: 'rgba(255,255,255,0.5)' }}
            >
              <LogOut size={15} strokeWidth={2} />
            </button>
          </div>

        )}
      </div>

    </aside>
  )
}
