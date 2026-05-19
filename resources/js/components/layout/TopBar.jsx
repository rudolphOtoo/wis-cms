import React from 'react'
import { useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'

const titles = {
  '/dashboard':     'Dashboard',
  '/members':       'Member Management',
  '/children':      "Children's Register",
  '/attendance':    'Attendance',
  '/finance':       'Finance',
  '/departments':   'Departments',
  '/visitors':      'Visitors',
  '/communication': 'Communication',
}

export default function TopBar() {
  const { pathname } = useLocation()
  const { user }     = useAuth()

  return (
    <header className="bg-white px-6 py-4 flex items-center justify-between flex-shrink-0"
            style={{borderBottom:'1px solid var(--color-surface-border)'}}>
      <div>
        <h1 className="text-xl font-semibold" style={{fontFamily:'var(--font-display)', color:'var(--color-navy)'}}>
          {titles[pathname] ?? 'WIS-CMS'}
        </h1>
        <p className="text-xs mt-0.5" style={{color:'#9ca3af'}}>
          {new Date().toLocaleDateString('en-GH', { weekday:'long', year:'numeric', month:'long', day:'numeric' })}
        </p>
      </div>
      <div className="flex items-center gap-3">
        <div className="text-right">
          <div className="text-sm font-semibold" style={{color:'#374151'}}>{user?.name}</div>
          <div className="text-xs capitalize" style={{color:'#9ca3af'}}>
            {user?.roles?.[0]?.replace('_',' ')}
          </div>
        </div>
        <div className="w-9 h-9 rounded-full flex items-center justify-center"
             style={{backgroundColor:'rgba(27,58,107,0.1)'}}>
          <span className="text-sm font-bold" style={{color:'var(--color-navy)'}}>
            {user?.name?.charAt(0)}
          </span>
        </div>
      </div>
    </header>
  )
}
