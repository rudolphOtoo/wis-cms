import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { getMessage } from '../../api/messages'

const STATUS_COLORS = {
  delivered: { bg: '#dcfce7', text: '#15803d', label: 'Delivered' },
  pending:   { bg: '#fef9c3', text: '#854d0e', label: 'Pending' },
  failed:    { bg: '#fee2e2', text: '#dc2626', label: 'Failed' },
}

export default function MessageDetail() {
  const { id }    = useParams()
  const navigate  = useNavigate()
  const [msg,     setMsg]     = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getMessage(id)
      .then(res => setMsg(res.data.data))
      .catch(() => navigate('/communication'))
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}}
           fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  if (!msg) return null

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/communication')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Message Details
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            Sent by {msg.sender} · {msg.sent_at}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-3 gap-3">
        <div className="card py-3 text-center">
          <div className="text-2xl font-bold" style={{color:'var(--color-navy)'}}>{msg.total_recipients}</div>
          <div className="text-xs" style={{color:'#6b7280'}}>Recipients</div>
        </div>
        <div className="card py-3 text-center">
          <div className="text-2xl font-bold" style={{color:'#15803d'}}>{msg.delivered_count}</div>
          <div className="text-xs" style={{color:'#6b7280'}}>Delivered</div>
        </div>
        <div className="card py-3 text-center">
          <div className="text-2xl font-bold" style={{color:'#dc2626'}}>{msg.failed_count}</div>
          <div className="text-xs" style={{color:'#6b7280'}}>Failed</div>
        </div>
      </div>

      <div className="card">
        {msg.subject && (
          <div className="pb-3 mb-3" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
            <div className="text-xs font-semibold uppercase tracking-wider mb-1" style={{color:'#6b7280'}}>Subject</div>
            <div className="text-lg font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              {msg.subject}
            </div>
          </div>
        )}
        <div className="text-xs font-semibold uppercase tracking-wider mb-1" style={{color:'#6b7280'}}>Message</div>
        <div className="text-sm whitespace-pre-wrap" style={{color:'#374151'}}>{msg.body}</div>
      </div>

      <div className="card p-0 overflow-hidden">
        <div className="px-5 py-3"
             style={{backgroundColor:'#f9fafb',borderBottom:'1px solid var(--color-surface-border)'}}>
          <h3 className="font-bold text-sm" style={{color:'var(--color-navy)'}}>
            Delivery Status — {msg.recipients.length} recipient{msg.recipients.length === 1 ? '' : 's'}
          </h3>
        </div>
        <div className="divide-y" style={{borderColor:'var(--color-surface-border)'}}>
          {msg.recipients.map(r => {
            const s = STATUS_COLORS[r.delivery_status] ?? STATUS_COLORS.pending
            return (
              <div key={r.id} className="px-5 py-3 flex items-center gap-4">
                <div className="w-9 h-9 rounded-full flex items-center justify-center
                                flex-shrink-0 text-sm font-bold text-white"
                     style={{backgroundColor:'var(--color-navy)'}}>
                  {r.name.charAt(0)}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-semibold" style={{color:'#111827'}}>{r.name}</div>
                  <div className="text-xs" style={{color:'#9ca3af'}}>
                    {r.email && <>📧 {r.email}</>}
                    {r.email && r.phone && ' · '}
                    {r.phone && <>📱 {r.phone}</>}
                  </div>
                  {r.failure_reason && (
                    <div className="text-xs mt-1" style={{color:'#dc2626'}}>
                      ⚠️ {r.failure_reason}
                    </div>
                  )}
                </div>
                <span className="px-2 py-0.5 rounded-full text-xs font-semibold flex-shrink-0"
                      style={{backgroundColor: s.bg, color: s.text}}>
                  {s.label}
                </span>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
