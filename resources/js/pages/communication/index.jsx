import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getMessages, getMessageStats } from '../../api/messages'
import { usePermission } from '../../hooks/usePermission'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
const cardBase = {
  backgroundColor: '#fff',
  border: BORDER,
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const Icon = ({ d, size = 22 }) => (
  <svg width={size} height={size} fill="none" stroke="currentColor" strokeWidth={1.8}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
)
const ICONS = {
  outbox:  <><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></>,
  calendar:<><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></>,
  groups:  <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/></>,
  mail:    <><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 5L2 7"/></>,
  sms:     <><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></>,
}

const CHANNELS = {
  sms:   { icons: ['sms'],          label: 'SMS' },
  email: { icons: ['mail'],         label: 'Email' },
  both:  { icons: ['mail', 'sms'],  label: 'Email + SMS' },
}

export default function CommunicationPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const [messages, setMessages] = useState([])
  const [stats,    setStats]    = useState(null)
  const [loading,  setLoading]  = useState(true)
  const [page,     setPage]     = useState(1)
  const [meta,     setMeta]     = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [mRes, sRes] = await Promise.all([
        getMessages({ page, per_page: 15 }),
        getMessageStats(),
      ])
      setMessages(mRes.data.data)
      setMeta(mRes.data.meta)
      setStats(sRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [page])

  useEffect(() => { fetchData() }, [fetchData])

  const statCards = [
    { label:'Total Messages Sent', value: stats?.total_sent       ?? '—', icon: ICONS.outbox,   sub:'All-time record' },
    { label:'Sent This Month',     value: stats?.this_month       ?? '—', icon: ICONS.calendar, sub:'Current period' },
    { label:'Recipients Reached',  value: stats?.total_recipients ?? '—', icon: ICONS.groups,   sub:'Across all channels' },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Header */}
      <div className="flex justify-between items-end gap-4 flex-wrap">
        <div>
          <h2 className="font-bold" style={{fontFamily:FONT_DISPLAY,fontSize:'32px',lineHeight:'40px',color:NAVY}}>
            Communications
          </h2>
          <p style={{color:'#44474f'}}>Review and manage sent messages to your congregation.</p>
        </div>
        {can('send messages') && (
          <button onClick={() => navigate('/communication/compose')} className="btn-primary gap-2" style={{padding:'12px 24px'}}>
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Compose Message
          </button>
        )}
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {statCards.map(s => (
          <div key={s.label} style={{...cardBase, padding:'24px'}}>
            <div className="flex items-center gap-2 mb-3" style={{color:NAVY}}>
              <Icon d={s.icon} size={20} />
              <span className="uppercase tracking-wider" style={{fontSize:'11px',fontWeight:700}}>{s.label}</span>
            </div>
            <div style={{fontFamily:FONT_DISPLAY,fontSize:'40px',fontWeight:700,color:NAVY,lineHeight:1}}>{s.value}</div>
            <div style={{fontSize:'12px',color:MUTED,marginTop:'4px'}}>{s.sub}</div>
          </div>
        ))}
      </div>

      {/* History table */}
      <div style={{...cardBase, overflow:'hidden'}}>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{backgroundColor:'#f2f3f6'}}>
                {[['Channel'],['Subject'],['Preview'],['Sender'],['Recipients','center'],['Delivery'],['','right']].map(([h, align], idx) => (
                  <th key={idx} className="uppercase tracking-wider" style={{padding:'12px 24px',fontSize:'11px',fontWeight:700,color:MUTED,textAlign:align||'left'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="text-center" style={{padding:'48px',color:PLACEHOLDER}}>Loading...</td></tr>
              ) : messages.length === 0 ? (
                <tr><td colSpan={7} className="text-center" style={{padding:'48px'}}>
                  <div className="text-4xl mb-3">💌</div>
                  <div className="font-semibold" style={{color:NAVY}}>No messages sent yet</div>
                  <div className="text-sm mt-1" style={{color:PLACEHOLDER}}>Compose your first message to broadcast to members</div>
                </td></tr>
              ) : messages.map(msg => {
                const ch = CHANNELS[msg.channel] ?? CHANNELS.email
                const total = msg.total_recipients || 0
                const delivered = msg.delivered_count || 0
                const failed = msg.failed_count || 0
                const pct = total > 0 ? Math.round((delivered / total) * 100) : 0
                return (
                  <tr key={msg.id} className="transition-colors cursor-pointer" style={{borderTop:BORDER}}
                      onClick={() => navigate(`/communication/${msg.id}`)}
                      onMouseEnter={e => e.currentTarget.style.backgroundColor='#f8f9fc'}
                      onMouseLeave={e => e.currentTarget.style.backgroundColor='transparent'}>
                    <td style={{padding:'16px 24px'}}>
                      <div className="flex gap-1">
                        {ch.icons.map(ic => (
                          <div key={ic} className="rounded flex items-center justify-center" style={{padding:'6px',backgroundColor:'rgba(199,215,253,0.4)',color:NAVY}} title={ic}>
                            <Icon d={ICONS[ic]} size={16} />
                          </div>
                        ))}
                      </div>
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <p style={{fontSize:'14px',fontWeight:600,color:NAVY}}>{msg.subject ?? <em style={{color:PLACEHOLDER}}>No subject</em>}</p>
                      <p style={{fontSize:'11px',color:MUTED}}>{msg.sent_at ?? msg.created_at}</p>
                    </td>
                    <td style={{padding:'16px 24px',maxWidth:'240px'}}>
                      <p className="truncate" style={{fontSize:'14px',color:'#44474f'}}>{msg.body_preview}</p>
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <div className="flex items-center gap-1.5">
                        <div className="w-6 h-6 rounded-full flex items-center justify-center" style={{fontSize:'10px',fontWeight:700,backgroundColor:'var(--color-primary-fixed,#d7e2ff)',color:NAVY}}>
                          {(msg.sender ?? '?').charAt(0)}
                        </div>
                        <span style={{fontSize:'14px',color:'#191c1e'}}>{msg.sender ?? '—'}</span>
                      </div>
                    </td>
                    <td style={{padding:'16px 24px',textAlign:'center',fontSize:'14px',fontWeight:600,color:'#191c1e'}}>{total}</td>
                    <td style={{padding:'16px 24px',minWidth:'140px'}}>
                      <div className="flex flex-col gap-1">
                        <div className="rounded-full overflow-hidden" style={{height:'4px',backgroundColor:'#e1e2e5'}}>
                          <div style={{width:`${pct}%`,height:'100%',backgroundColor:'#2e7d32'}}/>
                        </div>
                        <div className="flex justify-between" style={{fontSize:'10px'}}>
                          <span style={{color:'#2e7d32',fontWeight:700}}>{delivered} delivered</span>
                          {failed > 0
                            ? <span style={{color:'#ba1a1a',fontWeight:700}}>{failed} failed</span>
                            : <span style={{color:MUTED}}>0 failed</span>}
                        </div>
                      </div>
                    </td>
                    <td style={{padding:'16px 24px',textAlign:'right'}}>
                      <span className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:NAVY}}>View Details</span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between" style={{padding:'16px 24px',borderTop:BORDER}}>
            <span style={{fontSize:'14px',color:MUTED}}>Page {meta.current_page} of {meta.last_page} · {meta.total} messages</span>
            <div className="flex gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="px-4 py-2 rounded-lg disabled:opacity-50" style={{border:BORDER,fontSize:'14px',color:NAVY}}>Previous</button>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="px-4 py-2 rounded-lg text-white" style={{backgroundColor:NAVY,fontSize:'14px'}}>Next</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
