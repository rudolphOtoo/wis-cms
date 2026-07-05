import React, { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import { getCell, assignToCell, unassignFromCell, assignChildToCell, unassignChildFromCell } from '../../api/cells'
import { createMember, getMembers } from '../../api/members'
import { createChild, getChildren } from '../../api/children'
import MemberSearchPicker from '../../components/MemberSearchPicker'
import ChildSearchPicker from '../../components/ChildSearchPicker'
import { useConfirm } from '../../hooks/useConfirm'

export default function CellDetail() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const { confirm, dialog } = useConfirm()
  const [cell,       setCell]       = useState(null)
  const [members,    setMembers]    = useState([])
  const [children,   setChildren]   = useState([])
  const [allMembers, setAllMembers] = useState([])
  const [allChildren, setAllChildren] = useState([])
  const [loading,    setLoading]    = useState(true)
  const [assigning,  setAssigning]  = useState(false)
  const [selected,   setSelected]   = useState('')
  const [removing,   setRemoving]   = useState(null)
  const [showAdd,    setShowAdd]    = useState(false)
  const [addTab,     setAddTab]     = useState('new')
  const [newMember,  setNewMember]  = useState({ first_name: '', last_name: '', phone: '', gender: '' })
  const [newChild,   setNewChild]   = useState({ first_name: '', last_name: '', gender: '', date_of_birth: '' })
  const [creating,   setCreating]   = useState(false)
  const [notice,     setNotice]     = useState(null)

  const isChildrenMinistry = cell?.name === 'Children Ministry'

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const cRes = await getCell(id)
      console.log('[DEBUG] cell name from API:', JSON.stringify(cRes.data.data?.name), '| isChildrenMinistry:', cRes.data.data?.name === 'Children Ministry')
      setCell(cRes.data.data)
      setMembers(cRes.data.data.members ?? [])
      setChildren(cRes.data.data.children ?? [])

      if (cRes.data.data.name === 'Children Ministry') {
        const chRes = await getChildren({ per_page: 200, is_active: true, cell_id: 'null' })
        setAllChildren(chRes.data.data ?? [])
        setAllMembers([])
      } else {
        const amRes = await getMembers({ per_page: 200, unscoped: 1 })
        setAllMembers(amRes.data.data)
        setAllChildren([])
      }
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
      const res = isChildrenMinistry
        ? await assignChildToCell(id, selected)
        : await assignToCell(id, selected)
      setNotice({ ok: true, text: res.data.message })
      setSelected('')
      setShowAdd(false)
      fetchData()
    } catch (err) {
      const label = isChildrenMinistry ? 'child' : 'member'
      setNotice({ ok: false, text: err.response?.data?.message ?? `Failed to assign ${label}.` })
    } finally {
      setAssigning(false)
    }
  }

  const handleCreateAndAssign = async () => {
    if (isChildrenMinistry) {
      if (!newChild.first_name || !newChild.last_name || !newChild.gender) {
        toast.error('First name, last name, and gender are required.')
        return
      }
      setCreating(true)
      setNotice(null)
      try {
        const res = await createChild({ ...newChild, is_active: true })
        const cId = res.data.data.id
        await assignChildToCell(id, cId)
        setNotice({ ok: true, text: `${newChild.first_name} ${newChild.last_name} has been added to the cell.` })
        setNewChild({ first_name: '', last_name: '', gender: '', date_of_birth: '' })
        setShowAdd(false)
        fetchData()
      } catch (err) {
        setNotice({ ok: false, text: err.response?.data?.message ?? 'Failed to create child.' })
      } finally {
        setCreating(false)
      }
      return
    }

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

  const handleRemove = async (recordId) => {
    const label = isChildrenMinistry ? 'child' : 'member'
    if (!(await confirm(`Remove this ${label} from the cell?`))) return
    setRemoving(recordId)
    try {
      if (isChildrenMinistry) {
        await unassignChildFromCell(id, recordId)
      } else {
        await unassignFromCell(id, recordId)
      }
      fetchData()
    } catch {
      toast.error(`Failed to remove ${label}.`)
    } finally {
      setRemoving(null)
    }
  }

  const unassignedMembers = allMembers.filter(m => m.cell_id === null)
  const unassignedChildren = allChildren

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
            {isChildrenMinistry ? children.length : members.length} {isChildrenMinistry ? 'children' : 'members'} · {cell?.is_active ? 'Active' : 'Inactive'}
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
          <h3 className="font-semibold" style={{color:'var(--color-navy)'}}>
            {isChildrenMinistry ? 'Children' : 'Cell Members'}
          </h3>
          <button onClick={() => setShowAdd(!showAdd)} className="btn-primary text-sm px-3 py-1.5 gap-1">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            {isChildrenMinistry ? 'Add Child' : 'Add Member'}
          </button>
        </div>

        {showAdd && (
          <div>
            <div className="flex border-b" style={{borderColor:'var(--color-surface-border)'}}>
              <button onClick={() => setAddTab('new')}
                      className="px-4 py-2.5 text-sm font-semibold transition-colors"
                      style={{
                        color: addTab === 'new' ? 'var(--color-navy)' : '#6b7280',
                        borderBottom: addTab === 'new' ? '2px solid var(--color-navy)' : '2px solid transparent',
                      }}>
                {isChildrenMinistry ? 'New Child' : 'New Member'}
              </button>
              <button onClick={() => setAddTab('existing')}
                      className="px-4 py-2.5 text-sm font-semibold transition-colors"
                      style={{
                        color: addTab === 'existing' ? 'var(--color-navy)' : '#6b7280',
                        borderBottom: addTab === 'existing' ? '2px solid var(--color-navy)' : '2px solid transparent',
                      }}>
                From Church
              </button>
            </div>

            <div className="px-6 py-4"
                 style={{backgroundColor:'#f9fafb',borderBottom:'1px solid var(--color-surface-border)'}}>
              {addTab === 'new' && isChildrenMinistry ? (
                <div className="space-y-3">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>First Name *</label>
                      <input type="text" className="input-field" value={newChild.first_name}
                             onChange={e => setNewChild(f => ({...f, first_name: e.target.value}))}
                             placeholder="e.g. Adwoa"/>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>Last Name *</label>
                      <input type="text" className="input-field" value={newChild.last_name}
                             onChange={e => setNewChild(f => ({...f, last_name: e.target.value}))}
                             placeholder="e.g. Mensah"/>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>Gender *</label>
                      <select className="input-field" value={newChild.gender}
                              onChange={e => setNewChild(f => ({...f, gender: e.target.value}))}>
                        <option value="">Select</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold mb-1" style={{color:'#374151'}}>Date of Birth</label>
                      <input type="date" className="input-field" value={newChild.date_of_birth}
                             onChange={e => setNewChild(f => ({...f, date_of_birth: e.target.value}))}/>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 pt-1">
                    <button onClick={handleCreateAndAssign} disabled={creating} className="btn-primary px-4 py-2 text-sm">
                      {creating ? 'Creating...' : 'Create & Assign'}
                    </button>
                    <button onClick={() => { setShowAdd(false); setNewChild({ first_name: '', last_name: '', gender: '', date_of_birth: '' }) }}
                            className="px-4 py-2 rounded-lg text-sm font-semibold"
                            style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
                      Cancel
                    </button>
                  </div>
                </div>
              ) : addTab === 'new' ? (
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
                            style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
                      Cancel
                    </button>
                  </div>
                </div>
              ) : isChildrenMinistry ? (
                <div>
                  <div className="flex items-center gap-3">
                    <ChildSearchPicker
                      records={unassignedChildren}
                      value={selected}
                      onChange={setSelected}
                      placeholder="Search unassigned children..."
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
                    Only children not currently assigned to any cell are shown.
                  </p>
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
                            style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
                      Cancel
                    </button>
                  </div>
                  <p className="text-xs mt-2" style={{color:'#9ca3af'}}>
                    Only members not currently assigned to any cell are shown.
                  </p>
                </div>
              )}
            </div>
          </div>
        )}

        {isChildrenMinistry && children.length === 0 ? (
          <div className="text-center py-12">
            <div className="text-4xl mb-3">👶</div>
            <p className="font-semibold" style={{color:'var(--color-navy)'}}>No children yet</p>
            <p className="text-sm mt-1" style={{color:'#9ca3af'}}>Click "Add Child" to add children to this cell</p>
          </div>
        ) : !isChildrenMinistry && members.length === 0 ? (
          <div className="text-center py-12">
            <div className="text-4xl mb-3">👥</div>
            <p className="font-semibold" style={{color:'var(--color-navy)'}}>No members yet</p>
            <p className="text-sm mt-1" style={{color:'#9ca3af'}}>Click "Add Member" to add members to this cell</p>
          </div>
        ) : isChildrenMinistry ? (
          <table className="w-full">
            <thead>
              <tr style={{backgroundColor:'#f9fafb',borderBottom:'1px solid var(--color-surface-border)'}}>
                {['Child', 'Class', 'Age', 'Parent', 'Action'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{color:'#6b7280'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {children.map((child, i) => (
                <tr key={child.id}
                    style={{borderBottom:'1px solid var(--color-surface-border)', backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white"
                           style={{backgroundColor:'var(--color-navy)'}}>
                        {(child.first_name ?? '?').charAt(0)}
                      </div>
                      <span className="text-sm font-semibold" style={{color:'#111827'}}>
                        {child.first_name} {child.last_name}
                      </span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>{child.class_group ?? '—'}</td>
                  <td className="px-4 py-3 text-sm" style={{color:'#6b7280'}}>{child.age ?? '—'}</td>
                  <td className="px-4 py-3 text-sm" style={{color:'#6b7280'}}>{child.guardian?.name ?? '—'}</td>
                  <td className="px-4 py-3">
                    <button onClick={() => handleRemove(child.id)} disabled={removing === child.id}
                            className="text-xs px-2 py-1 rounded font-medium"
                            style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                      {removing === child.id ? '...' : 'Remove'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
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
      {dialog}
    </div>
  )
}
