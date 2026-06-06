
import { useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'

const titles = {
  '/dashboard':     'Dashboard',
  '/members':       'Member Management',
  '/children':      "Children's Register",
  '/attendance':    'Attendance',
  '/finance':       'Finance',
  '/departments':   'Departments',
  '/cells':         'Cells & Classes',
  '/visitors':      'Visitors',
  '/communication': 'Communication',
  '/admin/users':   'User Management',
  '/admin/audit':   'Audit Log',
  '/admin/settings/follow-up': 'Follow-up SMS',
  '/reports/finance/income-by-category': 'Income by Category',
  '/reports/finance/expense-by-category': 'Expense by Category',
  '/reports/cells/comparison': 'Cell Comparison',
  '/birthdays': 'Birthday Messages',
  '/reports/attendance/trends': 'Attendance Trends',
}

export default function TopBar({ onMenuClick }) {
  const { pathname } = useLocation()
  const { user }     = useAuth()

  return (
    <header className="bg-white px-4 md:px-6 py-3 md:py-4 flex items-center justify-between flex-shrink-0 gap-3"
            style={{borderBottom:'1px solid var(--color-surface-border)'}}>
      {/* Mobile hamburger - hidden on md+ */}
      <button onClick={onMenuClick}
              className="md:hidden p-2 -ml-2 rounded-lg"
              style={{color:'var(--color-navy)'}}
              aria-label="Open menu">
        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <div className="flex-1 min-w-0">
        <h1 className="text-lg md:text-xl font-semibold truncate"
            style={{fontFamily:'var(--font-display)', color:'var(--color-navy)'}}>
          {titles[pathname] ?? 'WIS-CMS'}
        </h1>
        <p className="text-xs mt-0.5 hidden sm:block" style={{color:'#9ca3af'}}>
          {new Date().toLocaleDateString('en-GH', { weekday:'long', year:'numeric', month:'long', day:'numeric' })}
        </p>
      </div>

      <div className="flex items-center gap-3 flex-shrink-0">
        <div className="text-right hidden sm:block">
          <div className="text-sm font-semibold truncate max-w-[140px]" style={{color:'#374151'}}>{user?.name}</div>
          <div className="text-xs capitalize" style={{color:'#9ca3af'}}>
            {user?.roles?.[0]?.replace('_',' ')}
          </div>
        </div>
        <div className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
             style={{backgroundColor:'rgba(27,58,107,0.1)'}}>
          <span className="text-sm font-bold" style={{color:'var(--color-navy)'}}>
            {user?.name?.charAt(0)}
          </span>
        </div>
      </div>
    </header>
  )
}
