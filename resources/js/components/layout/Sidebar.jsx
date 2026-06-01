import React from 'react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { usePermission } from '../../hooks/usePermission'

const nav = [
  { to: '/dashboard',     label: 'Dashboard',   permission: null,                d: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
  { to: '/members',       label: 'Members',     permission: 'view members',      d: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
  { to: '/children',      label: 'Children',    permission: 'view children',     d: 'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z' },
  { to: '/attendance',    label: 'Attendance',  permission: 'view attendance',   d: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4' },
  { to: '/finance',       label: 'Finance',     permission: 'view finance',      d: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
  { to: '/departments',   label: 'Departments', permission: 'view departments',  d: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4' },
  { to: '/cells',         label: 'Cells',       permission: 'view cells',        d: 'M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z' },
  { to: '/visitors',      label: 'Visitors',    permission: 'view visitors',     d: 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z' },
  { to: '/communication', label: 'Messages',    permission: 'view messages',     d: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z' },
]

const adminNav = [
  { to: '/admin/users', label: 'Users',     permission: 'manage users',   d: 'M9 7a3 3 0 11-6 0 3 3 0 016 0zM6 21v-1a4 4 0 014-4h2m6-5v6m-3-3h6' },
  { to: '/admin/audit', label: 'Audit Log', permission: 'view audit log', d: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01' },
  { to: '/admin/settings/follow-up', label: 'Follow-up SMS', permission: 'manage users', d: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z' },
]

export default function Sidebar() {
  const { user, logout } = useAuth()
  const { can }          = usePermission()

  const visibleNav      = nav.filter(item => !item.permission || can(item.permission))
  const visibleAdminNav = adminNav.filter(item => can(item.permission))

  return (
    <div className="w-64 flex flex-col h-full flex-shrink-0" style={{backgroundColor:'var(--color-navy-deeper)'}}>
      <div className="px-6 py-5" style={{borderBottom:'1px solid rgba(255,255,255,0.1)'}}>
        <div className="flex items-center gap-3">
          <img src="/images/logo.png" alt="Methodist Church Ghana Logo" className="w-9 h-9 object-contain flex-shrink-0" />
          <div>
            <div className="text-white text-sm font-bold" style={{fontFamily:'var(--font-display)'}}>WIS-CMS</div>
            <div className="text-xs" style={{color:'rgba(255,255,255,0.4)'}}>Methodist Church Ghana</div>
          </div>
        </div>
      </div>

      <nav className="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
        {visibleNav.map((item) => (
          <NavLink key={item.to} to={item.to}
            className={({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`}>
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d={item.d}/>
            </svg>
            {item.label}
          </NavLink>
        ))}

        {visibleAdminNav.length > 0 && (
          <>
            <div className="px-4 py-3 mt-2">
              <div className="text-xs font-bold uppercase tracking-wider"
                   style={{color:'rgba(255,255,255,0.4)'}}>
                Administration
              </div>
            </div>
            {visibleAdminNav.map(item => (
              <NavLink key={item.to} to={item.to}
                className={({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`}>
                <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d={item.d}/>
                </svg>
                {item.label}
              </NavLink>
            ))}
          </>
        )}
      </nav>

      <div className="px-3 py-4" style={{borderTop:'1px solid rgba(255,255,255,0.1)'}}>
        <div className="flex items-center gap-3 px-3 py-2">
          <div className="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
               style={{backgroundColor:'rgba(201,168,76,0.2)'}}>
            <span className="text-xs font-bold" style={{color:'var(--color-gold)'}}>
              {user?.name?.charAt(0) ?? 'U'}
            </span>
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-white text-xs font-semibold truncate">{user?.name}</div>
            <div className="text-xs capitalize truncate" style={{color:'rgba(255,255,255,0.4)'}}>
              {user?.roles?.[0]?.replace('_', ' ')}
            </div>
          </div>
          <button onClick={logout} title="Sign out"
                  style={{color:'rgba(255,255,255,0.3)'}}
                  className="hover:text-white transition-colors">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
          </button>
        </div>
      </div>
    </div>
  )
}
