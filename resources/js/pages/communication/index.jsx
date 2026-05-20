import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getMessages, getMessageStats } from '../../api/messages'
import { usePermission } from '../../hooks/usePermission'

const CHANNEL_BADGES = {
  sms:   { bg: '#dbeafe', text: '#1d4ed8', icon: '📱', label: 'SMS' },
  email: { bg: '#dcfce7', text: '#15803d', icon: '📧', label: 'Email' },
  both:  { bg: '#fef3c7', text: '#92400e', icon: '📨', label: 'SMS + Email' },
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

  return (
    <div className="space-y-6">

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {[
          { label: 'Total Sent',         value: stats?.total_sent       ?? '—' },
          { label: 'This Month',         value: stats?.this_month       ?? '—' },
          { label: 'Total Recipients',   value: stats?.total_recipients ?? '—' },
        ].map(s => (
          <div key={s.label} className="card py-4">
            <div className="text-2xl font-bold"
                 style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              {s.value}
            </div>
            <div className="text-xs mt-1" style={{color:'#6b7280'}}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Communication
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            Send announcements and reach your members
          </p>
        </div>
        {can('send messages') && (
          <button onClick={() => navigate('/communication/compose')} className="btn-primary gap-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
            </svg>
            Compose Message
          </button>
        )}
      </div>

      <div className="card p-0 overflow-hidden">
        {loading ? (
          <div className="text-center py-12" style={{color:'#9ca3af'}}>Loading...</div>
        ) : messages.length === 0 ? (
          <div className="text-center py-12">
            <div className="text-4xl mb-3">💌</div>
            <div className="font-semibold" style={{color:'var(--color-navy)'}}>No messages sent yet</div>
            <div className="text-sm mt-1" style={{color:'#9ca3af'}}>
              Compose your first message to broadcast to members
            </div>
          </div>
        ) : (
          <div className="divide-y" style={{borderColor:'var(--color-surface-border)'}}>
            {messages.map(msg => {
              const badge = CHANNEL_BADGES[msg.channel]
              return (
                <div key={msg.id}
                     onClick={() => navigate(`/communication/${msg.id}`)}
                     className="px-5 py-4 cursor-pointer hover:bg-gray-50 transition-colors">
                  <div className="flex items-start gap-4">
                    <div className="w-10 h-10 rounded-xl flex items-center justify-center
                                    flex-shrink-0 text-xl"
                         style={{backgroundColor: badge.bg}}>
                      {badge.icon}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-3 mb-1">
                        <h3 className="text-sm font-bold truncate" style={{color:'#111827'}}>
                          {msg.subject ?? <em style={{color:'#9ca3af'}}>No subject</em>}
                        </h3>
                        <span className="text-xs flex-shrink-0" style={{color:'#9ca3af'}}>
                          {msg.sent_at}
                        </span>
                      </div>
                      <p className="text-xs mb-2 line-clamp-1" style={{color:'#6b7280'}}>
                        {msg.body_preview}
                      </p>
                      <div className="flex items-center gap-2 flex-wrap text-xs">
                        <span className="px-2 py-0.5 rounded-full font-semibold"
                              style={{backgroundColor: badge.bg, color: badge.text}}>
                          {badge.label}
                        </span>
                        <span style={{color:'#9ca3af'}}>
                          {msg.sender} · sent to {msg.total_recipients}
                        </span>
                        {msg.delivered_count > 0 && (
                          <span style={{color:'#15803d'}}>· {msg.delivered_count} delivered</span>
                        )}
                        {msg.failed_count > 0 && (
                          <span style={{color:'#dc2626'}}>· {msg.failed_count} failed</span>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              )
            })}
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="px-4 py-3 flex items-center justify-between"
               style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <span className="text-sm" style={{color:'#6b7280'}}>
              Page {meta.current_page} of {meta.last_page}
            </span>
            <div className="flex gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="px-3 py-1 text-sm rounded border disabled:opacity-50"
                      style={{borderColor:'var(--color-surface-border)'}}>
                Previous
              </button>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="px-3 py-1 text-sm rounded border disabled:opacity-50"
                      style={{borderColor:'var(--color-surface-border)'}}>
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
