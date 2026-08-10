import { useState, useEffect, useRef } from 'react'
import { getLifeEvents, createLifeEvent, updateLifeEvent, deleteLifeEvent } from '../../api/lifeEvents'
import { getMembers } from '../../api/members'
import { toast } from 'sonner'
import { useConfirm } from '../../hooks/useConfirm'

const CURRENT_YEAR = new Date().getFullYear()

const TYPE_META = {
  death: { label: 'Death', bg: '#fee2e2', text: '#991b1b' },
  birth: { label: 'Birth', bg: '#dcfce7', text: '#166534' },
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GH', { day: 'numeric', month: 'short', year: 'numeric' })
}

function personLabel(event) {
  return event.name ?? '—'
}

function parentsLabel(event) {
  if (event.type !== 'birth') return ''
  const father = [event.father_first_name, event.father_last_name].filter(Boolean).join(' ')
  const mother = [event.mother_first_name, event.mother_last_name].filter(Boolean).join(' ')
  const parts = []
  if (father) parts.push(`F: ${father}`)
  if (mother) parts.push(`M: ${mother}`)
  return parts.join(' · ')
}

export default function LifeEvents() {
  const { confirm, dialog } = useConfirm()
  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(false)

  // Filters
  const [filterType, setFilterType] = useState('')
  const [filterYear, setFilterYear] = useState(String(CURRENT_YEAR))

  // Modal + form state
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [type, setType] = useState('death')
  const [eventDate, setEventDate] = useState('')
  const [notes, setNotes] = useState('')

  // Name fields — the deceased person (deaths) or the baby (births)
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')

  // Death fields
  const [memberId, setMemberId] = useState('')
  const [memberQuery, setMemberQuery] = useState('')
  const [memberOptions, setMemberOptions] = useState([])
  const [burialDate, setBurialDate] = useState('')

  // Birth fields
  const [fatherFirstName, setFatherFirstName] = useState('')
  const [fatherLastName, setFatherLastName] = useState('')
  const [fatherMemberId, setFatherMemberId] = useState('')
  const [fatherQuery, setFatherQuery] = useState('')
  const [fatherOptions, setFatherOptions] = useState([])
  const [motherFirstName, setMotherFirstName] = useState('')
  const [motherLastName, setMotherLastName] = useState('')
  const [motherMemberId, setMotherMemberId] = useState('')
  const [motherQuery, setMotherQuery] = useState('')
  const [motherOptions, setMotherOptions] = useState([])

  const [submitting, setSubmitting] = useState(false)

  async function load() {
    setLoading(true)
    try {
      const params = {}
      if (filterType) params.type = filterType
      if (filterYear) params.year = filterYear
      const res = await getLifeEvents(params)
      setEvents(res.data.data)
    } catch {
      toast.error('Failed to load life events.')
    } finally {
      setLoading(false)
    }
  }

  const loadRef = useRef(load)
  useEffect(() => { loadRef.current = load })
  useEffect(() => { loadRef.current() }, [])

  async function runMemberSearch(query, setter, setOptions) {
    if (!query || query.trim().length < 2) {
      setOptions([])
      return
    }
    try {
      const res = await getMembers({ search: query.trim(), per_page: 8 })
      setOptions(res.data.data)
    } catch { /* silent */ }
  }

  const deathSearchTimer = useRef(null)
  const onDeathSearch = (value) => {
    setMemberQuery(value)
    clearTimeout(deathSearchTimer.current)
    deathSearchTimer.current = setTimeout(() => runMemberSearch(value, setMemberQuery, setMemberOptions), 250)
  }

  const fatherSearchTimer = useRef(null)
  const onFatherSearch = (value) => {
    setFatherQuery(value)
    clearTimeout(fatherSearchTimer.current)
    fatherSearchTimer.current = setTimeout(() => runMemberSearch(value, setFatherQuery, setFatherOptions), 250)
  }

  const motherSearchTimer = useRef(null)
  const onMotherSearch = (value) => {
    setMotherQuery(value)
    clearTimeout(motherSearchTimer.current)
    motherSearchTimer.current = setTimeout(() => runMemberSearch(value, setMotherQuery, setMotherOptions), 250)
  }

  function selectMember(m, kind) {
    if (kind === 'death') {
      setMemberId(m.id)
      setMemberQuery(m.full_name)
      setMemberOptions([])
      setFirstName(m.first_name)
      setLastName(m.last_name ?? '')
    } else if (kind === 'father') {
      setFatherMemberId(m.id)
      setFatherQuery(m.full_name)
      setFatherOptions([])
      setFatherFirstName(m.first_name)
      setFatherLastName(m.last_name ?? '')
    } else {
      setMotherMemberId(m.id)
      setMotherQuery(m.full_name)
      setMotherOptions([])
      setMotherFirstName(m.first_name)
      setMotherLastName(m.last_name ?? '')
    }
  }

  function resetForm() {
    setType('death')
    setEventDate('')
    setBurialDate('')
    setNotes('')
    setFirstName('')
    setLastName('')
    setMemberId('')
    setMemberQuery('')
    setMemberOptions([])
    setFatherFirstName('')
    setFatherLastName('')
    setFatherMemberId('')
    setFatherQuery('')
    setFatherOptions([])
    setMotherFirstName('')
    setMotherLastName('')
    setMotherMemberId('')
    setMotherQuery('')
    setMotherOptions([])
  }

  function openNew(kind) {
    setEditing(null)
    resetForm()
    setType(kind)
    setOpen(true)
  }

  function openEdit(event) {
    setEditing(event)
    setType(event.type)
    setEventDate(event.event_date)
    setBurialDate(event.burial_date ?? '')
    setNotes(event.notes ?? '')
    setFirstName(event.first_name ?? '')
    setLastName(event.last_name ?? '')
    if (event.type === 'death') {
      setMemberId(event.member?.id ?? '')
      setMemberQuery(event.member?.name ?? '')
      setFatherFirstName('')
      setFatherLastName('')
      setFatherMemberId('')
      setFatherQuery('')
      setMotherFirstName('')
      setMotherLastName('')
      setMotherMemberId('')
      setMotherQuery('')
    } else {
      setFatherFirstName(event.father_first_name ?? '')
      setFatherLastName(event.father_last_name ?? '')
      setFatherMemberId(event.father_member?.id ?? '')
      setFatherQuery(event.father_member?.name ?? '')
      setMotherFirstName(event.mother_first_name ?? '')
      setMotherLastName(event.mother_last_name ?? '')
      setMotherMemberId(event.member?.id ?? '')
      setMotherQuery(event.member?.name ?? '')
      setMemberId('')
      setMemberQuery('')
    }
    setOpen(true)
  }

  function closeModal() {
    setOpen(false)
    setEditing(null)
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setSubmitting(true)
    try {
      const payload = {
        type,
        event_date: eventDate,
        burial_date: type === 'death' ? (burialDate || null) : null,
        notes: notes || null,
        member_id: type === 'death' ? (memberId || null) : (motherMemberId || null),
        father_member_id: type === 'birth' ? (fatherMemberId || null) : null,
        first_name: firstName,
        last_name: lastName || null,
        father_first_name: type === 'birth' ? (fatherFirstName || null) : null,
        father_last_name: type === 'birth' ? (fatherLastName || null) : null,
        mother_first_name: type === 'birth' ? motherFirstName : null,
        mother_last_name: type === 'birth' ? (motherLastName || null) : null,
      }
      if (editing) {
        await updateLifeEvent(editing.id, payload)
        toast.success('Life event updated.')
      } else {
        await createLifeEvent(payload)
        toast.success(type === 'death' ? 'Death recorded successfully.' : 'Birth recorded successfully.')
      }
      closeModal()
      load()
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Failed to save life event.')
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(event) {
    if (!(await confirm(`Delete the ${event.type} record for ${personLabel(event)}?`))) return
    try {
      await deleteLifeEvent(event.id)
      toast.success('Life event removed.')
      load()
    } catch {
      toast.error('Failed to remove life event.')
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
            Life Events
          </h1>
          <p style={{color:'#44474f',marginTop:'4px'}}>
            Record deaths and births for the year-end church announcement
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={() => openNew('birth')} className="btn-primary px-4 py-2">
            + Record Birth
          </button>
          <button onClick={() => openNew('death')} className="px-4 py-2 rounded-lg font-medium"
                  style={{border:'1px solid #fecaca',backgroundColor:'#fef2f2',color:'#991b1b'}}>
            + Record Death
          </button>
        </div>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl p-4 flex flex-wrap items-end gap-4"
           style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Type</label>
          <select value={filterType} onChange={e => setFilterType(e.target.value)}
                  className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
            <option value="">All</option>
            <option value="death">Death</option>
            <option value="birth">Birth</option>
          </select>
        </div>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Year</label>
          <input type="number" value={filterYear} onChange={e => setFilterYear(e.target.value)}
                 className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)',width:'110px'}} />
        </div>
        <button onClick={load} disabled={loading} className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Filter'}
        </button>
      </div>

      {/* List */}
      <div className="bg-white rounded-xl overflow-hidden" style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead style={{backgroundColor:'#edeef1'}}>
              <tr>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Type</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Person</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Date</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Burial</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Parents</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Recorded By</th>
                <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {events.length === 0 && !loading && (
                <tr>
                  <td colSpan={7} className="px-6 py-8 text-center" style={{color:'#9ca3af'}}>
                    No life events found.
                  </td>
                </tr>
              )}
              {events.map(event => {
                const meta = TYPE_META[event.type] || TYPE_META.death
                return (
                  <tr key={event.id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                    <td className="px-6 py-3">
                      <span className="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"
                            style={{backgroundColor: meta.bg, color: meta.text}}>
                        {meta.label}
                      </span>
                    </td>
                    <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{personLabel(event)}</td>
                    <td className="px-6 py-3" style={{color:'#44474f'}}>{formatDate(event.event_date)}</td>
                    <td className="px-6 py-3" style={{color:'#44474f'}}>
                      {event.type === 'death' ? (event.burial_date ? formatDate(event.burial_date) : '—') : '—'}
                    </td>
                    <td className="px-6 py-3" style={{color:'#44474f'}}>{parentsLabel(event) || '—'}</td>
                    <td className="px-6 py-3" style={{color:'#6b7280'}}>{event.recorder?.name ?? '—'}</td>
                    <td className="px-6 py-3">
                      <div className="flex items-center gap-2">
                        <button onClick={() => openEdit(event)} className="px-2 py-1 rounded text-xs font-semibold"
                                style={{border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                          Edit
                        </button>
                        <button onClick={() => handleDelete(event)} className="px-2 py-1 rounded text-xs font-semibold"
                                style={{border:'1px solid #fecaca',color:'#991b1b'}}>
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      {dialog}

      {open && (
        <EventModal
          editing={editing}
          type={type}
          setType={setType}
          eventDate={eventDate}
          setEventDate={setEventDate}
          burialDate={burialDate}
          setBurialDate={setBurialDate}
          notes={notes}
          setNotes={setNotes}
          firstName={firstName}
          setFirstName={setFirstName}
          lastName={lastName}
          setLastName={setLastName}
          memberId={memberId}
          memberQuery={memberQuery}
          memberOptions={memberOptions}
          onDeathSearch={onDeathSearch}
          onSelectMember={(m) => selectMember(m, 'death')}
          fatherFirstName={fatherFirstName}
          setFatherFirstName={setFatherFirstName}
          fatherLastName={fatherLastName}
          setFatherLastName={setFatherLastName}
          fatherMemberId={fatherMemberId}
          fatherQuery={fatherQuery}
          fatherOptions={fatherOptions}
          onFatherSearch={onFatherSearch}
          onSelectFather={(m) => selectMember(m, 'father')}
          motherFirstName={motherFirstName}
          setMotherFirstName={setMotherFirstName}
          motherLastName={motherLastName}
          setMotherLastName={setMotherLastName}
          motherMemberId={motherMemberId}
          motherQuery={motherQuery}
          motherOptions={motherOptions}
          onMotherSearch={onMotherSearch}
          onSelectMother={(m) => selectMember(m, 'mother')}
          submitting={submitting}
          onSubmit={handleSubmit}
          onClose={closeModal}
        />
      )}
    </div>
  )
}

function MemberCombo({ query, onSearch, options, onSelect, selectedId, placeholder }) {
  return (
    <div>
      <input
        type="text"
        value={query}
        onChange={e => onSearch(e.target.value)}
        placeholder={placeholder}
        className="input-field"
      />
      {options.length > 0 && (
        <div className="mt-1 rounded-lg overflow-hidden"
             style={{border:'1px solid var(--color-surface-border)',backgroundColor:'white',boxShadow:'0 4px 12px rgba(0,0,0,0.08)'}}>
          {options.map(m => (
            <button
              key={m.id}
              type="button"
              onClick={() => onSelect(m)}
              className="block w-full text-left px-3 py-2 text-sm"
              style={{color:'var(--color-navy)',borderBottom:'1px solid var(--color-surface-border)'}}
              onMouseEnter={e => e.currentTarget.style.backgroundColor = '#f8f9fa'}
              onMouseLeave={e => e.currentTarget.style.backgroundColor = 'white'}
            >
              {m.full_name} <span style={{color:'#9ca3af',fontSize:'11px'}}>({m.member_number})</span>
            </button>
          ))}
        </div>
      )}
      {selectedId && (
        <p className="text-xs mt-1" style={{color:'#15803d'}}>Selected: {query}</p>
      )}
    </div>
  )
}

function EventModal(props) {
  const {
    editing, type, setType, eventDate, setEventDate, burialDate, setBurialDate,
    notes, setNotes,
    firstName, setFirstName, lastName, setLastName,
    memberId, memberQuery, memberOptions, onDeathSearch, onSelectMember,
    fatherFirstName, setFatherFirstName, fatherLastName, setFatherLastName,
    fatherMemberId, fatherQuery, fatherOptions, onFatherSearch, onSelectFather,
    motherFirstName, setMotherFirstName, motherLastName, setMotherLastName,
    motherMemberId, motherQuery, motherOptions, onMotherSearch, onSelectMother,
    submitting, onSubmit, onClose,
  } = props

  return (
    <div className="fixed inset-0 flex items-center justify-center z-50 p-4" style={{backgroundColor:'rgba(0,0,0,0.5)'}}>
      <div className="bg-white rounded-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto shadow-2xl">
        <div className="px-6 py-4 flex items-center justify-between" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <div>
            <h3 className="text-lg font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              {editing ? 'Edit' : 'Record'} Life Event
            </h3>
            <p className="text-xs mt-0.5" style={{color:'#6b7280'}}>
              {editing ? 'Update the details below' : 'For the year-end church announcement'}
            </p>
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100">
            <svg className="w-5 h-5" style={{color:'#6b7280'}} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <form onSubmit={onSubmit} className="p-6 space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <button type="button" onClick={() => setType('death')} className="px-4 py-2 rounded-lg font-semibold text-sm"
                    style={type === 'death'
                      ? {backgroundColor:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'}
                      : {backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#44474f'}}>
              Death
            </button>
            <button type="button" onClick={() => setType('birth')} className="px-4 py-2 rounded-lg font-semibold text-sm"
                    style={type === 'birth'
                      ? {backgroundColor:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'}
                      : {backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#44474f'}}>
              Birth
            </button>
          </div>

          {type === 'death' ? (
            <>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Deceased First Name *</label>
                  <input type="text" className="input-field" value={firstName} onChange={e => setFirstName(e.target.value)} required />
                </div>
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Deceased Last Name</label>
                  <input type="text" className="input-field" value={lastName} onChange={e => setLastName(e.target.value)} />
                </div>
              </div>
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Link to Member (optional)</label>
                <MemberCombo
                  query={memberQuery}
                  onSearch={onDeathSearch}
                  options={memberOptions}
                  onSelect={onSelectMember}
                  selectedId={memberId}
                  placeholder="Optional: search register to mark them deceased..."
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Date of Death *</label>
                  <input type="date" className="input-field" value={eventDate} onChange={e => setEventDate(e.target.value)} required />
                </div>
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Date of Burial</label>
                  <input type="date" className="input-field" value={burialDate} onChange={e => setBurialDate(e.target.value)} />
                </div>
              </div>
            </>
          ) : (
            <>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Baby First Name *</label>
                  <input type="text" className="input-field" value={firstName} onChange={e => setFirstName(e.target.value)} required />
                </div>
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Baby Last Name</label>
                  <input type="text" className="input-field" value={lastName} onChange={e => setLastName(e.target.value)} />
                </div>
              </div>
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Date of Birth *</label>
                <input type="date" className="input-field" value={eventDate} onChange={e => setEventDate(e.target.value)} required />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Father First Name</label>
                  <input type="text" className="input-field" value={fatherFirstName} onChange={e => setFatherFirstName(e.target.value)} />
                </div>
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Father Last Name</label>
                  <input type="text" className="input-field" value={fatherLastName} onChange={e => setFatherLastName(e.target.value)} />
                </div>
              </div>
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Father (if a member)</label>
                <MemberCombo
                  query={fatherQuery}
                  onSearch={onFatherSearch}
                  options={fatherOptions}
                  onSelect={onSelectFather}
                  selectedId={fatherMemberId}
                  placeholder="Optional: search member register..."
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Mother First Name *</label>
                  <input type="text" className="input-field" value={motherFirstName} onChange={e => setMotherFirstName(e.target.value)} required />
                </div>
                <div>
                  <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Mother Last Name</label>
                  <input type="text" className="input-field" value={motherLastName} onChange={e => setMotherLastName(e.target.value)} />
                </div>
              </div>
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Mother (if a member)</label>
                <MemberCombo
                  query={motherQuery}
                  onSearch={onMotherSearch}
                  options={motherOptions}
                  onSelect={onSelectMother}
                  selectedId={motherMemberId}
                  placeholder="Optional: search member register..."
                />
              </div>
            </>
          )}

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Notes</label>
            <textarea className="input-field" rows={3} value={notes} onChange={e => setNotes(e.target.value)}
                      placeholder="Optional notes (e.g. funeral details, family)" />
          </div>

          <div className="flex items-center justify-end gap-3 pt-4" style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <button type="button" onClick={onClose} className="px-5 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
              Cancel
            </button>
            <button type="submit" disabled={submitting} className="btn-primary px-6 py-2">
              {submitting ? 'Saving...' : (editing ? 'Save Changes' : 'Save Record')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
