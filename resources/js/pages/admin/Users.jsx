import React, { useState, useEffect, useCallback } from 'react'
import { getUsers, getUserRoles, createUser, updateUser, deleteUser, linkMember, createAndLinkMember, unlinkMember } from '../../api/users'
import { getMembers } from '../../api/members'
import { useAuth } from '../../context/AuthContext'
import { useDebounce } from '../../hooks/useDebounce'

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const ROLE_COLORS = {
  super_admin:       { bg: '#fce7f3', text: '#9d174d' },
  pastor:            { bg: '#dbeafe', text: '#1d4ed8' },
  secretary:         { bg: '#dcfce7', text: '#15803d' },
  finance_officer:   { bg: '#fef3c7', text: '#92400e' },
  department_leader: { bg: '#e0e7ff', text: '#4338ca' },
  usher:             { bg: '#e2e8f0', text: '#475569' },
  member:            { bg: '#f3f4f6', text: '#6b7280' },
}

const AVATAR_BG = ['#1b3a6b', '#c7d7fd', '#ffdcc1', '#e0e7ff', '#dcfce7']

export default function UsersPage() {
  const { user: currentUser } = useAuth()
  const [users,    setUsers]    = useState([])
  const [roles,    setRoles]    = useState([])
  const [loading,  setLoading]  = useState(true)
  const [search,   setSearch]   = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [page,     setPage]     = useState(1)
  const [meta,     setMeta]     = useState(null)
  const [editing,  setEditing]  = useState(null)
  const [deleting, setDeleting] = useState(null)

  const debouncedSearch = useDebounce(search, 400)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [uRes, rRes] = await Promise.all([
        getUsers({ search: debouncedSearch, role: roleFilter, page, per_page: 15 }),
        getUserRoles(),
      ])
      setUsers(uRes.data.data)
      setMeta(uRes.data.meta)
      setRoles(rRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [debouncedSearch, roleFilter, page])

  useEffect(() => { fetchData() }, [fetchData])

  const handleDelete = async (user) => {
    if (!confirm(`Delete ${user.name}? This will remove their account permanently.`)) return
    setDeleting(user.id)
    try {
      await deleteUser(user.id)
      fetchData()
    } catch (err) {
      alert(err.response?.data?.message ?? 'Failed to delete user.')
    } finally {
      setDeleting(null)
    }
  }

  const initials = (name) => name.split(' ').map(w => w.charAt(0)).slice(0, 2).join('')

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
            User Management
          </h2>
          <p style={{color:'#44474f',marginTop:'4px'}}>
            {meta ? `Manage system access for ${meta.total} staff member${meta.total === 1 ? '' : 's'}` : 'Loading...'}
          </p>
        </div>
        <button onClick={() => setEditing('new')} className="btn-primary gap-2" style={{padding:'10px 24px'}}>
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 7a4 4 0 108 0 4 4 0 00-8 0M19 8v6M22 11h-6"/>
          </svg>
          New User
        </button>
      </div>

      {/* Filters */}
      <div style={{...cardBase, padding:'16px 24px'}} className="flex flex-wrap items-center gap-4">
        <div className="flex-1 min-w-[200px] relative">
          <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#747780'}}
               fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input type="text" placeholder="Search by name or email..."
                 className="input-field" style={{paddingLeft:'2.5rem'}}
                 value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
        </div>
        <div className="flex items-center gap-2">
          <span className="uppercase tracking-wider" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>Role</span>
          <select className="input-field" style={{width:'auto'}}
                  value={roleFilter} onChange={e => { setRoleFilter(e.target.value); setPage(1) }}>
            <option value="">All Roles</option>
            {roles.map(r => <option key={r.name} value={r.name}>{r.label}</option>)}
          </select>
        </div>
      </div>

      {/* Table */}
      <div style={{...cardBase, overflow:'hidden'}}>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{backgroundColor:'#f2f3f6'}}>
                {[['Name'],['Email'],['Role'],['Status'],['Last Login'],['Actions','right']].map(([h, align]) => (
                  <th key={h} className="uppercase tracking-wider" style={{padding:'12px 24px',fontSize:'12px',fontWeight:700,color:'#747780',textAlign:align||'left'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={6} className="text-center" style={{padding:'48px',color:'#9ca3af'}}>Loading...</td></tr>
              ) : users.length === 0 ? (
                <tr><td colSpan={6} className="text-center" style={{padding:'48px'}}>
                  <div className="text-4xl mb-3">👤</div>
                  <div className="font-semibold" style={{color:'var(--color-navy)'}}>No users found</div>
                </td></tr>
              ) : users.map((u, i) => {
                const isMe = u.id === currentUser?.id
                const rc = ROLE_COLORS[u.role] ?? { bg:'#f3f4f6', text:'#6b7280' }
                const avBg = AVATAR_BG[i % AVATAR_BG.length]
                const darkAv = avBg === '#1b3a6b'
                return (
                  <tr key={u.id} className="transition-colors" style={{borderTop:'1px solid var(--color-surface-border)'}}
                      onMouseEnter={e => e.currentTarget.style.backgroundColor='#f8f9fc'}
                      onMouseLeave={e => e.currentTarget.style.backgroundColor='transparent'}>
                    <td style={{padding:'16px 24px'}}>
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 font-bold"
                             style={{backgroundColor:avBg, color: darkAv ? '#fff' : 'var(--color-navy)'}}>
                          {initials(u.name)}
                        </div>
                        <div className="flex items-center gap-2">
                          <span style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>{u.name}</span>
                          {isMe && (
                            <span className="uppercase" style={{padding:'1px 6px',borderRadius:'4px',fontSize:'10px',fontWeight:700,backgroundColor:'var(--color-gold)',color:'#fff'}}>You</span>
                          )}
                        </div>
                      </div>
                    </td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f'}}>{u.email}</td>
                    <td style={{padding:'16px 24px'}}>
                      <span className="rounded-full" style={{padding:'4px 12px',fontSize:'12px',fontWeight:700,backgroundColor:rc.bg,color:rc.text}}>
                        {u.role_label ?? '—'}
                      </span>
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <span className="rounded-full" style={{padding:'4px 12px',fontSize:'12px',fontWeight:700,
                              backgroundColor: u.is_active ? '#dcfce7' : '#e1e2e5', color: u.is_active ? '#15803d' : '#44474f'}}>
                        {u.is_active ? 'Active' : 'Disabled'}
                      </span>
                    </td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#747780'}}>{u.last_login_at ?? 'Never'}</td>
                    <td style={{padding:'16px 24px',textAlign:'right'}}>
                      <div className="flex justify-end items-center gap-3">
                        <button onClick={() => setEditing(u)} className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Edit</button>
                        {!isMe && (
                          <button onClick={() => handleDelete(u)} disabled={deleting === u.id}
                                  className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'#ba1a1a'}}>
                            {deleting === u.id ? '...' : 'Delete'}
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between" style={{padding:'16px 24px',backgroundColor:'#f8f9fc',borderTop:'1px solid var(--color-surface-border)'}}>
            <span style={{fontSize:'14px',color:'#747780'}}>Showing page {meta.current_page} of {meta.last_page} · {meta.total} members</span>
            <div className="flex items-center gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="w-8 h-8 rounded flex items-center justify-center disabled:opacity-40"
                      style={{border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/></svg>
              </button>
              <span className="w-8 h-8 rounded flex items-center justify-center font-bold text-white" style={{backgroundColor:'var(--color-navy)',fontSize:'14px'}}>{meta.current_page}</span>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="w-8 h-8 rounded flex items-center justify-center disabled:opacity-40"
                      style={{border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/></svg>
              </button>
            </div>
          </div>
        )}
      </div>

      {editing && (
        <UserModal
          user={editing === 'new' ? null : editing}
          roles={roles}
          onClose={() => setEditing(null)}
          onSuccess={() => { setEditing(null); fetchData() }}
        />
      )}
    </div>
  )
}

function UserModal({ user, roles, onClose, onSuccess }) {
  const isEdit = Boolean(user)

  const [mode,          setMode]          = useState('form')
  const [tempPassword,  setTempPassword]  = useState(null)
  const [copied,        setCopied]        = useState(false)
  const [createdName,   setCreatedName]   = useState('')

  const [form, setForm] = useState({
    name:      user?.name      ?? '',
    email:     user?.email     ?? '',
    password:  '',
    role:      user?.role      ?? '',
    is_active: user?.is_active ?? true,
  })
  const [errors,  setErrors]  = useState({})
  const [loading, setLoading] = useState(false)

  const set = (field) => (e) => {
    const v = e.target.type === 'checkbox' ? e.target.checked : e.target.value
    setForm(f => ({ ...f, [field]: v }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})
    try {
      const data = { ...form }
      if (isEdit) {
        if (!data.password) delete data.password
        await updateUser(user.id, data)
        onSuccess()
      } else {
        // Create — server generates the temporary password.
        delete data.password
        const res = await createUser(data)
        const temp = res?.data?.temp_password ?? null
        if (temp) {
          setTempPassword(temp)
          setCreatedName(form.name)
          setMode('temp-password')
        } else {
          // Defensive: if backend didn't return a temp password, just close.
          onSuccess()
        }
      }
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        if (err.response.data.message && !err.response.data.errors) alert(err.response.data.message)
      } else {
        alert('Something went wrong.')
      }
    } finally {
      setLoading(false)
    }
  }

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(tempPassword)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // older browsers / non-https — fall back silently
      alert('Could not copy automatically. Please copy the password manually.')
    }
  }

  const finishAndClose = () => {
    setTempPassword(null)  // discard from React state
    onSuccess()
    onClose()
  }

  return (
    <div className="fixed inset-0 flex items-center justify-center z-50 p-4" style={{backgroundColor:'rgba(13,31,60,0.4)',backdropFilter:'blur(4px)'}}>
      <div className="bg-white w-full max-w-2xl rounded-2xl shadow-2xl max-h-[90vh] overflow-y-auto">
        <div className="px-6 pt-6 pb-4 flex justify-between items-start" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <div>
            <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'24px',color:'var(--color-navy)'}}>
              {mode === 'temp-password' ? 'User Created' : (isEdit ? 'Edit User' : 'New User')}
            </h2>
            <p style={{fontSize:'14px',color:'#747780',marginTop:'4px'}}>
              {mode === 'temp-password'
                ? 'Share the temporary password with the new user.'
                : 'Update administrative profile and permissions.'}
            </p>
          </div>
          <button onClick={mode === 'temp-password' ? finishAndClose : onClose}
                  className="p-1 rounded hover:bg-gray-100" style={{color:'#747780'}}>
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        {mode === 'temp-password' ? (
          <div className="p-6">
            <div className="rounded-lg p-4 mb-4" style={{backgroundColor:'#dcfce7',border:'1px solid #86efac'}}>
              <div className="flex items-start gap-3">
                <svg className="w-5 h-5 mt-0.5" style={{color:'#15803d'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                  <div style={{fontSize:'14px',fontWeight:700,color:'#15803d'}}>Account created for {createdName}</div>
                  <p style={{fontSize:'13px',color:'#166534',marginTop:'2px'}}>Share the temporary password below. They will be required to change it on first login.</p>
                </div>
              </div>
            </div>

            <label className="block mb-1.5" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Temporary Password</label>
            <div className="flex items-center gap-2 mb-2">
              <input type="text" readOnly value={tempPassword ?? ''}
                     className="input-field"
                     style={{fontFamily:'ui-monospace, SFMono-Regular, Menlo, monospace',fontSize:'16px',letterSpacing:'0.05em'}}
                     onFocus={(e) => e.target.select()}/>
              <button type="button" onClick={handleCopy}
                      className="px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap"
                      style={{backgroundColor: copied ? '#15803d' : 'var(--color-navy)',color:'white'}}>
                {copied ? '✓ Copied' : 'Copy'}
              </button>
            </div>
            <p style={{fontSize:'12px',color:'#747780',fontStyle:'italic'}}>
              This password is shown only once. It cannot be retrieved later — if lost, delete the user and recreate them.
            </p>

            <div className="flex justify-end mt-6 pt-4" style={{borderTop:'1px solid var(--color-surface-border)'}}>
              <button type="button" onClick={finishAndClose} className="btn-primary px-8 py-2">
                I've shared it — Done
              </button>
            </div>
          </div>
        ) : (
        <form onSubmit={handleSubmit} className="p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block mb-1.5" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Full Name *</label>
              <input type="text" className="input-field" value={form.name} onChange={set('name')} required/>
              {errors.name && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.name[0]}</p>}
            </div>
            <div>
              <label className="block mb-1.5" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Email Address *</label>
              <input type="email" className="input-field" value={form.email} onChange={set('email')} required/>
              {errors.email && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.email[0]}</p>}
            </div>

            {isEdit ? (
              <div className="md:col-span-2">
                <label className="block mb-1.5" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Password</label>
                <input type="password" className="input-field" value={form.password} onChange={set('password')}
                       minLength={8} placeholder="••••••••"/>
                <p className="italic mt-1" style={{fontSize:'12px',color:'#747780'}}>Leave blank to keep current password. If you set one, the user will be required to change it on next login.</p>
                {errors.password && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.password[0]}</p>}
              </div>
            ) : (
              <div className="md:col-span-2 rounded-lg p-3 flex items-start gap-3" style={{backgroundColor:'#f8f9fc',border:'1px solid var(--color-surface-border)'}}>
                <svg className="w-5 h-5 mt-0.5 flex-shrink-0" style={{color:'var(--color-navy)'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <div>
                  <div style={{fontSize:'13px',fontWeight:700,color:'var(--color-navy)'}}>A temporary password will be generated</div>
                  <p style={{fontSize:'12px',color:'#747780',marginTop:'2px'}}>You will see it once after creation. Share it with the user — they will be required to change it on first login.</p>
                </div>
              </div>
            )}

            <div>
              <label className="block mb-1.5" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Role Selection *</label>
              <select className="input-field" value={form.role} onChange={set('role')} required>
                <option value="">Select a role</option>
                {roles.map(r => <option key={r.name} value={r.name}>{r.label}</option>)}
              </select>
              {errors.role && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.role[0]}</p>}
            </div>
            <div className="flex items-center justify-between rounded-lg p-3" style={{backgroundColor:'#f8f9fc'}}>
              <div className="flex items-center gap-2">
                <svg className="w-5 h-5" style={{color:'var(--color-navy)'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Active Account</span>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" className="sr-only peer" checked={form.is_active} onChange={set('is_active')}/>
                <div className="rounded-full" style={{width:'44px',height:'24px',backgroundColor: form.is_active ? 'var(--color-navy)' : '#c4c6d0',transition:'background-color 0.2s'}}>
                  <div className="rounded-full bg-white" style={{width:'20px',height:'20px',marginTop:'2px',marginLeft: form.is_active ? '22px' : '2px',transition:'margin-left 0.2s',boxShadow:'0 1px 2px rgba(0,0,0,0.2)'}}/>
                </div>
              </label>
            </div>
            {isEdit && (
              <div className="md:col-span-2">
                <MemberLinkPanel user={user} onChanged={onSuccess} />
              </div>
            )}
          </div>
          <div className="flex justify-end gap-3 mt-6 pt-4" style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <button type="button" onClick={onClose} className="px-5 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>Cancel</button>
            <button type="submit" disabled={loading} className="btn-primary px-8 py-2">
              {loading ? 'Saving...' : isEdit ? 'Save Changes' : 'Create User'}
            </button>
          </div>
        </form>
        )}
      </div>
    </div>
  )
}

function MemberLinkPanel({ user, onChanged }) {
  const linked = user.member  // populated when backend includes member
  const [mode, setMode]       = useState('view') // 'view' | 'pick' | 'create' | 'busy'
  const [members, setMembers] = useState([])
  const [selectedId, setSelectedId] = useState('')
  const [search, setSearch]   = useState('')
  const [createForm, setCreateForm] = useState({
    first_name: '', last_name: '', gender: '', date_of_birth: '', phone: '', email: '',
  })
  const [errors, setErrors] = useState({})
  const [notice, setNotice] = useState(null)

  const openPicker = async () => {
    setMode('pick'); setNotice(null); setSelectedId('')
    try {
      const res = await getMembers({ per_page: 200 })
      setMembers(res.data.data ?? [])
    } catch { setNotice({ ok: false, text: 'Could not load members.' }) }
  }

  const doLink = async () => {
    if (!selectedId) return
    setMode('busy'); setNotice(null)
    try {
      await linkMember(user.id, selectedId)
      setNotice({ ok: true, text: 'Member linked.' })
      onChanged?.()
    } catch (err) {
      setNotice({ ok: false, text: err.response?.data?.message ?? 'Could not link.' })
      setMode('pick')
    }
  }

  const doUnlink = async () => {
    if (!confirm(`Unlink ${linked?.first_name ?? 'this member'} from this user? The user keeps their login.`)) return
    setMode('busy')
    try {
      await unlinkMember(user.id)
      setNotice({ ok: true, text: 'Member unlinked.' })
      onChanged?.()
    } catch (err) {
      setNotice({ ok: false, text: err.response?.data?.message ?? 'Could not unlink.' })
      setMode('view')
    }
  }

  const doCreate = async () => {
    setMode('busy'); setErrors({}); setNotice(null)
    try {
      await createAndLinkMember(user.id, {
        ...createForm,
        date_of_birth: createForm.date_of_birth || null,
        phone: createForm.phone || null,
        email: createForm.email || null,
      })
      setNotice({ ok: true, text: 'Member created and linked.' })
      onChanged?.()
    } catch (err) {
      if (err.response?.status === 422) setErrors(err.response.data.errors ?? {})
      else setNotice({ ok: false, text: err.response?.data?.message ?? 'Could not create member.' })
      setMode('create')
    }
  }

  const filteredMembers = members.filter(m =>
    !search ||
    `${m.first_name} ${m.last_name}`.toLowerCase().includes(search.toLowerCase()) ||
    (m.member_number ?? '').toLowerCase().includes(search.toLowerCase())
  )

  return (
    <div className="rounded-lg p-4" style={{backgroundColor:'#f8f9fc',border:'1px solid var(--color-surface-border)'}}>
      <div className="flex justify-between items-start mb-3">
        <div>
          <div style={{fontSize:'13px',fontWeight:700,color:'var(--color-navy)'}}>Member Link</div>
          <p style={{fontSize:'12px',color:'#747780',marginTop:'2px'}}>Connect this login to a church member record.</p>
        </div>
      </div>

      {notice && (
        <div className="rounded p-2 mb-3" style={{backgroundColor: notice.ok ? '#dcfce7' : '#fee2e2', border: `1px solid ${notice.ok ? '#86efac' : '#fecaca'}`, fontSize:'12px', color: notice.ok ? '#15803d' : '#b91c1c'}}>
          {notice.text}
        </div>
      )}

      {/* LINKED state */}
      {linked && mode === 'view' && (
        <div className="bg-white rounded-lg p-3 flex items-start justify-between gap-3" style={{border:'1px solid var(--color-surface-border)'}}>
          <div>
            <div style={{fontWeight:600,color:'var(--color-navy)'}}>{linked.first_name} {linked.last_name}</div>
            <div className="font-mono" style={{fontSize:'12px',color:'#747780'}}>{linked.member_number}</div>
            {linked.phone && <div style={{fontSize:'12px',color:'#747780'}}>{linked.phone}</div>}
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={openPicker} className="px-3 py-1.5 rounded text-xs font-semibold" style={{backgroundColor:'#edeef1',color:'var(--color-navy)'}}>Change</button>
            <button type="button" onClick={doUnlink} className="px-3 py-1.5 rounded text-xs font-semibold" style={{backgroundColor:'#fef2f2',color:'#b91c1c'}}>Unlink</button>
          </div>
        </div>
      )}

      {/* NOT LINKED state */}
      {!linked && mode === 'view' && (
        <div className="flex gap-2">
          <button type="button" onClick={openPicker} className="px-3 py-2 rounded text-sm font-semibold" style={{backgroundColor:'var(--color-navy)',color:'white'}}>Link to existing member</button>
          <button type="button" onClick={() => { setMode('create'); setNotice(null) }} className="px-3 py-2 rounded text-sm font-semibold" style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>Create new member</button>
        </div>
      )}

      {/* PICK existing */}
      {mode === 'pick' && (
        <div className="space-y-2">
          <input type="text" className="input-field" placeholder="Search by name or member number..." value={search} onChange={e => setSearch(e.target.value)}/>
          <select className="input-field" value={selectedId} onChange={e => setSelectedId(e.target.value)} size={Math.min(8, Math.max(3, filteredMembers.length))}>
            {filteredMembers.length === 0 && <option value="">No members match.</option>}
            {filteredMembers.map(m => (
              <option key={m.id} value={m.id}>{m.first_name} {m.last_name} — {m.member_number} {m.phone ? `(${m.phone})` : ''}</option>
            ))}
          </select>
          <div className="flex gap-2 pt-1">
            <button type="button" onClick={doLink} disabled={!selectedId} className="btn-primary text-sm px-4 py-2" style={{opacity: selectedId ? 1 : 0.5}}>Link</button>
            <button type="button" onClick={() => setMode('view')} className="px-4 py-2 rounded text-sm font-semibold" style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>Cancel</button>
          </div>
        </div>
      )}

      {/* CREATE new */}
      {mode === 'create' && (
        <div className="space-y-3">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label className="block mb-1" style={{fontSize:'12px',fontWeight:600,color:'var(--color-navy)'}}>First Name *</label>
              <input type="text" className="input-field" value={createForm.first_name} onChange={e => setCreateForm(f => ({...f, first_name: e.target.value}))}/>
              {errors.first_name && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.first_name[0]}</p>}
            </div>
            <div>
              <label className="block mb-1" style={{fontSize:'12px',fontWeight:600,color:'var(--color-navy)'}}>Last Name *</label>
              <input type="text" className="input-field" value={createForm.last_name} onChange={e => setCreateForm(f => ({...f, last_name: e.target.value}))}/>
              {errors.last_name && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.last_name[0]}</p>}
            </div>
            <div>
              <label className="block mb-1" style={{fontSize:'12px',fontWeight:600,color:'var(--color-navy)'}}>Gender *</label>
              <select className="input-field" value={createForm.gender} onChange={e => setCreateForm(f => ({...f, gender: e.target.value}))}>
                <option value="">—</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
              {errors.gender && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.gender[0]}</p>}
            </div>
            <div>
              <label className="block mb-1" style={{fontSize:'12px',fontWeight:600,color:'var(--color-navy)'}}>Date of Birth</label>
              <input type="date" className="input-field" value={createForm.date_of_birth} onChange={e => setCreateForm(f => ({...f, date_of_birth: e.target.value}))}/>
            </div>
            <div>
              <label className="block mb-1" style={{fontSize:'12px',fontWeight:600,color:'var(--color-navy)'}}>Phone</label>
              <input type="text" className="input-field" value={createForm.phone} onChange={e => setCreateForm(f => ({...f, phone: e.target.value}))}/>
            </div>
            <div>
              <label className="block mb-1" style={{fontSize:'12px',fontWeight:600,color:'var(--color-navy)'}}>Email</label>
              <input type="email" className="input-field" value={createForm.email} onChange={e => setCreateForm(f => ({...f, email: e.target.value}))}/>
            </div>
          </div>
          <div className="flex gap-2 pt-1">
            <button type="button" onClick={doCreate} className="btn-primary text-sm px-4 py-2">Create &amp; Link</button>
            <button type="button" onClick={() => { setMode('view'); setErrors({}) }} className="px-4 py-2 rounded text-sm font-semibold" style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>Cancel</button>
          </div>
        </div>
      )}

      {mode === 'busy' && (
        <div className="text-sm" style={{color:'#747780'}}>Working…</div>
      )}
    </div>
  )
}
