import { useState, useEffect, useCallback } from 'react'
import { useAuth } from '../../context/AuthContext'
import { getPortalProfile, getPortalGiving, getPortalAttendance } from '../../api/portal'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const Icon = ({ d, size = 22 }) => (
  <svg width={size} height={size} fill="none" stroke="currentColor" strokeWidth={1.8}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
)
const ICONS = {
  savings: <><path d="M19 5c-1.5 0-2.8 1.4-3 2-3.5-1.5-11-.3-11 5 0 1.8 0 3 2 4.5V20h4v-2h3v2h4v-4c1-.5 1.7-1 2-2h2v-4h-2c0-1-.5-1.5-1-2V5z"/><path d="M2 9v1c0 1.1.9 2 2 2h1M16 11h0"/></>,
  redeem:  <><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></>,
  hands:   <><path d="M11 14h2a2 2 0 002-2 2 2 0 00-2-2H9.5a2 2 0 00-1.4.6L5 13M3 12l3 3 7-1M7 18l-2-2"/></>,
}
const CAT_ICONS = ['savings', 'redeem', 'hands']

export default function Portal() {
  const { logout } = useAuth()
  const [tab, setTab] = useState('giving')

  const [profile,    setProfile]    = useState(null)
  const [giving,     setGiving]     = useState(null)
  const [givingYear, setGivingYear] = useState(null)
  const [attendance, setAttendance] = useState(null)
  const [loading,    setLoading]    = useState(true)

  const loadGiving = useCallback(async (year) => {
    const res = await getPortalGiving(year)
    setGiving(res.data.data)
    setGivingYear(res.data.data.year)
  }, [])

  useEffect(() => {
    Promise.all([
      getPortalProfile().then(r => setProfile(r.data.data)),
      loadGiving(),
      getPortalAttendance().then(r => setAttendance(r.data.data)),
    ]).catch(console.error).finally(() => setLoading(false))
  }, [loadGiving])

  const initials = profile?.full_name
    ? profile.full_name.split(' ').map(w => w.charAt(0)).slice(0, 2).join('')
    : '?'

  if (loading) return (
    <div className="min-h-screen flex items-center justify-center" style={{backgroundColor:'var(--color-surface)'}}>
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  const TABS = [
    { k: 'giving',     label: 'My Giving' },
    { k: 'attendance', label: 'My Attendance' },
    { k: 'profile',    label: 'My Profile' },
  ]

  return (
    <div className="min-h-screen" style={{backgroundColor:'var(--color-surface)'}}>

      {/* Top bar */}
      <header className="shadow-sm" style={{backgroundColor:'var(--color-navy)'}}>
        <div className="flex justify-between items-center max-w-5xl mx-auto px-6 h-16">
          <div className="flex items-center gap-3">
            <img src="/images/wis-logo.png" alt="Wesleyan International Society Logo" className="w-8 h-8 object-contain" />
            <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'22px',color:'var(--color-gold-light)'}}>WIS-CMS</h1>
          </div>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-3 pr-4" style={{borderRight:'1px solid rgba(255,255,255,0.1)'}}>
              <div className="text-right hidden sm:block">
                <p className="leading-none" style={{fontSize:'14px',fontWeight:600,color:'white'}}>{profile?.full_name}</p>
                <p className="uppercase tracking-widest mt-1" style={{fontSize:'10px',color:'rgba(255,255,255,0.5)'}}>Member</p>
              </div>
              <div className="w-10 h-10 rounded-full flex items-center justify-center font-bold"
                   style={{backgroundColor:'var(--color-primary-container,#1b3a6b)',color:'var(--color-gold-light)',border:'1px solid rgba(255,255,255,0.2)'}}>
                {initials}
              </div>
            </div>
            <button onClick={logout} className="flex items-center gap-2 transition-colors"
                    style={{fontSize:'14px',fontWeight:600,color:'rgba(255,255,255,0.7)'}}>
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
              <span className="hidden sm:inline">Sign Out</span>
            </button>
          </div>
        </div>
      </header>

      <main className="max-w-5xl mx-auto px-6 py-8">

        {/* Welcome banner */}
        <section className="mb-8">
          <h2 className="font-bold mb-3" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
            Welcome back, {profile?.full_name?.split(' ')[0]}
          </h2>
          <div className="flex flex-wrap gap-3 items-center">
            <span className="inline-flex items-center rounded-full" style={{padding:'4px 12px',fontSize:'12px',fontWeight:600,backgroundColor:'rgba(0,36,82,0.05)',color:'var(--color-navy)',border:'1px solid rgba(0,36,82,0.1)'}}>
              Membership ID: <strong className="ml-1">{profile?.member_number}</strong>
            </span>
            {profile?.join_date && (
              <span className="inline-flex items-center rounded-full" style={{padding:'4px 12px',fontSize:'12px',fontWeight:600,backgroundColor:'rgba(78,94,127,0.08)',color:'#4e5e7f',border:'1px solid rgba(78,94,127,0.15)'}}>
                Member since: <strong className="ml-1">{profile.join_date}</strong>
              </span>
            )}
          </div>
        </section>

        {/* Tabs with gold underline */}
        <div className="mb-8" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <div className="flex gap-8">
            {TABS.map(t => {
              const active = tab === t.k
              return (
                <button key={t.k} onClick={() => setTab(t.k)}
                        className="relative transition-colors" style={{padding:'16px 8px',fontSize:'14px',
                                fontWeight: active ? 700 : 600, color: active ? 'var(--color-navy)' : '#747780'}}>
                  {t.label}
                  {active && <div style={{position:'absolute',bottom:'-1px',left:0,height:'3px',width:'100%',backgroundColor:'var(--color-gold)'}}/>}
                </button>
              )
            })}
          </div>
        </div>

        {/* GIVING */}
        {tab === 'giving' && giving && (
          <div className="space-y-6">
            {/* Hero */}
            <div className="relative overflow-hidden" style={{borderRadius:'16px',padding:'32px',backgroundColor:'var(--color-navy)',boxShadow:'0 4px 12px rgba(13,31,60,0.05)'}}>
              <div className="absolute pointer-events-none" style={{right:'-80px',top:'-80px',width:'320px',height:'320px',borderRadius:'9999px',backgroundColor:'rgba(255,255,255,0.05)'}}/>
              <div className="absolute pointer-events-none" style={{left:'-40px',bottom:'-40px',width:'160px',height:'160px',borderRadius:'9999px',backgroundColor:'rgba(201,168,76,0.1)'}}/>
              <div className="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                  <p className="uppercase tracking-widest mb-2" style={{fontSize:'14px',fontWeight:600,color:'var(--color-gold)'}}>Total Given in {givingYear}</p>
                  <h3 style={{fontFamily:'var(--font-display)',fontSize:'48px',fontWeight:700,color:'white',lineHeight:1}}>{fmt(giving.total)}</h3>
                </div>
                {giving.available_years?.length > 1 && (
                  <select value={givingYear ?? ''} onChange={e => loadGiving(e.target.value)}
                          className="rounded-lg relative z-10" style={{backgroundColor:'rgba(255,255,255,0.15)',color:'white',border:'none',padding:'8px 12px',fontSize:'14px'}}>
                    {giving.available_years.map(y => <option key={y} value={y} style={{color:'#111'}}>{y}</option>)}
                  </select>
                )}
              </div>
            </div>

            {giving.total === 0 ? (
              <div className="text-center py-10" style={{...cardBase, padding:'40px'}}>
                <div className="text-3xl mb-2">💝</div>
                <div style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>No giving recorded for {givingYear}</div>
              </div>
            ) : (
              <>
                {/* Category cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                  {giving.by_category.map((cat, i) => (
                    <div key={cat.category} className="flex items-center gap-5 transition-colors group" style={{...cardBase, padding:'24px'}}>
                      <div className="w-14 h-14 rounded-full flex items-center justify-center transition-all"
                           style={{backgroundColor:'rgba(201,168,76,0.1)',color:'var(--color-gold)'}}>
                        <Icon d={ICONS[CAT_ICONS[i % CAT_ICONS.length]]} size={28} />
                      </div>
                      <div>
                        <p className="uppercase" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>{cat.category} ({cat.count})</p>
                        <p style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>{fmt(cat.total)}</p>
                      </div>
                    </div>
                  ))}
                </div>

                {/* Transactions */}
                <div style={{...cardBase, overflow:'hidden'}}>
                  <div className="flex justify-between items-center" style={{padding:'24px 32px',borderBottom:'1px solid var(--color-surface-border)'}}>
                    <h4 style={{fontFamily:'var(--font-display)',fontSize:'20px',fontWeight:600,color:'var(--color-navy)'}}>My Transactions</h4>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                      <thead>
                        <tr style={{backgroundColor:'#f8f9fc'}}>
                          {['Date','Category','Amount (GHS)','Reference'].map(h => (
                            <th key={h} className="uppercase tracking-wider" style={{padding:'16px 32px',fontSize:'12px',fontWeight:700,color:'#747780'}}>{h}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {giving.transactions.map(t => (
                          <tr key={t.id} className="transition-colors" style={{borderTop:'1px solid var(--color-surface-border)'}}
                              onMouseEnter={e => e.currentTarget.style.backgroundColor='#f8f9fc'}
                              onMouseLeave={e => e.currentTarget.style.backgroundColor='transparent'}>
                            <td style={{padding:'20px 32px',fontSize:'15px',color:'#191c1e'}}>{t.date}</td>
                            <td style={{padding:'20px 32px'}}>
                              <span className="inline-flex items-center rounded" style={{padding:'4px 8px',fontSize:'12px',fontWeight:600,backgroundColor:'rgba(199,215,253,0.4)',color:'#4e5e7f'}}>{t.category}</span>
                            </td>
                            <td style={{padding:'20px 32px',fontSize:'15px',fontWeight:700,color:'var(--color-navy)'}}>{fmt(t.amount)}</td>
                            <td style={{padding:'20px 32px',fontSize:'13px',color:'#747780'}}>{t.reference ?? '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              </>
            )}
          </div>
        )}

        {/* ATTENDANCE */}
        {tab === 'attendance' && attendance && (
          <div className="space-y-6">
            <div className="text-center" style={{...cardBase, padding:'32px'}}>
              <div style={{fontFamily:'var(--font-display)',fontSize:'48px',fontWeight:700,color:'var(--color-navy)',lineHeight:1}}>{attendance.total_present}</div>
              <div style={{fontSize:'14px',color:'#747780',marginTop:'8px'}}>Services attended (all time)</div>
            </div>
            <div style={{...cardBase, overflow:'hidden'}}>
              <div style={{padding:'16px 24px',backgroundColor:'#f8f9fc',borderBottom:'1px solid var(--color-surface-border)'}}>
                <h3 style={{fontSize:'16px',fontWeight:700,color:'var(--color-navy)'}}>Recent Attendance</h3>
              </div>
              {attendance.recent.length === 0 ? (
                <div className="text-center" style={{padding:'32px',fontSize:'14px',color:'#9ca3af'}}>No attendance recorded yet</div>
              ) : (
                <div>
                  {attendance.recent.map(r => (
                    <div key={r.id} className="flex items-center justify-between" style={{padding:'16px 24px',borderTop:'1px solid var(--color-surface-border)'}}>
                      <span style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>{r.service}</span>
                      <span style={{fontSize:'14px',color:'#747780'}}>{r.date}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}

        {/* PROFILE */}
        {tab === 'profile' && profile && (
          <div style={{...cardBase, padding:'24px'}}>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
              {[
                ['Full Name', profile.full_name],
                ['Member Number', profile.member_number],
                ['Gender', profile.gender, true],
                ['Phone', profile.phone],
                ['Email', profile.email],
                ['Occupation', profile.occupation],
                ['Marital Status', profile.marital_status, true],
                ['Date of Birth', profile.date_of_birth],
                ['Join Date', profile.join_date],
                ['Baptised', profile.is_baptised ? 'Yes' : 'No'],
                ['Address', profile.address],
              ].map(([label, value, cap]) => (
                <div key={label}>
                  <div className="uppercase tracking-wider mb-1" style={{fontSize:'12px',fontWeight:700,color:'#9ca3af'}}>{label}</div>
                  <div className={cap ? 'capitalize' : ''} style={{fontSize:'15px',fontWeight:500,color:'#191c1e'}}>{value || '—'}</div>
                </div>
              ))}
            </div>
            <p className="mt-6 pt-4" style={{fontSize:'12px',color:'#9ca3af',borderTop:'1px solid var(--color-surface-border)'}}>
              To update your details, please contact the church office.
            </p>
          </div>
        )}
      </main>
    </div>
  )
}
