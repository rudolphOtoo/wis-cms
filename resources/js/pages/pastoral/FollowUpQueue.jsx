import { useState, useEffect } from 'react'
import { getPastoralFollowUps } from '../../api/pastoral'
import { toast } from 'sonner'

const CATEGORY_COLORS = {
  general:   { bg: '#edeef1', text: '#44474f' },
  pastoral:  { bg: '#d7e2ff', text: '#374766' },
  medical:   { bg: '#fce7f3', text: '#9d174d' },
  welfare:   { bg: '#fef3c7', text: '#92400e' },
}

export default function FollowUpQueue() {
  const [followUps, setFollowUps] = useState([])
  const [loading, setLoading] = useState(false)

  async function load() {
    setLoading(true)
    try {
      const res = await getPastoralFollowUps()
      setFollowUps(res.data.data)
    } catch (_e) {
      toast.error('Failed to load follow-ups.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Follow-up Queue
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          Pending pastoral care follow-ups across all cells
        </p>
      </div>

      <div className="bg-white rounded-xl overflow-hidden"
           style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <h2 className="font-bold"
              style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
            Pending Follow-ups ({followUps.length})
          </h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead style={{backgroundColor:'#edeef1'}}>
              <tr>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Member</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Category</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Note</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Follow-up Date</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Author</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Created</th>
              </tr>
            </thead>
            <tbody>
              {followUps.length === 0 && !loading && (
                <tr>
                  <td colSpan={6} className="px-6 py-8 text-center" style={{color:'#9ca3af'}}>
                    No pending follow-ups. All caught up!
                  </td>
                </tr>
              )}
              {followUps.map(note => {
                const catColor = CATEGORY_COLORS[note.category] || CATEGORY_COLORS.general
                const isOverdue = note.follow_up_date && new Date(note.follow_up_date) < new Date()
                return (
                  <tr key={note.id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                    <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>
                      {note.member?.name ?? '—'}
                    </td>
                    <td className="px-6 py-3">
                      <span className="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"
                            style={{backgroundColor: catColor.bg, color: catColor.text}}>
                        {note.category}
                      </span>
                    </td>
                    <td className="px-6 py-3" style={{color:'#44474f'}}>
                      <div className="font-medium">{note.title}</div>
                      <div className="text-xs mt-0.5" style={{color:'#6b7280'}}>{note.body?.slice(0, 80)}...</div>
                    </td>
                    <td className="px-6 py-3" style={{color: isOverdue ? '#ba1a1a' : '#44474f', fontWeight: isOverdue ? 'bold' : 'normal'}}>
                      {note.follow_up_date ?? '—'}
                      {isOverdue && <span className="text-xs ml-1">OVERDUE</span>}
                    </td>
                    <td className="px-6 py-3" style={{color:'#6b7280'}}>{note.author?.name ?? '—'}</td>
                    <td className="px-6 py-3" style={{color:'#6b7280'}}>{note.created_at}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
