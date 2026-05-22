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

// Material-style inline icons
const Icon = ({ path, color, size = 22 }) => (
  <svg width={size} height={size} fill="none" stroke={color} strokeWidth={1.8}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
    {path}
  </svg>
)
const ICONS = {
  people:  <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></>,
  event:   <><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></>,
  money:   <><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></>,
  visitor: <><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></>,
}

const STAT_CARD = "rounded-xl flex flex-col justify-between"
const cardStyle = {
  backgroundColor:'#fff', border:'1px solid var(--color-surface-border)',
  boxShadow:'0 4px 12px rgba(13,31,60,0.05)', padding:'24px', minHeight:'140px',
}

export default function Dashboard() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getDashboard().then(res => setData(res.data.data)).catch(console.error).finally(() => setLoading(false))
  }, [])

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  const { hero, gender_split, counts, attendance_chart, finance_chart,
          top_categories, recent_members, recent_transactions } = data

  const totalGender = gender_split.male + gender_split.female
  const malePct   = totalGender > 0 ? Math.round((gender_split.male  / totalGender) * 100) : 0
  const femalePct = totalGender > 0 ? Math.round((gender_split.female/ totalGender) * 100) : 0

  const stats = [
    { label:'Active Members',        value: hero.total_members, icon: ICONS.people,  big:true },
    { label:'Last Sunday Attendance',value: hero.last_attendance, icon: ICONS.event, big:true },
    { label:'This Month Income',     value: hero.month_income, icon: ICONS.money, money:true, growth: hero.income_growth },
    { label:'New Visitors This Month',value: hero.month_visitors, icon: ICONS.visitor, big:true },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Welcome banner — navy gradient with blurred circle */}
      <section className="rounded-xl relative overflow-hidden flex justify-between items-center"
               style={{background:'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)',padding:'40px'}}>
        <div className="relative z-10">
          <h2 className="font-bold text-white" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px'}}>
            {greeting()}, {user?.name?.split(' ')[0]}
          </h2>
          <p className="mt-1" style={{color:'rgba(255,255,255,0.8)'}}>
            Here is what's happening at Wesleyan International today.
          </p>
        </div>
        <div className="absolute rounded-full"
             style={{right:'-10%',top:'-50%',width:'384px',height:'384px',background:'rgba(255,255,255,0.05)',filter:'blur(60px)'}}/>
      </section>

      {/* Stat cards */}
      <section className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {stats.map(s => (
          <div key={s.label} className={STAT_CARD} style={cardStyle}>
            <div className="flex justify-between items-start">
              <p style={{fontSize:'14px',fontWeight:600,color:'#44474f'}}>{s.label}</p>
              <Icon path={s.icon} color="var(--color-navy)" />
            </div>
            <div className="mt-6">
              {s.money ? (
                <>
                  <div className="flex items-baseline gap-1">
                    <span style={{fontFamily:'var(--font-display)',fontSize:'22px',fontWeight:700,color:'var(--color-navy)'}}>GHS</span>
                    <span style={{fontFamily:'var(--font-display)',fontSize:'40px',fontWeight:700,lineHeight:1,color:'var(--color-navy)'}}>
                      {Number(s.value).toLocaleString('en-GH')}
                    </span>
                  </div>
                  {s.growth !== null && s.growth !== undefined && (
                    <div className="flex items-center gap-1 mt-1"
                         style={{fontSize:'12px',fontWeight:700,color: s.growth >= 0 ? '#15803d' : '#ba1a1a'}}>
                      <span>{s.growth >= 0 ? '↑' : '↓'}</span>
                      <span>{Math.abs(s.growth)}% from last month</span>
                    </div>
                  )}
                </>
              ) : (
                <span style={{fontFamily:'var(--font-display)',fontSize:'48px',fontWeight:700,lineHeight:1,color:'var(--color-navy)'}}>
                  {s.value}
                </span>
              )}
            </div>
          </div>
        ))}
      </section>

      {/* Charts row — REAL Recharts, Stitch card styling */}
      <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="rounded-xl" style={cardStyle}>
          <div className="flex justify-between items-center mb-6">
            <h3 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Attendance Trend</h3>
            <button onClick={() => navigate('/attendance')} className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>View all →</button>
          </div>
          {attendance_chart.length === 0 ? (
            <div className="text-center py-12 text-sm" style={{color:'#9ca3af'}}>No attendance data yet</div>
          ) : (
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={attendance_chart}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2"/>
                <XAxis dataKey="date" stroke="#9ca3af" style={{fontSize:'12px'}}/>
                <YAxis stroke="#9ca3af" style={{fontSize:'12px'}}/>
                <Tooltip contentStyle={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',borderRadius:'8px',fontSize:'12px'}}/>
                <Line type="monotone" dataKey="count" stroke="var(--color-navy)" strokeWidth={2.5}
                      dot={{ fill:'var(--color-navy)', r:4 }} activeDot={{ r:6 }}/>
              </LineChart>
            </ResponsiveContainer>
          )}
        </div>

        <div className="rounded-xl" style={cardStyle}>
          <div className="flex justify-between items-center mb-6">
            <h3 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Finance Overview</h3>
            <button onClick={() => navigate('/finance')} className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>View all →</button>
          </div>
          <ResponsiveContainer width="100%" height={240}>
            <BarChart data={finance_chart}>
              <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2"/>
              <XAxis dataKey="month" stroke="#9ca3af" style={{fontSize:'12px'}}/>
              <YAxis stroke="#9ca3af" style={{fontSize:'12px'}} tickFormatter={fmtShort}/>
              <Tooltip contentStyle={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',borderRadius:'8px',fontSize:'12px'}} formatter={(v) => fmt(v)}/>
              <Legend wrapperStyle={{ fontSize:'12px' }}/>
              <Bar dataKey="income"   fill="#10b981" name="Income"   radius={[4,4,0,0]}/>
              <Bar dataKey="expenses" fill="#ba1a1a" name="Expenses" radius={[4,4,0,0]}/>
            </BarChart>
          </ResponsiveContainer>
        </div>
      </section>

      {/* Bottom row */}
      <section className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Membership composition */}
        <div className="rounded-xl" style={cardStyle}>
          <h3 className="mb-6" style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Membership Composition</h3>
          <div className="space-y-6">
            <div className="space-y-3">
              <div className="flex justify-between" style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>
                <span>Male</span><span>{gender_split.male} ({malePct}%)</span>
              </div>
              <div className="w-full rounded-full" style={{height:'8px',backgroundColor:'#edeef1'}}>
                <div className="rounded-full" style={{height:'8px',width:`${malePct}%`,backgroundColor:'var(--color-navy)'}}/>
              </div>
            </div>
            <div className="space-y-3">
              <div className="flex justify-between" style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>
                <span>Female</span><span>{gender_split.female} ({femalePct}%)</span>
              </div>
              <div className="w-full rounded-full" style={{height:'8px',backgroundColor:'#edeef1'}}>
                <div className="rounded-full" style={{height:'8px',width:`${femalePct}%`,backgroundColor:'var(--color-gold)'}}/>
              </div>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3 mt-6 pt-6" style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <div><div className="text-lg font-bold" style={{color:'var(--color-navy)'}}>{counts.departments}</div><div className="text-xs" style={{color:'#9ca3af'}}>Active departments</div></div>
            <div><div className="text-lg font-bold" style={{color:'#d97706'}}>{counts.pending_visitors}</div><div className="text-xs" style={{color:'#9ca3af'}}>Pending follow-ups</div></div>
          </div>
        </div>

        {/* Top income sources */}
        <div className="rounded-xl" style={cardStyle}>
          <h3 className="mb-6" style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Top Income Sources</h3>
          {top_categories.length === 0 ? (
            <div className="text-center py-8 text-sm" style={{color:'#9ca3af'}}>No income recorded this month</div>
          ) : (
            <div className="space-y-6">
              {top_categories.map((cat) => {
                const max = Math.max(...top_categories.map(c => c.total))
                const pct = max > 0 ? (cat.total / max) * 100 : 0
                return (
                  <div key={cat.name} className="space-y-3">
                    <div className="flex justify-between" style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>
                      <span>{cat.name}</span>
                      <span style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>{fmt(cat.total)}</span>
                    </div>
                    <div className="w-full rounded-full" style={{height:'8px',backgroundColor:'#edeef1'}}>
                      <div className="rounded-full" style={{height:'8px',width:`${pct}%`,backgroundColor:'#10b981'}}/>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        {/* Quick actions — 2x2 grid with hover scale */}
        <div className="rounded-xl" style={cardStyle}>
          <h3 className="mb-6" style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Quick Actions</h3>
          <div className="grid grid-cols-2 gap-3">
            {[
              { to:'/members/new',    label:'Add Member',     icon:ICONS.visitor },
              { to:'/attendance/new', label:'Take Attendance',icon:ICONS.event },
              { to:'/finance/new',    label:'Record Income',  icon:ICONS.money },
              { to:'/visitors/new',   label:'Add Visitor',    icon:ICONS.people },
            ].map(a => (
              <button key={a.to} onClick={() => navigate(a.to)}
                      className="flex flex-col items-center justify-center gap-2 rounded-xl group transition-colors"
                      style={{padding:'24px',backgroundColor:'#f2f3f6',border:'1px solid transparent'}}
                      onMouseEnter={e => { e.currentTarget.style.borderColor='rgba(27,58,107,0.2)'; e.currentTarget.querySelector('svg').style.transform='scale(1.1)' }}
                      onMouseLeave={e => { e.currentTarget.style.borderColor='transparent'; e.currentTarget.querySelector('svg').style.transform='scale(1)' }}>
                <span style={{transition:'transform 0.15s ease'}}><Icon path={a.icon} color="var(--color-navy)" /></span>
                <span style={{fontSize:'12px',fontWeight:600,textAlign:'center',color:'var(--color-navy)'}}>{a.label}</span>
              </button>
            ))}
          </div>
        </div>
      </section>

      {/* Recent activity */}
      <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="rounded-xl" style={cardStyle}>
          <div className="flex justify-between items-center mb-4">
            <h3 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Recent Members</h3>
            <button onClick={() => navigate('/members')} className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>View all →</button>
          </div>
          {recent_members.length === 0 ? (
            <div className="text-center py-6 text-sm" style={{color:'#9ca3af'}}>No members yet</div>
          ) : (
            <div className="space-y-3">
              {recent_members.map((m, i) => (
                <div key={i} className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white" style={{backgroundColor:'var(--color-navy)'}}>{m.name.charAt(0)}</div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold truncate" style={{color:'#111827'}}>{m.name}</div>
                    <div className="text-xs" style={{color:'#9ca3af'}}>{m.detail} · {m.when}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="rounded-xl" style={cardStyle}>
          <div className="flex justify-between items-center mb-4">
            <h3 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Recent Transactions</h3>
            <button onClick={() => navigate('/finance')} className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>View all →</button>
          </div>
          {recent_transactions.length === 0 ? (
            <div className="text-center py-6 text-sm" style={{color:'#9ca3af'}}>No transactions yet</div>
          ) : (
            <div className="space-y-3">
              {recent_transactions.map((t, i) => (
                <div key={i} className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-full flex items-center justify-center text-lg" style={{backgroundColor: t.type === 'income' ? '#dcfce7' : '#ffdad6'}}>
                    {t.type === 'income' ? '💰' : '📤'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold truncate" style={{color:'#111827'}}>{t.detail}</div>
                    <div className="text-xs" style={{color:'#9ca3af'}}>{t.when}</div>
                  </div>
                  <div className="text-sm font-bold flex-shrink-0" style={{color: t.type === 'income' ? '#15803d' : '#ba1a1a'}}>
                    {t.type === 'income' ? '+' : '−'} {fmt(t.amount)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>
    </div>
  )
}
