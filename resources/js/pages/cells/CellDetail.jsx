import React, { memo, useState, useEffect, useCallback, useMemo } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import { getCell, assignToCell, unassignFromCell } from '../../api/cells'
import { createMember, getMembers } from '../../api/members'
import MemberSearchPicker from '../../components/MemberSearchPicker'
import { Users } from 'lucide-react'
import { useConfirm } from '../../hooks/useConfirm'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
export default function CellDetail() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const { confirm, dialog } = useConfirm()
  const [cell,       setCell]       = useState(null)
  const [members,    setMembers]    = useState([])
  const [allMembers, setAllMembers] = useState([])
  const [loading,    setLoading]    = useState(true)
  const [assigning,  setAssigning]  = useState(false)
  const [selected,   setSelected]   = useState('')
  const [removing,   setRemoving]   = useState(null)
  const [showAdd,    setShowAdd]    = useState(false)
  const [addTab,     setAddTab]     = useState('new')
  const [newMember,  setNewMember]  = useState({ first_name: '', last_name: '', phone: '', gender: '' })
  const [creating,   setCreating]   = useState(false)
  const [notice,     setNotice]     = useState(null)

  const fetchData = useCallback(async (signal) => {
    setLoading(true)
    try {
      const [cRes, amRes] = await Promise.all([
        getCell(id, signal),
        getMembers({ per_page: 200, unscoped: 1 }, signal),
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

  useEffect(() => {
    const controller = new AbortController()
    fetchData(controller.signal)
    return () => controller.abort()
  }, [fetchData])

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

  const handleCreateAndAssign = async () => {
    if (!newMember.first_name || !newMember.last_name || !newMember.gender) {
      toast.error('First name, last name, and gender are required.')
      return
    }
    setCreating(true)
    setNotice(null)
    try {
      const res = await createMember({ ...newMember, status: 'active' })
      const mId = res.data.data.id
      await assignToCell(id, mId)
      setNotice({ ok: true, text: `${newMember.first_name} ${newMember.last_name} has been added to the cell.` })
      setNewMember({ first_name: '', last_name: '', phone: '', gender: '' })
      setShowAdd(false)
      fetchData()
    } catch (err) {
      setNotice({ ok: false, text: err.response?.data?.message ?? 'Failed to create member.' })
    } finally {
      setCreating(false)
    }
  }

  const handleRemove = async (memberId) => {
    if (!(await confirm('Remove this member from the cell? They will have no cell until reassigned.'))) return
    setRemoving(memberId)
    try {
      await unassignFromCell(id, memberId)
      fetchData()
    } catch {
      toast.error('Failed to remove member.')
    } finally {
      setRemoving(null)
    }
  }

  const unassignedMembers = useMemo(() => {
    return allMembers.filter(m => m.cell_id === null)
  }, [allMembers])

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:NAVY}}
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
                 aria-label="Back to cells"
                 className="min-w-[44px] min-h-[44px] flex items-center justify-center p-2 rounded-lg"
                 style={{backgroundColor:'white',border:BORDER}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div className="flex-1">
          <h2 className="text-xl font-bold" style={{fontFamily:FONT_DISPLAY,color:NAVY}}>
            {cell?.name}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {members.length} members · {cell?.is_active ? 'Active' : 'Inactive'}
            {cell?.leader && <> · Led by {cell.leader.name}</>}
          </p>
        </div>
        <button onClick={() => navigate(`/cells/${id}/edit`)}
                className="px-4 py-2 rounded-lg text-sm font-semibold"
                style={{backgroundColor:'white',border:BORDER,color:'#374151'}}>
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

      <div className="card p-0">
        <div className="px-6 py-4 flex items-center justify-between"
             style={{borderBottom:BORDER}}>
          <h3 className="font-semibold" style={{color:NAVY}}>Cell Members</h3>
          <button onClick={() => setShowAdd(!showAdd)} className="btn-primary text-sm min-h-[44px] min-w-[44px] px-3 py-1.5 gap-1">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Add Member
          </button>
        </div>

        {showAdd && (
          <div>
            <div className="flex border-b" style={{borderColor:'var(--color-surface-border)'}}>
              <button onClick={() => setAddTab('new')}
                      className="px-4 py-2.5 text-sm font-semibold transition-colors"
                      style={{
                        color: addTab === 'new' ? NAVY : '#6b7280',
                        borderBottom: addTab === 'new' ? '2px solid var(--color-navy)' : '2px solid transparent',
                      }}>
                New Member
              </button>
              <button onClick={() => setAddTab('existing')}
                      className="px-4 py-2.5 text-sm font-semibold transition-colors"
                      style={{
                        color: addTab === 'existing' ? NAVY : '#6b7280',
                        borderBottom: addTab === 'existing' ? '2px solid var(--color-navy)' : '2px solid transparent',
                      }}>
                From Church
              </button>
            </div>

            <div className="px-6 py-4"
                 style={{backgroundColor:'#f9fafb',borderBottom:BORDER}}>
              {addTab === 'new' ? (
                <div className="space-y-3">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>First Name *</label>
                      <input type="text" className="input-field" value={newMember.first_name}
                             onChange={e => setNewMember(f => ({...f, first_name: e.target.value}))}
                             placeholder="e.g. Kwame"/>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>Last Name *</label>
                      <input type="text" className="input-field" value={newMember.last_name}
                             onChange={e => setNewMember(f => ({...f, last_name: e.target.value}))}
                             placeholder="e.g. Asante"/>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>Phone</label>
                      <input type="text" className="input-field" value={newMember.phone}
                             onChange={e => setNewMember(f => ({...f, phone: e.target.value}))}
                             placeholder="e.g. 054 123 4567"/>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>Gender *</label>
                      <select className="input-field" value={newMember.gender}
                              onChange={e => setNewMember(f => ({...f, gender: e.target.value}))}>
                        <option value="">Select</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                      </select>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 pt-1">
                    <button onClick={handleCreateAndAssign} disabled={creating} className="btn-primary px-4 py-2 text-sm">
                      {creating ? 'Creating...' : 'Create & Assign'}
                    </button>
                    <button onClick={() => { setShowAdd(false); setNewMember({ first_name: '', last_name: '', phone: '', gender: '' }) }}
                            className="px-4 py-2 rounded-lg text-sm font-semibold"
                            style={{backgroundColor:'white',border:BORDER,color:'#374151'}}>
                      Cancel
                    </button>
                  </div>
                </div>
              ) : (
                <div>
                  <div className="flex items-center gap-3">
                    <MemberSearchPicker
                      members={unassignedMembers}
                      value={selected}
                      onChange={setSelected}
                      placeholder="Search unassigned members..."
                      disabled={assigning}
                    />
                    <button onClick={handleAssign} disabled={!selected || assigning} className="btn-primary px-4 py-2.5 text-sm">
                      {assigning ? 'Assigning...' : 'Assign'}
                    </button>
                    <button onClick={() => { setShowAdd(false); setSelected('') }}
                            className="px-4 py-2.5 rounded-lg text-sm font-semibold"
                            style={{backgroundColor:'white',border:BORDER,color:'#374151'}}>
                      Cancel
                    </button>
                  </div>
                  <p className="text-xs mt-2" style={{color:PLACEHOLDER}}>
                    Only members not currently assigned to any cell are shown.
                  </p>
                </div>
              )}
            </div>
          </div>
        )}

        {members.length === 0 ? (
          <div className="text-center py-12">
            <Users size={40} strokeWidth={1.2} className="mx-auto mb-3" style={{color:NAVY}} aria-hidden="true" />
            <p className="font-semibold" style={{color:NAVY}}>No members yet</p>
            <p className="text-sm mt-1" style={{color:PLACEHOLDER}}>Click "Add Member" to add members to this cell</p>
          </div>
        ) : (
          <>
            {/* Mobile card view (below 640px) */}
            <div className="mobile-table-cards">
              <div className="divide-y" style={{borderColor:'var(--color-surface-border)'}}>
                {members.map((member, i) => (
                  <div key={member.id} className="px-4 py-3 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3 min-w-0 flex-1">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                           style={{backgroundColor:NAVY}}>
                        {(member.first_name ?? '?').charAt(0)}
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold truncate" style={{color:'#111827'}}>
                          {member.first_name} {member.last_name}
                        </p>
                        <div className="flex items-center gap-2 text-xs" style={{color:'#6b7280'}}>
                          <span>{member.phone ?? '—'}</span>
                          <span className="capitalize">· {member.status}</span>
                        </div>
                      </div>
                    </div>
                    <button onClick={() => handleRemove(member.id)} disabled={removing === member.id}
                            className="min-h-[44px] min-w-[44px] px-3 rounded-lg text-sm font-medium flex-shrink-0"
                            style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                      {removing === member.id ? '...' : 'Remove'}
                    </button>
                  </div>
                ))}
              </div>
            </div>

            {/* Desktop table (640px and above) */}
            <div className="desktop-table overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr style={{backgroundColor:'#f9fafb',borderBottom:BORDER}}>
                    {['Member', 'Phone', 'Status', 'Action'].map(h => (
                      <th key={h} className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{color:'#6b7280'}}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {members.map((member, i) => (
                    <CellMemberRow
                      key={member.id}
                      member={member}
                      index={i}
                      handleRemove={handleRemove}
                      removing={removing}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>
      {dialog}
    </div>
  )
}

const CellMemberRow = memo(function CellMemberRow({ member, index, handleRemove, removing }) {
  return (
    <tr
      style={{borderBottom:BORDER, backgroundColor: index % 2 === 0 ? 'white' : '#fafafa'}}>
      <td className="px-4 py-3">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white"
               style={{backgroundColor:NAVY}}>
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
                className="min-h-[44px] min-w-[44px] px-3 py-2 rounded-lg text-sm font-medium"
                style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
          {removing === member.id ? '...' : 'Remove'}
        </button>
      </td>
    </tr>
  )
})
