import React, { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  LineChart, Line, BarChart, Bar, XAxis, YAxis, Tooltip,
  ResponsiveContainer, CartesianGrid, Legend,
} from 'recharts'
import { useAuth } from '../../context/AuthContext'
import { getDashboard } from '../../api/dashboard'
import { messageDepartment } from '../../api/departments'

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

  if (data.mode === 'department_leader') {
    return <LeaderDashboard data={data} user={user} navigate={navigate} />
  }

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


function LeaderDashboard({ data, user, navigate }) {
  const [msgDept, setMsgDept] = useState(null)
  const cardStyle = {
    backgroundColor:'#fff', border:'1px solid var(--color-surface-border)',
    boxShadow:'0 4px 12px rgba(13,31,60,0.05)', padding:'24px',
  }
  const ROLE_PILL = {
    president: { bg:'#ffedd5', text:'#9a3412' },
    secretary: { bg:'rgba(27,58,107,0.1)', text:'var(--color-navy)' },
    leader:    { bg:'#ffedd5', text:'#9a3412' },
    member:    { bg:'#e1e2e5', text:'#44474f' },
  }
  const pill = (role) => ROLE_PILL[(role || 'member').toLowerCase()] ?? ROLE_PILL.member
  const initials = (name) => name.split(' ').map(w => w.charAt(0)).slice(0,2).join('')

  const depts = data.departments ?? []

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>
      {/* Welcome banner */}
      <section className="rounded-xl relative overflow-hidden flex justify-between items-center"
               style={{background:'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)',padding:'40px'}}>
        <div className="relative z-10">
          <h2 className="font-bold text-white" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px'}}>
            {greeting()}, {user?.name?.split(' ')[0]}
          </h2>
          <div className="flex flex-wrap items-center gap-3 mt-3">
            {depts.length === 0 ? (
              <span style={{color:'rgba(255,255,255,0.8)'}}>You are not assigned to lead a department yet.</span>
            ) : (
              <>
                <span className="inline-flex items-center rounded-full font-bold"
                      style={{padding:'6px 16px',backgroundColor:'var(--color-gold)',color:'var(--color-navy)',fontSize:'14px'}}>
                  Leading: {depts.map(d => d.name).join(', ')}
                </span>
                <span style={{color:'rgba(255,255,255,0.8)',fontSize:'14px'}}>
                  {data.totals.total_active_members} member{data.totals.total_active_members === 1 ? '' : 's'} in your care
                </span>
              </>
            )}
          </div>
        </div>
        <div className="absolute rounded-full"
             style={{right:'-10%',top:'-50%',width:'384px',height:'384px',background:'rgba(255,255,255,0.05)',filter:'blur(60px)'}}/>
      </section>

      {depts.length === 0 ? (
        <div className="rounded-xl text-center" style={{...cardStyle, padding:'48px'}}>
          <div className="text-4xl mb-3">🏛️</div>
          <div className="font-semibold" style={{color:'var(--color-navy)'}}>No department assigned</div>
          <p className="text-sm mt-1" style={{color:'#6b7280'}}>An administrator will assign you to a department to lead.</p>
        </div>
      ) : depts.map(dept => (
        <div key={dept.id} className="space-y-6">
          {/* Stat cards row — real department-meeting data */}
          <section className="grid grid-cols-2 lg:grid-cols-4 gap-6">
            {[
              { label:'Active Members', value: dept.active_members, icon: ICONS.people },
              { label:'Last Meeting', value: dept.attendance.last_present, icon: ICONS.event },
              { label:'Attendance Rate', value: `${dept.attendance.attendance_rate}%`, icon: ICONS.people },
              { label:'Meetings This Month', value: dept.attendance.meetings_this_month, icon: ICONS.event },
            ].map(s => (
              <div key={s.label} className="rounded-xl flex flex-col justify-between" style={{...cardStyle, minHeight:'130px'}}>
                <div className="flex justify-between items-start">
                  <p className="uppercase tracking-wider" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>{s.label}</p>
                  <Icon path={s.icon} color="var(--color-navy)" size={20}/>
                </div>
                <span style={{fontFamily:'var(--font-display)',fontSize:'40px',fontWeight:700,lineHeight:1,color:'var(--color-navy)'}}>
                  {s.value}
                </span>
              </div>
            ))}
          </section>

          {/* Attendance trend + quick actions */}
          <section className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="rounded-xl lg:col-span-2" style={cardStyle}>
              <h3 className="mb-4" style={{fontFamily:'var(--font-display)',fontSize:'20px',fontWeight:600,color:'var(--color-navy)'}}>
                Attendance Trend
              </h3>
              {dept.attendance.trend.length === 0 ? (
                <div className="text-center py-12" style={{color:'#9ca3af'}}>
                  <div className="text-3xl mb-2">📋</div>
                  <div className="text-sm font-semibold" style={{color:'var(--color-navy)'}}>No department meetings recorded yet</div>
                  <div className="text-xs mt-1">Use "Take Attendance" to record your first meeting.</div>
                </div>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <LineChart data={dept.attendance.trend}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2"/>
                    <XAxis dataKey="date" stroke="#9ca3af" style={{fontSize:'12px'}}/>
                    <YAxis stroke="#9ca3af" style={{fontSize:'12px'}} allowDecimals={false}/>
                    <Tooltip contentStyle={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',borderRadius:'8px',fontSize:'12px'}}/>
                    <Line type="monotone" dataKey="count" stroke="var(--color-navy)" strokeWidth={2.5}
                          dot={{ fill:'var(--color-navy)', r:4 }} activeDot={{ r:6 }} name="Present"/>
                  </LineChart>
                </ResponsiveContainer>
              )}
            </div>
            <div className="rounded-xl" style={cardStyle}>
              <h3 className="mb-4" style={{fontFamily:'var(--font-display)',fontSize:'20px',fontWeight:600,color:'var(--color-navy)'}}>Quick Actions</h3>
              <div className="space-y-3">
                <button onClick={() => navigate('/members/new')}
                        className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors"
                        style={{padding:'16px',backgroundColor:'var(--color-navy)',color:'white'}}>
                  <Icon path={ICONS.visitor} color="white" size={20}/>
                  <span style={{fontSize:'14px',fontWeight:600}}>Add Member</span>
                </button>
                <button onClick={() => navigate('/attendance/new')}
                        className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors"
                        style={{padding:'16px',backgroundColor:'white',border:'2px solid var(--color-gold)',color:'var(--color-navy)'}}>
                  <Icon path={ICONS.event} color="var(--color-navy)" size={20}/>
                  <span style={{fontSize:'14px',fontWeight:600}}>Take Attendance</span>
                </button>
                <button onClick={() => setMsgDept(dept)}
                        className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors"
                        style={{padding:'16px',backgroundColor:'#f2f3f6',border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                  <Icon path={ICONS.people} color="var(--color-navy)" size={20}/>
                  <span style={{fontSize:'14px',fontWeight:600}}>Message Members</span>
                </button>
              </div>
            </div>
          </section>

          {/* Members table + recently added */}
          <section className="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div className="rounded-xl xl:col-span-2" style={{...cardStyle, padding:0, overflow:'hidden'}}>
              <div className="flex justify-between items-center" style={{padding:'24px',borderBottom:'1px solid var(--color-surface-border)'}}>
                <h3 style={{fontFamily:'var(--font-display)',fontSize:'20px',fontWeight:600,color:'var(--color-navy)'}}>
                  {dept.name} Members
                </h3>
                <button onClick={() => navigate(`/departments/${dept.id}`)} className="text-xs font-semibold" style={{color:'var(--color-navy)'}}>View all →</button>
              </div>
              {dept.members.length === 0 ? (
                <div className="text-center" style={{padding:'32px',fontSize:'14px',color:'#9ca3af'}}>No members in this department yet</div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left">
                    <thead>
                      <tr style={{backgroundColor:'#f8f9fc'}}>
                        {['Name','Member ID','Phone','Role'].map(h => (
                          <th key={h} className="uppercase tracking-wider" style={{padding:'12px 24px',fontSize:'12px',fontWeight:700,color:'#747780'}}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {dept.members.map(m => {
                        const p = pill(m.role)
                        return (
                          <tr key={m.id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                            <td style={{padding:'14px 24px'}}>
                              <div className="flex items-center gap-3">
                                <div className="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs text-white" style={{backgroundColor:'var(--color-navy)'}}>{initials(m.name)}</div>
                                <span style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>{m.name}</span>
                              </div>
                            </td>
                            <td style={{padding:'14px 24px',fontSize:'14px',color:'#44474f'}}>{m.member_number}</td>
                            <td style={{padding:'14px 24px',fontSize:'14px',color:'#44474f'}}>{m.phone || '—'}</td>
                            <td style={{padding:'14px 24px'}}>
                              <span className="rounded-full uppercase tracking-wide" style={{padding:'3px 12px',fontSize:'11px',fontWeight:700,backgroundColor:p.bg,color:p.text}}>{m.role || 'member'}</span>
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            <div className="rounded-xl" style={cardStyle}>
              <h3 className="mb-6" style={{fontFamily:'var(--font-display)',fontSize:'20px',fontWeight:600,color:'var(--color-navy)'}}>Recently Added</h3>
              {dept.recent_members.length === 0 ? (
                <div className="text-sm" style={{color:'#9ca3af'}}>No recent additions</div>
              ) : (
                <div className="space-y-5">
                  {dept.recent_members.map((m, i) => (
                    <div key={i} className="flex items-center gap-3">
                      <div className="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white" style={{backgroundColor:'var(--color-navy)'}}>{initials(m.name)}</div>
                      <div className="flex-1 min-w-0">
                        <div className="text-sm font-semibold truncate" style={{color:'var(--color-navy)'}}>{m.name}</div>
                        <div className="uppercase tracking-widest" style={{fontSize:'10px',fontWeight:700,color:'#9ca3af'}}>
                          {m.joined_at ? `Joined ${m.joined_at}` : m.member_number}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </section>
        </div>
      ))}

      {msgDept && (
        <MessageModal dept={msgDept} onClose={() => setMsgDept(null)} />
      )}
    </div>
  )
}

function MessageModal({ dept, onClose }) {
  const [channel, setChannel] = useState('sms')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [sending, setSending] = useState(false)
  const [result, setResult] = useState(null) // {ok, text}

  const send = async () => {
    if (!body.trim()) return
    setSending(true)
    setResult(null)
    try {
      const res = await messageDepartment(dept.id, { subject: subject || null, body, channel })
      setResult({ ok: true, text: res.data.message || 'Message sent.' })
    } catch (err) {
      const msg = err.response?.data?.message || 'Could not send the message.'
      setResult({ ok: false, text: msg })
    } finally {
      setSending(false)
    }
  }

  const channels = [
    { key: 'sms', label: 'SMS' },
    { key: 'email', label: 'Email' },
    { key: 'both', label: 'Both' },
  ]

  return (
    <div className="fixed inset-0 flex items-center justify-center z-50 p-4" style={{backgroundColor:'rgba(13,31,60,0.4)',backdropFilter:'blur(4px)'}}>
      <div className="bg-white w-full max-w-lg rounded-2xl shadow-2xl">
        <div className="px-6 pt-6 pb-4 flex justify-between items-start" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <div>
            <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'22px',color:'var(--color-navy)'}}>Message {dept.name}</h2>
            <p style={{fontSize:'13px',color:'#747780',marginTop:'2px'}}>Sent to your department's members with contact details.</p>
          </div>
          <button onClick={onClose} className="p-1 rounded hover:bg-gray-100" style={{color:'#747780'}}>
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        {result?.ok ? (
          <div className="p-6">
            <div className="rounded-lg p-4" style={{backgroundColor:'#dcfce7',border:'1px solid #86efac'}}>
              <div style={{fontSize:'14px',fontWeight:700,color:'#15803d'}}>{result.text}</div>
              <p style={{fontSize:'12px',color:'#166534',marginTop:'4px'}}>Messages are delivered once an SMS provider is connected.</p>
            </div>
            <div className="flex justify-end mt-6">
              <button onClick={onClose} className="btn-primary px-6 py-2">Done</button>
            </div>
          </div>
        ) : (
          <div className="p-6 space-y-4">
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Channel</label>
              <div className="flex gap-2">
                {channels.map(c => (
                  <button key={c.key} type="button" onClick={() => setChannel(c.key)}
                          style={{padding:'8px 18px',borderRadius:'999px',fontSize:'13px',fontWeight:600,
                                  backgroundColor: channel===c.key ? 'var(--color-navy)' : '#f2f3f6',
                                  color: channel===c.key ? 'white' : 'var(--color-navy)',
                                  border:'1px solid var(--color-surface-border)'}}>
                    {c.label}
                  </button>
                ))}
              </div>
            </div>
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Subject (optional)</label>
              <input type="text" className="input-field" value={subject} onChange={e => setSubject(e.target.value)} maxLength={200}/>
            </div>
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Message *</label>
              <textarea className="input-field" rows={4} value={body} onChange={e => setBody(e.target.value)} placeholder="e.g. Choir rehearsal moved to 5pm on Saturday."/>
            </div>
            {result && !result.ok && (
              <p style={{fontSize:'13px',color:'#ba1a1a'}}>{result.text}</p>
            )}
            <p style={{fontSize:'12px',color:'#9ca3af',fontStyle:'italic'}}>Note: messages send for real once an SMS provider is connected.</p>
            <div className="flex justify-end gap-3 pt-2" style={{borderTop:'1px solid var(--color-surface-border)'}}>
              <button type="button" onClick={onClose} className="px-5 py-2 rounded-lg text-sm font-semibold"
                      style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>Cancel</button>
              <button type="button" onClick={send} disabled={sending || !body.trim()} className="btn-primary px-6 py-2" style={{opacity: (sending || !body.trim()) ? 0.6 : 1}}>
                {sending ? 'Sending...' : 'Send Message'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
