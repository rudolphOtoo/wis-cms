import React, { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts'
import { getAttendance, getAttendanceStats } from '../../api/attendance'
import { usePermission } from '../../hooks/usePermission'
import { TableSkeleton } from '../../components/ui/Skeletons'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
const cardBase = {
  backgroundColor: '#fff',
  border: BORDER,
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const Icon = ({ d, size = 28 }) => (
  <svg width={size} height={size} fill="none" stroke="currentColor" strokeWidth={1.6}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
)
const ICONS = {
  groups:   <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></>,
  equalizer:<><line x1="6" y1="20" x2="6" y2="12"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="18" y1="20" x2="18" y2="9"/></>,
  calendar: <><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></>,
  trendUp:  <><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></>,
  trendDown:<><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></>,
}

const AVATAR_TINTS = [
  { bg:'#c7d7fd', text:'#374766' }, { bg:'#ffdcc1', text:'#693c0a' },
  { bg:'#d7e2ff', text:'#001a40' }, { bg:'#dcfce7', text:'#15803d' },
]
const initials = (name) => name ? name.split(' ').map(w => w.charAt(0)).slice(0,2).join('').toUpperCase() : '—'
const tintFor = (name) => AVATAR_TINTS[(name?.length ?? 0) % AVATAR_TINTS.length]

export default function AttendancePage() {
  const navigate  = useNavigate()
  const { can }   = usePermission()
  const [sessions, setSessions] = useState([])
  const [stats,    setStats]    = useState(null)
  const [loading,  setLoading]  = useState(true)
  const [page,     setPage]     = useState(1)
  const [meta,     setMeta]     = useState(null)

  useEffect(() => {
    const controller = new AbortController()
    let mounted = true

    const load = async () => {
      setLoading(true)
      try {
        const [aRes, sRes] = await Promise.all([
          getAttendance({ page, per_page: 15 }, controller.signal),
          getAttendanceStats(controller.signal),
        ])
        if (!mounted) return
        setSessions(aRes.data.data)
        setMeta(aRes.data.meta)
        setStats(sRes.data.data)
      } catch (err) {
        if (!mounted || err?.code === 'ERR_CANCELED') return
        console.error(err)
      } finally {
        if (mounted) setLoading(false)
      }
    }

    load()

    return () => {
      mounted = false
      controller.abort()
    }
  }, [page])

  const wow = stats?.week_over_week_pct
  const insights = stats?.insights
  const trend = stats?.monthly_trend ?? []

  const statCards = [
    {
      label:'Last Sunday Attendance', value: stats?.last_sunday?.total ?? '—', icon: ICONS.groups,
      badge: wow !== null && wow !== undefined ? wow : null,
      sub: stats?.last_sunday?.date ? new Date(stats.last_sunday.date).toLocaleDateString('en-GB', { day:'numeric', month:'short' }) : null,
    },
    { label:'Average Attendance',      value: stats?.average ?? '—', icon: ICONS.equalizer, sub:'Recent services' },
    { label:'Total Sessions',          value: stats?.total_sessions ?? '—', icon: ICONS.calendar, sub:'All time' },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Page header */}
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <h2 className="font-bold" style={{fontFamily:FONT_DISPLAY,fontSize:'32px',lineHeight:'40px',color:NAVY}}>
            Attendance Overview
          </h2>
          <p style={{color:'#44474f'}}>Track Sunday and weekday service attendance</p>
        </div>
        {can('create attendance') && (
          <button onClick={() => navigate('/attendance/new')} className="btn-primary gap-2" style={{padding:'12px 24px'}}>
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Take Attendance
          </button>
        )}
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {statCards.map(s => (
          <div key={s.label} className="flex items-center gap-4" style={{...cardBase, padding:'24px'}}>
            <div className="flex items-center justify-center rounded-full flex-shrink-0"
                 style={{width:'56px',height:'56px',backgroundColor:'rgba(0,36,82,0.05)',color:NAVY}}>
              <Icon d={s.icon} />
            </div>
            <div>
              <p style={{fontSize:'14px',fontWeight:600,color:'#44474f'}}>{s.label}</p>
              <h3 style={{fontFamily:FONT_DISPLAY,fontSize:'24px',fontWeight:600,color:NAVY}}>{s.value}</h3>
              {s.badge !== null && s.badge !== undefined ? (
                <div className="flex items-center gap-1" style={{fontSize:'12px',fontWeight:700,color: s.badge >= 0 ? '#15803d' : '#ba1a1a'}}>
                  <Icon d={s.badge >= 0 ? ICONS.trendUp : ICONS.trendDown} size={14} />
                  <span>{s.badge >= 0 ? '+' : ''}{s.badge}% vs last week</span>
                </div>
              ) : s.sub ? (
                <p style={{fontSize:'12px',color:MUTED}}>{s.sub}</p>
              ) : null}
            </div>
          </div>
        ))}
      </div>

      {/* Records table */}
      <div style={{...cardBase, overflow:'hidden'}}>
        <div className="flex justify-between items-center"
             style={{padding:'16px 24px',borderBottom:BORDER,backgroundColor:'#f8f9fc'}}>
          <h4 className="uppercase tracking-wider" style={{fontSize:'14px',fontWeight:700,color:NAVY}}>Historical Records</h4>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{backgroundColor:'#f2f3f6'}}>
                {[['Date'],['Service', 'hidden md:table-cell'],['Adults','hidden sm:table-cell'],['Children','hidden sm:table-cell'],['Total'],['Recorded By','hidden sm:table-cell'],['Action']].map(([h, extra]) => (
                  <th key={h} className={`uppercase ${extra ?? ''}`} style={{padding:'12px 24px',fontSize:'12px',fontWeight:700,color:MUTED}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <TableSkeleton rows={8} cols={7} />
              ) : sessions.length === 0 ? (
                <tr><td colSpan={7} className="text-center" style={{padding:'48px'}}>
                  <div className="text-4xl mb-3">📋</div>
                  <div className="font-semibold" style={{color:NAVY}}>No attendance sessions yet</div>
                  <div className="text-sm mt-1" style={{color:PLACEHOLDER}}>Click "Take Attendance" to record your first session</div>
                </td></tr>
              ) : sessions.map((session) => {
                const t = tintFor(session.recorded_by)
                return (
                  <tr key={session.id} className="transition-colors hover:bg-slate-50" style={{borderTop:BORDER}}>
                    <td style={{padding:'16px 24px',fontSize:'15px',fontWeight:600,color:'#191c1e'}}>{session.service_date}</td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f'}} className="hidden md:table-cell">{session.service_type?.name ?? '—'}</td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f'}} className="hidden sm:table-cell">{session.adult_count}</td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f'}} className="hidden sm:table-cell">{session.children_count}</td>
                    <td style={{padding:'16px 24px',fontSize:'15px',fontWeight:700,color:NAVY}}>{session.total_count}</td>
                    <td style={{padding:'16px 24px'}} className="hidden sm:table-cell">
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                             style={{backgroundColor:t.bg,color:t.text}}>{initials(session.recorded_by)}</div>
                        <span style={{fontSize:'15px',color:'#191c1e'}}>{session.recorded_by ?? '—'}</span>
                      </div>
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <button onClick={() => navigate(`/attendance/${session.id}`)}
                              className="hover:underline flex items-center gap-1" style={{fontSize:'14px',fontWeight:600,color:NAVY}}>
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        View
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {meta && meta.last_page > 1 && (
          <div className="flex justify-between items-center"
               style={{padding:'16px 24px',backgroundColor:'#f8f9fc',borderTop:BORDER}}>
            <p style={{fontSize:'14px',color:'#44474f'}}>
              Page <span className="font-bold" style={{color:NAVY}}>{meta.current_page}</span> of <span className="font-bold" style={{color:NAVY}}>{meta.last_page}</span> · {meta.total} records
            </p>
            <div className="flex items-center gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="w-11 h-11 rounded-lg flex items-center justify-center disabled:opacity-50"
                      style={{border:BORDER,color:NAVY}}>
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/></svg>
              </button>
              <span className="w-10 h-10 rounded-lg flex items-center justify-center font-bold text-white" style={{backgroundColor:NAVY}}>{meta.current_page}</span>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="w-11 h-11 rounded-lg flex items-center justify-center disabled:opacity-50"
                      style={{border:BORDER,color:NAVY}}>
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/></svg>
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Bottom bento — Monthly Trend (real chart) + Insights (real facts) */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {/* Monthly Trend — REAL Recharts */}
        <div style={{...cardBase, padding:'24px'}}>
          <div className="flex justify-between items-center mb-6">
            <h4 style={{fontFamily:FONT_DISPLAY,fontSize:'24px',fontWeight:600,color:NAVY}}>Monthly Trend</h4>
            {insights && (
              <span className="px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1"
                    style={{backgroundColor: insights.trend_direction === 'up' ? '#dcfce7' : insights.trend_direction === 'down' ? '#ffdad6' : '#edeef1',
                            color: insights.trend_direction === 'up' ? '#15803d' : insights.trend_direction === 'down' ? '#ba1a1a' : '#44474f'}}>
                {insights.trend_direction === 'up' ? '↑ Rising' : insights.trend_direction === 'down' ? '↓ Declining' : '→ Steady'}
              </span>
            )}
          </div>
          {trend.length === 0 ? (
            <div className="text-center py-12 text-sm" style={{color:PLACEHOLDER}}>Not enough data yet</div>
          ) : (
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={trend}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2"/>
                <XAxis dataKey="month" stroke="#9ca3af" style={{fontSize:'12px'}}/>
                <YAxis stroke="#9ca3af" style={{fontSize:'12px'}}/>
                <Tooltip contentStyle={{backgroundColor:'white',border:BORDER,borderRadius:'8px',fontSize:'12px'}}/>
                <Bar dataKey="total" fill="var(--color-navy)" radius={[4,4,0,0]} name="Total attendance"/>
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Attendance Insights — REAL computed facts (navy card) */}
        <div className="relative overflow-hidden text-white"
             style={{borderRadius:'16px',padding:'24px',background:'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)',boxShadow:'0 4px 12px rgba(13,31,60,0.05)'}}>
          <div className="absolute rounded-full" style={{bottom:'-40px',right:'-40px',width:'192px',height:'192px',background:'rgba(255,255,255,0.05)'}}/>
          <div className="relative z-10">
            <h4 className="mb-4" style={{fontFamily:FONT_DISPLAY,fontSize:'24px',fontWeight:600}}>Attendance Insights</h4>
            {insights ? (
              <div className="space-y-4">
                <div>
                  <p style={{fontSize:'12px',color:'rgba(255,255,255,0.6)',textTransform:'uppercase',letterSpacing:'0.05em'}}>Best-attended service</p>
                  <p style={{fontSize:'18px',fontWeight:700,color:'var(--color-gold-light)'}}>{insights.top_service}</p>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p style={{fontSize:'12px',color:'rgba(255,255,255,0.6)',textTransform:'uppercase',letterSpacing:'0.05em'}}>Avg adults / service</p>
                    <p style={{fontSize:'20px',fontWeight:700}}>{insights.avg_adults}</p>
                  </div>
                  <div>
                    <p style={{fontSize:'12px',color:'rgba(255,255,255,0.6)',textTransform:'uppercase',letterSpacing:'0.05em'}}>Avg children / service</p>
                    <p style={{fontSize:'20px',fontWeight:700}}>{insights.avg_children}</p>
                  </div>
                </div>
                <div className="pt-2" style={{borderTop:'1px solid rgba(255,255,255,0.1)'}}>
                  <p style={{fontSize:'13px',color:'rgba(255,255,255,0.7)'}}>
                    {insights.trend_direction === 'up'
                      ? 'Attendance is trending upward this month — momentum worth building on.'
                      : insights.trend_direction === 'down'
                      ? 'Attendance dipped versus last month — worth a closer look at follow-up.'
                      : 'Attendance is holding steady month-over-month.'}
                  </p>
                </div>
              </div>
            ) : (
              <p style={{color:'rgba(255,255,255,0.7)'}}>Insights will appear once attendance is recorded.</p>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
