import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { getCell, assignToCell, unassignFromCell } from '../../api/cells'
import { getMembers } from '../../api/members'
import MemberSearchPicker from '../../components/MemberSearchPicker'

export default function CellDetail() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const [cell,       setCell]       = useState(null)
  const [members,    setMembers]    = useState([])
  const [allMembers, setAllMembers] = useState([])
  const [loading,    setLoading]    = useState(true)
  const [assigning,  setAssigning]  = useState(false)
  const [selected,   setSelected]   = useState('')
  const [removing,   setRemoving]   = useState(null)
  const [showAdd,    setShowAdd]    = useState(false)
  const [notice,     setNotice]     = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [cRes, amRes] = await Promise.all([
        getCell(id),
        getMembers({ per_page: 200 }),
      ])
      setCell(cRes.data.data)
      setMembers(cRes.data.data.members ?? [])
      setAllMembers(amRes.data.data)
    } catch {
      navigate('/cells')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => { fetchData() }, [fetchData])

  const handleAssign = async () => {
    if (!selected) return
    setAssigning(true)
    setNotice(null)
    try {
      const res = await assignToCell(id, selected)
      setNotice({ ok: true, text: res.data.message })
      setSelected('')
      setShowAdd(false)
      fetchData()
    } catch (err) {
      setNotice({ ok: false, text: err.response?.data?.message ?? 'Failed to assign member.' })
    } finally {
      setAssigning(false)
    }
  }

  const handleRemove = async (memberId) => {
    if (!confirm('Remove this member from the cell? They will have no cell until reassigned.')) return
    setRemoving(memberId)
    try {
      await unassignFromCell(id, memberId)
      fetchData()
    } catch {
      alert('Failed to remove member.')
    } finally {
      setRemoving(null)
    }
  }

  // Members not already in THIS cell (includes those in other cells —
  // assigning them will MOVE them here, which the backend handles).
  const availableMembers = allMembers.filter(
    m => !members.some(cm => cm.id === m.id)
  )

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}}
           fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/cells')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div className="flex-1">
          <h2 className="text-xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {cell?.name}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {members.length} members · {cell?.is_active ? 'Active' : 'Inactive'}
            {cell?.leader && <> · Led by {cell.leader.name}</>}
          </p>
        </div>
        <button onClick={() => navigate(`/cells/${id}/edit`)}
                className="px-4 py-2 rounded-lg text-sm font-semibold"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
          Edit Cell
        </button>
      </div>

      {cell?.description && (
        <div className="card">
          <p className="text-sm" style={{color:'#374151'}}>{cell.description}</p>
        </div>
      )}

      {notice && (
        <div className="card" style={{backgroundColor: notice.ok ? '#dcfce7' : '#fef2f2',
                                      border: `1px solid ${notice.ok ? '#86efac' : '#fecaca'}`}}>
          <p className="text-sm font-medium" style={{color: notice.ok ? '#15803d' : '#b91c1c'}}>{notice.text}</p>
        </div>
      )}

      <div className="card p-0 overflow-hidden">
        <div className="px-6 py-4 flex items-center justify-between"
             style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <h3 className="font-semibold" style={{color:'var(--color-navy)'}}>Cell Members</h3>
          <button onClick={() => setShowAdd(!showAdd)} className="btn-primary text-sm px-3 py-1.5 gap-1">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Assign Member
          </button>
        </div>

        {showAdd && (
          <div className="px-6 py-4"
               style={{backgroundColor:'#f9fafb',borderBottom:'1px solid var(--color-surface-border)'}}>
            <div className="flex items-center gap-3">
              <MemberSearchPicker
                members={availableMembers}
                value={selected}
                onChange={setSelected}
                placeholder="Search members by name or number..."
                disabled={assigning}
              />
              <button onClick={handleAssign} disabled={!selected || assigning} className="btn-primary px-4 py-2.5 text-sm">
                {assigning ? 'Assigning...' : 'Assign'}
              </button>
              <button onClick={() => { setShowAdd(false); setSelected('') }}
                      className="px-4 py-2.5 rounded-lg text-sm font-semibold"
                      style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
                Cancel
              </button>
            </div>
            <p className="text-xs mt-2" style={{color:'#9ca3af'}}>
              Each member belongs to one cell. Assigning a member already in another cell will move them here.
            </p>
          </div>
        )}

        {members.length === 0 ? (
          <div className="text-center py-12">
            <div className="text-4xl mb-3">👥</div>
            <p className="font-semibold" style={{color:'var(--color-navy)'}}>No members yet</p>
            <p className="text-sm mt-1" style={{color:'#9ca3af'}}>Click "Assign Member" to add members to this cell</p>
          </div>
        ) : (
          <table className="w-full">
            <thead>
              <tr style={{backgroundColor:'#f9fafb',borderBottom:'1px solid var(--color-surface-border)'}}>
                {['Member', 'Phone', 'Status', 'Action'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{color:'#6b7280'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {members.map((member, i) => (
                <tr key={member.id}
                    style={{borderBottom:'1px solid var(--color-surface-border)', backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white"
                           style={{backgroundColor:'var(--color-navy)'}}>
                        {(member.first_name ?? '?').charAt(0)}
                      </div>
                      <span className="text-sm font-semibold" style={{color:'#111827'}}>
                        {member.first_name} {member.last_name}
                      </span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm font-mono" style={{color:'#6b7280'}}>{member.phone ?? '—'}</td>
                  <td className="px-4 py-3 text-sm capitalize" style={{color:'#374151'}}>{member.status}</td>
                  <td className="px-4 py-3">
                    <button onClick={() => handleRemove(member.id)} disabled={removing === member.id}
                            className="text-xs px-2 py-1 rounded font-medium"
                            style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                      {removing === member.id ? '...' : 'Remove'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
