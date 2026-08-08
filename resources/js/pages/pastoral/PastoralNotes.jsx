import { useState, useEffect, useRef } from 'react'
import { getPastoralNotes, createPastoralNote } from '../../api/pastoral'
import { getMembers } from '../../api/members'
import { toast } from 'sonner'

const CATEGORIES = [
  { value: 'general', label: 'General' },
  { value: 'pastoral', label: 'Pastoral' },
  { value: 'medical', label: 'Medical' },
  { value: 'welfare', label: 'Welfare' },
]

const CATEGORY_COLORS = {
  general:   { bg: '#edeef1', text: '#44474f' },
  pastoral:  { bg: '#d7e2ff', text: '#374766' },
  medical:   { bg: '#fce7f3', text: '#9d174d' },
  welfare:   { bg: '#fef3c7', text: '#92400e' },
}

export default function PastoralNotes() {
  const [notes, setNotes] = useState([])
  const [loading, setLoading] = useState(false)
  const [members, setMembers] = useState([])
  const [showForm, setShowForm] = useState(false)

  // Form state
  const [memberId, setMemberId] = useState('')
  const [category, setCategory] = useState('general')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [followUpRequired, setFollowUpRequired] = useState(false)
  const [followUpDate, setFollowUpDate] = useState('')
  const [submitting, setSubmitting] = useState(false)

  // Filter state
  const [filterCategory, setFilterCategory] = useState('')

  async function loadNotes() {
    setLoading(true)
    try {
      const params = {}
      if (filterCategory) params.category = filterCategory
      const res = await getPastoralNotes(params)
      setNotes(res.data.data)
    } catch {
      toast.error('Failed to load pastoral notes.')
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

  const loadNotesRef   = useRef(loadNotes)
  const loadMembersRef = useRef(loadMembers)
  useEffect(() => {
    loadNotesRef.current   = loadNotes
    loadMembersRef.current = loadMembers
  })
  useEffect(() => { loadNotesRef.current(); loadMembersRef.current() }, [])

  async function handleSubmit(e) {
    e.preventDefault()
    setSubmitting(true)
    try {
      await createPastoralNote({
        member_id: memberId,
        category,
        title,
        body,
        follow_up_required: followUpRequired,
        follow_up_date: followUpDate || null,
      })
      toast.success('Pastoral note created.')
      setShowForm(false)
      setMemberId('')
      setCategory('general')
      setTitle('')
      setBody('')
      setFollowUpRequired(false)
      setFollowUpDate('')
      loadNotes()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Failed to create note.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
            Pastoral Notes
          </h1>
          <p style={{color:'#44474f',marginTop:'4px'}}>
            Care notes and follow-up tracking for members
          </p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary px-4 py-2">
          {showForm ? 'Cancel' : '+ New Note'}
        </button>
      </div>

      {/* Create form */}
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
              <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Category</label>
              <select value={category} onChange={e => setCategory(e.target.value)}
                      className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
                {CATEGORIES.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
            </div>
          </div>
          <div className="flex flex-col">
            <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Title</label>
            <input type="text" value={title} onChange={e => setTitle(e.target.value)} required
                   className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
          </div>
          <div className="flex flex-col">
            <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Notes</label>
            <textarea value={body} onChange={e => setBody(e.target.value)} required rows={4}
                      className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
          </div>
          <div className="flex flex-wrap items-center gap-4">
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={followUpRequired} onChange={e => setFollowUpRequired(e.target.checked)} />
              <span style={{color:'#44474f'}}>Follow-up required</span>
            </label>
            {followUpRequired && (
              <div className="flex flex-col">
                <input type="date" value={followUpDate} onChange={e => setFollowUpDate(e.target.value)}
                       className="px-3 py-2 rounded-lg text-sm" style={{border:'1px solid var(--color-surface-border)'}} />
              </div>
            )}
          </div>
          <button type="submit" disabled={submitting} className="btn-primary px-6 py-2">
            {submitting ? 'Saving...' : 'Save Note'}
          </button>
        </form>
      )}

      {/* Filter */}
      <div className="bg-white rounded-xl p-4 flex flex-wrap items-end gap-4"
           style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Category</label>
          <select value={filterCategory} onChange={e => setFilterCategory(e.target.value)}
                  className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
            <option value="">All</option>
            {CATEGORIES.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
          </select>
        </div>
        <button onClick={loadNotes} disabled={loading} className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Filter'}
        </button>
      </div>

      {/* Notes list */}
      <div className="space-y-3">
        {notes.length === 0 && !loading && (
          <div className="bg-white rounded-xl p-6 text-center" style={{border:'1px solid var(--color-surface-border)',color:'#9ca3af'}}>
            No pastoral notes found.
          </div>
        )}
        {notes.map(note => {
          const catColor = CATEGORY_COLORS[note.category] || CATEGORY_COLORS.general
          return (
            <div key={note.id} className="bg-white rounded-xl p-5"
                 style={{border:'1px solid var(--color-surface-border)'}}>
              <div className="flex items-start justify-between">
                <div>
                  <div className="flex items-center gap-2 mb-1">
                    <span className="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"
                          style={{backgroundColor: catColor.bg, color: catColor.text}}>
                      {note.category}
                    </span>
                    {note.follow_up_required && !note.follow_up_completed && (
                      <span className="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"
                            style={{backgroundColor: '#fef3c7', color: '#92400e'}}>
                        Follow-up: {note.follow_up_date || 'Pending'}
                      </span>
                    )}
                  </div>
                  <h3 className="font-bold" style={{color:'var(--color-navy)'}}>{note.title}</h3>
                  <p className="text-sm mt-1" style={{color:'#44474f'}}>{note.body}</p>
                </div>
                <div className="text-right text-xs" style={{color:'#9ca3af'}}>
                  <div>{note.member?.name}</div>
                  <div>{note.author?.name}</div>
                  <div>{note.created_at}</div>
                </div>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
