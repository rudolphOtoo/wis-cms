import { useState, useEffect } from 'react'
import { getConfirmations, createConfirmation, deleteConfirmation } from '../../api/confirmations'
import { getMembers } from '../../api/members'
import { toast } from 'sonner'

/**
 * Confirmations ledger — reference module page. Mounted by AppRouter only
 * when the active profile enables capabilities.modules.confirmations.
 */
export default function Confirmations() {
  const [records, setRecords] = useState([])
  const [loading, setLoading] = useState(false)
  const [members, setMembers] = useState([])
  const [showForm, setShowForm] = useState(false)

  const [memberId, setMemberId] = useState('')
  const [confirmedAt, setConfirmedAt] = useState('')
  const [clergy, setClergy] = useState('')
  const [location, setLocation] = useState('')
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function loadRecords() {
    setLoading(true)
    try {
      const res = await getConfirmations({ per_page: 100 })
      setRecords(res.data.data)
    } catch {
      toast.error('Failed to load confirmations.')
    } finally {
      setLoading(false)
    }
  }

  async function loadMembers() {
    try {
      const res = await getMembers({ per_page: 1000 })
      setMembers(res.data.data)
    } catch { /* silent */ }
  }

  useEffect(() => { loadRecords(); loadMembers() }, [])

  async function handleSubmit(e) {
    e.preventDefault()
    setSubmitting(true)
    try {
      await createConfirmation({
        member_id: memberId,
        confirmed_at: confirmedAt,
        officiating_clergy: clergy || null,
        location: location || null,
        notes: notes || null,
      })
      toast.success('Confirmation recorded.')
      setShowForm(false)
      setMemberId('')
      setConfirmedAt('')
      setClergy('')
      setLocation('')
      setNotes('')
      loadRecords()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Failed to record confirmation.')
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(id) {
    if (!window.confirm('Delete this confirmation record?')) return
    try {
      await deleteConfirmation(id)
      toast.success('Confirmation deleted.')
      loadRecords()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Failed to delete confirmation.')
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
            Confirmations
          </h1>
          <p style={{color:'#44474f',marginTop:'4px'}}>
            Ledger of confirmed members
          </p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary px-4 py-2">
          {showForm ? 'Cancel' : '+ Record Confirmation'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-white rounded-xl p-6 space-y-4"
              style={{border:'1px solid var(--color-surface-border)'}}>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="flex flex-col">
              <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Member</label>
              <select value={memberId} onChange={e => setMemberId(e.target.value)} required
                      className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
                <option value="">Select member...</option>
                {members.map(m => (
                  <option key={m.id} value={m.id}>{m.first_name} {m.last_name} ({m.member_number})</option>
                ))}
              </select>
            </div>
            <div className="flex flex-col">
              <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Confirmation Date</label>
              <input type="date" value={confirmedAt} onChange={e => setConfirmedAt(e.target.value)} required
                     className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
            </div>
            <div className="flex flex-col">
              <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Officiating Clergy</label>
              <input type="text" value={clergy} onChange={e => setClergy(e.target.value)}
                     className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
            </div>
            <div className="flex flex-col">
              <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Location</label>
              <input type="text" value={location} onChange={e => setLocation(e.target.value)}
                     className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
            </div>
          </div>
          <div className="flex flex-col">
            <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Notes</label>
            <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={3}
                      className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
          </div>
          <button type="submit" disabled={submitting} className="btn-primary px-6 py-2">
            {submitting ? 'Saving...' : 'Save Confirmation'}
          </button>
        </form>
      )}

      <div className="bg-white rounded-xl overflow-hidden" style={{border:'1px solid var(--color-surface-border)'}}>
        {records.length === 0 && !loading && (
          <div className="p-6 text-center" style={{color:'#9ca3af'}}>
            No confirmation records yet.
          </div>
        )}
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wider" style={{color:'#9ca3af',borderBottom:'1px solid var(--color-surface-border)'}}>
              <th className="px-4 py-3 font-semibold">Member</th>
              <th className="px-4 py-3 font-semibold">Member No.</th>
              <th className="px-4 py-3 font-semibold">Confirmed On</th>
              <th className="px-4 py-3 font-semibold">Clergy</th>
              <th className="px-4 py-3 font-semibold">Location</th>
              <th className="px-4 py-3 font-semibold"></th>
            </tr>
          </thead>
          <tbody>
            {records.map(r => (
              <tr key={r.id} style={{borderBottom:'1px solid var(--color-surface-border)'}}>
                <td className="px-4 py-3" style={{color:'var(--color-navy)'}}>{r.member?.name}</td>
                <td className="px-4 py-3" style={{color:'#44474f'}}>{r.member?.member_number}</td>
                <td className="px-4 py-3" style={{color:'#44474f'}}>{r.confirmed_at}</td>
                <td className="px-4 py-3" style={{color:'#44474f'}}>{r.officiating_clergy || '—'}</td>
                <td className="px-4 py-3" style={{color:'#44474f'}}>{r.location || '—'}</td>
                <td className="px-4 py-3 text-right">
                  <button
                    onClick={() => handleDelete(r.id)}
                    className="text-xs font-semibold rounded-lg px-2 py-1"
                    style={{color:'#b91c1c'}}
                  >
                    Delete
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
