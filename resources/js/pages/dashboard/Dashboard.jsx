import React from 'react'
import { useAuth } from '../../context/AuthContext'

export default function Dashboard() {
  const { user } = useAuth()
  return (
    <div className="space-y-6">
      <div className="card" style={{background:'linear-gradient(to right, var(--color-navy-deeper), var(--color-navy))',border:'none'}}>
        <h2 className="text-2xl font-bold text-white" style={{fontFamily:'var(--font-display)'}}>
          Good morning, {user?.name?.split(' ')[0]} 👋
        </h2>
        <p className="text-sm mt-1" style={{color:'rgba(255,255,255,0.6)'}}>
          Welcome to the Wesleyan International Society Church Management System.
        </p>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          {label:'Total Members',     value:'—', emoji:'👥'},
          {label:'Sunday Attendance', value:'—', emoji:'⛪'},
          {label:'This Month Income', value:'—', emoji:'💰'},
          {label:'New Visitors',      value:'—', emoji:'🙏'},
        ].map(s => (
          <div key={s.label} className="card">
            <div className="text-3xl mb-3">{s.emoji}</div>
            <div className="text-2xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>{s.value}</div>
            <div className="text-sm mt-1" style={{color:'#6b7280'}}>{s.label}</div>
          </div>
        ))}
      </div>
      <div className="card">
        <h3 className="text-lg font-semibold mb-2" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
          System Ready
        </h3>
        <p className="text-sm" style={{color:'#6b7280'}}>
          Authentication and database are fully configured. Modules are being built one by one.
        </p>
      </div>
    </div>
  )
}
