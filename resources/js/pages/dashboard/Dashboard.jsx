import React, { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  LineChart, Line, BarChart, Bar, XAxis, YAxis, Tooltip,
  ResponsiveContainer, CartesianGrid, Legend,
} from 'recharts'
import { useAuth } from '../../context/AuthContext'
import { getDashboard } from '../../api/dashboard'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const fmtShort = (n) => {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000)     return `${(n / 1_000).toFixed(1)}K`
  return n
}

const greeting = () => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
}

export default function Dashboard() {
  const { user }   = useAuth()
  const navigate   = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getDashboard()
      .then(res => setData(res.data.data))
      .catch(console.error)
      .finally(() => setLoading(false))
  }, [])

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24">
        <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
          <path className="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
      </div>
    )
  }

  const { hero, gender_split, counts,
          attendance_chart, finance_chart, top_categories,
          recent_members, recent_transactions } = data

  const totalGender = gender_split.male + gender_split.female
  const malePct     = totalGender > 0 ? Math.round((gender_split.male  / totalGender) * 100) : 0
  const femalePct   = totalGender > 0 ? Math.round((gender_split.female/ totalGender) * 100) : 0

  return (
    <div className="space-y-6">

      {/* Welcome Banner */}
      <div className="card relative overflow-hidden"
           style={{background:'linear-gradient(135deg,var(--color-navy-deeper),var(--color-navy))',border:'none'}}>
        <div className="absolute -top-20 -right-20 w-64 h-64 rounded-full blur-3xl"
             style={{backgroundColor:'rgba(201,168,76,0.15)'}}/>
        <div className="relative z-10">
          <h2 className="text-2xl font-bold text-white"
              style={{fontFamily:'var(--font-display)'}}>
            {greeting()}, {user?.name?.split(' ')[0]} 👋
          </h2>
          <p className="text-sm mt-1" style={{color:'rgba(255,255,255,0.7)'}}>
            Here's what's happening at Wesleyan International Society today.
          </p>
        </div>
      </div>

      {/* Hero Stats */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

        {/* Members */}
        <div className="card relative overflow-hidden">
          <div className="absolute top-0 right-0 w-20 h-20 rounded-full -mt-8 -mr-8"
               style={{backgroundColor:'rgba(27,58,107,0.05)'}}/>
          <div className="flex items-start justify-between mb-3 relative">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center"
                 style={{backgroundColor:'rgba(27,58,107,0.1)'}}>
              <svg className="w-5 h-5" style={{color:'var(--color-navy)'}}
                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
            </div>
          </div>
          <div className="text-3xl font-bold mb-1" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {hero.total_members}
          </div>
          <div className="text-xs" style={{color:'#6b7280'}}>Active Members</div>
        </div>

        {/* Attendance */}
        <div className="card relative overflow-hidden">
          <div className="absolute top-0 right-0 w-20 h-20 rounded-full -mt-8 -mr-8"
               style={{backgroundColor:'rgba(20,184,166,0.05)'}}/>
          <div className="flex items-start justify-between mb-3 relative">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center"
                 style={{backgroundColor:'rgba(20,184,166,0.1)'}}>
              <svg className="w-5 h-5" style={{color:'#0d9488'}}
                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
              </svg>
            </div>
          </div>
          <div className="text-3xl font-bold mb-1" style={{fontFamily:'var(--font-display)',color:'#0d9488'}}>
            {hero.last_attendance}
          </div>
          <div className="text-xs" style={{color:'#6b7280'}}>Last Sunday Attendance</div>
        </div>

        {/* Income */}
        <div className="card relative overflow-hidden">
          <div className="absolute top-0 right-0 w-20 h-20 rounded-full -mt-8 -mr-8"
               style={{backgroundColor:'rgba(22,163,74,0.05)'}}/>
          <div className="flex items-start justify-between mb-3 relative">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center"
                 style={{backgroundColor:'rgba(22,163,74,0.1)'}}>
              <svg className="w-5 h-5" style={{color:'#16a34a'}}
                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
              </svg>
            </div>
            {hero.income_growth !== null && (
              <span className="px-2 py-0.5 rounded-full text-xs font-bold"
                    style={{
                      backgroundColor: hero.income_growth >= 0 ? '#dcfce7' : '#fee2e2',
                      color:           hero.income_growth >= 0 ? '#15803d' : '#dc2626',
                    }}>
                {hero.income_growth >= 0 ? '↑' : '↓'} {Math.abs(hero.income_growth)}%
              </span>
            )}
          </div>
          <div className="text-2xl font-bold mb-1"
               style={{fontFamily:'var(--font-display)',color:'#16a34a'}}>
            {fmt(hero.month_income)}
          </div>
          <div className="text-xs" style={{color:'#6b7280'}}>This Month — Income</div>
        </div>

        {/* Visitors */}
        <div className="card relative overflow-hidden">
          <div className="absolute top-0 right-0 w-20 h-20 rounded-full -mt-8 -mr-8"
               style={{backgroundColor:'rgba(124,58,237,0.05)'}}/>
          <div className="flex items-start justify-between mb-3 relative">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center"
                 style={{backgroundColor:'rgba(124,58,237,0.1)'}}>
              <svg className="w-5 h-5" style={{color:'#7c3aed'}}
                   fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
              </svg>
            </div>
          </div>
          <div className="text-3xl font-bold mb-1"
               style={{fontFamily:'var(--font-display)',color:'#7c3aed'}}>
            {hero.month_visitors}
          </div>
          <div className="text-xs" style={{color:'#6b7280'}}>New Visitors This Month</div>
        </div>
      </div>

      {/* Charts row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {/* Attendance trend */}
        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h3 className="font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
                Attendance Trend
              </h3>
              <p className="text-xs" style={{color:'#9ca3af'}}>Last 8 Sunday services</p>
            </div>
            <button onClick={() => navigate('/attendance')}
                    className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>
              View all →
            </button>
          </div>
          {attendance_chart.length === 0 ? (
            <div className="text-center py-12 text-sm" style={{color:'#9ca3af'}}>
              No attendance data yet
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <LineChart data={attendance_chart}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2"/>
                <XAxis dataKey="date" stroke="#9ca3af" style={{fontSize:'12px'}}/>
                <YAxis stroke="#9ca3af" style={{fontSize:'12px'}}/>
                <Tooltip contentStyle={{
                  backgroundColor:'white',
                  border:'1px solid var(--color-surface-border)',
                  borderRadius:'8px',
                  fontSize:'12px',
                }}/>
                <Line type="monotone" dataKey="count"
                      stroke="var(--color-navy)" strokeWidth={2.5}
                      dot={{ fill:'var(--color-navy)', r:4 }}
                      activeDot={{ r:6 }}/>
              </LineChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Finance trend */}
        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h3 className="font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
                Finance Overview
              </h3>
              <p className="text-xs" style={{color:'#9ca3af'}}>Income vs Expenses — last 6 months</p>
            </div>
            <button onClick={() => navigate('/finance')}
                    className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>
              View all →
            </button>
          </div>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={finance_chart}>
              <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2"/>
              <XAxis dataKey="month" stroke="#9ca3af" style={{fontSize:'12px'}}/>
              <YAxis stroke="#9ca3af" style={{fontSize:'12px'}}
                     tickFormatter={fmtShort}/>
              <Tooltip
                contentStyle={{
                  backgroundColor:'white',
                  border:'1px solid var(--color-surface-border)',
                  borderRadius:'8px',
                  fontSize:'12px',
                }}
                formatter={(value) => fmt(value)}/>
              <Legend wrapperStyle={{ fontSize:'12px' }}/>
              <Bar dataKey="income"   fill="#16a34a" name="Income"   radius={[4,4,0,0]}/>
              <Bar dataKey="expenses" fill="#dc2626" name="Expenses" radius={[4,4,0,0]}/>
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Bottom row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* Gender split */}
        <div className="card">
          <h3 className="font-bold mb-4"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Membership Composition
          </h3>
          <div className="space-y-4">
            <div>
              <div className="flex justify-between text-sm mb-1.5">
                <span style={{color:'#374151'}}>👨 Male</span>
                <span className="font-semibold" style={{color:'var(--color-navy)'}}>
                  {gender_split.male} ({malePct}%)
                </span>
              </div>
              <div className="h-2 rounded-full overflow-hidden" style={{backgroundColor:'#f3f4f6'}}>
                <div className="h-full rounded-full"
                     style={{width:`${malePct}%`,backgroundColor:'var(--color-navy)'}}/>
              </div>
            </div>
            <div>
              <div className="flex justify-between text-sm mb-1.5">
                <span style={{color:'#374151'}}>👩 Female</span>
                <span className="font-semibold" style={{color:'#a855f7'}}>
                  {gender_split.female} ({femalePct}%)
                </span>
              </div>
              <div className="h-2 rounded-full overflow-hidden" style={{backgroundColor:'#f3f4f6'}}>
                <div className="h-full rounded-full"
                     style={{width:`${femalePct}%`,backgroundColor:'#a855f7'}}/>
              </div>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3 mt-4 pt-4"
               style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <div>
              <div className="text-lg font-bold" style={{color:'var(--color-navy)'}}>
                {counts.departments}
              </div>
              <div className="text-xs" style={{color:'#9ca3af'}}>Active departments</div>
            </div>
            <div>
              <div className="text-lg font-bold" style={{color:'#d97706'}}>
                {counts.pending_visitors}
              </div>
              <div className="text-xs" style={{color:'#9ca3af'}}>Pending follow-ups</div>
            </div>
          </div>
        </div>

        {/* Top categories */}
        <div className="card">
          <h3 className="font-bold mb-4"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Top Income Sources
          </h3>
          {top_categories.length === 0 ? (
            <div className="text-center py-8 text-sm" style={{color:'#9ca3af'}}>
              No income recorded this month
            </div>
          ) : (
            <div className="space-y-3">
              {top_categories.map((cat, i) => {
                const max = Math.max(...top_categories.map(c => c.total))
                const pct = max > 0 ? (cat.total / max) * 100 : 0
                return (
                  <div key={cat.name}>
                    <div className="flex justify-between text-sm mb-1.5">
                      <span style={{color:'#374151'}}>{cat.name}</span>
                      <span className="font-semibold" style={{color:'#16a34a'}}>
                        {fmt(cat.total)}
                      </span>
                    </div>
                    <div className="h-1.5 rounded-full overflow-hidden"
                         style={{backgroundColor:'#f3f4f6'}}>
                      <div className="h-full rounded-full"
                           style={{width:`${pct}%`,
                                   backgroundColor: i === 0 ? '#16a34a'
                                                   : i === 1 ? '#22c55e'
                                                   : i === 2 ? '#4ade80'
                                                   : '#86efac'}}/>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        {/* Quick actions */}
        <div className="card">
          <h3 className="font-bold mb-4"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Quick Actions
          </h3>
          <div className="grid grid-cols-2 gap-2">
            {[
              { to:'/members/new',    label:'Add Member',     emoji:'👤' },
              { to:'/attendance/new', label:'Take Attendance', emoji:'✓' },
              { to:'/finance/new',    label:'Record Income',  emoji:'💰' },
              { to:'/visitors/new',   label:'Add Visitor',    emoji:'🙏' },
            ].map(a => (
              <button key={a.to} onClick={() => navigate(a.to)}
                      className="p-3 rounded-xl text-left transition-all hover:shadow-md"
                      style={{backgroundColor:'#f9fafb',border:'1px solid var(--color-surface-border)'}}>
                <div className="text-2xl mb-1">{a.emoji}</div>
                <div className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>
                  {a.label}
                </div>
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Recent activity */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-bold"
                style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              Recent Members
            </h3>
            <button onClick={() => navigate('/members')}
                    className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>
              View all →
            </button>
          </div>
          {recent_members.length === 0 ? (
            <div className="text-center py-6 text-sm" style={{color:'#9ca3af'}}>
              No members yet
            </div>
          ) : (
            <div className="space-y-3">
              {recent_members.map((m, i) => (
                <div key={i} className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-full flex items-center justify-center
                                  text-sm font-bold text-white"
                       style={{backgroundColor:'var(--color-navy)'}}>
                    {m.name.charAt(0)}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold truncate" style={{color:'#111827'}}>
                      {m.name}
                    </div>
                    <div className="text-xs" style={{color:'#9ca3af'}}>
                      {m.detail} · {m.when}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-bold"
                style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              Recent Transactions
            </h3>
            <button onClick={() => navigate('/finance')}
                    className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>
              View all →
            </button>
          </div>
          {recent_transactions.length === 0 ? (
            <div className="text-center py-6 text-sm" style={{color:'#9ca3af'}}>
              No transactions yet
            </div>
          ) : (
            <div className="space-y-3">
              {recent_transactions.map((t, i) => (
                <div key={i} className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-full flex items-center justify-center text-lg"
                       style={{backgroundColor: t.type === 'income' ? '#dcfce7' : '#fee2e2'}}>
                    {t.type === 'income' ? '💰' : '📤'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold truncate" style={{color:'#111827'}}>
                      {t.detail}
                    </div>
                    <div className="text-xs" style={{color:'#9ca3af'}}>{t.when}</div>
                  </div>
                  <div className="text-sm font-bold flex-shrink-0"
                       style={{color: t.type === 'income' ? '#16a34a' : '#dc2626'}}>
                    {t.type === 'income' ? '+' : '−'} {fmt(t.amount)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
