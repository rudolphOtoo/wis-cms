import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getUsers, getUserRoles, createUser, updateUser, deleteUser } from '../../api/users'
import { useAuth } from '../../context/AuthContext'

const ROLE_COLORS = {
  super_admin:       { bg: '#fce7f3', text: '#9d174d' },
  pastor:            { bg: '#dbeafe', text: '#1d4ed8' },
  secretary:         { bg: '#dcfce7', text: '#15803d' },
  finance_officer:   { bg: '#fef3c7', text: '#92400e' },
  department_leader: { bg: '#e0e7ff', text: '#4338ca' },
  usher:             { bg: '#f3f4f6', text: '#6b7280' },
}

export default function UsersPage() {
  const { user: currentUser } = useAuth()
  const [users,    setUsers]    = useState([])
  const [roles,    setRoles]    = useState([])
  const [loading,  setLoading]  = useState(true)
  const [search,   setSearch]   = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [page,     setPage]     = useState(1)
  const [meta,     setMeta]     = useState(null)
  const [editing,  setEditing]  = useState(null) // user being edited, or 'new' for new user
  const [deleting, setDeleting] = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [uRes, rRes] = await Promise.all([
        getUsers({ search, role: roleFilter, page, per_page: 15 }),
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
  }, [search, roleFilter, page])

  useEffect(() => { fetchData() }, [fetchData])
  useEffect(() => {
    const t = setTimeout(() => fetchData(), 400)
    return () => clearTimeout(t)
  }, [search])

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

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            User Management
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {meta ? `${meta.total} users · ${roles.length} roles` : 'Loading...'}
          </p>
        </div>
        <button onClick={() => setEditing('new')} className="btn-primary gap-2">
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
          </svg>
          New User
        </button>
      </div>

      <div className="card py-4">
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4"
                 style={{color:'#9ca3af'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by name or email..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <select className="input-field" style={{width:'auto'}}
                  value={roleFilter}
                  onChange={e => { setRoleFilter(e.target.value); setPage(1) }}>
            <option value="">All Roles</option>
            {roles.map(r => (
              <option key={r.name} value={r.name}>{r.label}</option>
            ))}
          </select>
        </div>
      </div>

      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr style={{borderBottom:'1px solid var(--color-surface-border)',backgroundColor:'#f9fafb'}}>
                {['Name', 'Email', 'Role', 'Status', 'Last Login', 'Actions'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider"
                      style={{color:'#6b7280'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={6} className="text-center py-12" style={{color:'#9ca3af'}}>Loading...</td></tr>
              ) : users.length === 0 ? (
                <tr>
                  <td colSpan={6} className="text-center py-12">
                    <div className="text-4xl mb-3">👤</div>
                    <div className="font-semibold" style={{color:'var(--color-navy)'}}>No users found</div>
                  </td>
                </tr>
              ) : users.map((u, i) => {
                const isMe = u.id === currentUser?.id
                return (
                  <tr key={u.id}
                      style={{borderBottom:'1px solid var(--color-surface-border)',
                              backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full flex items-center justify-center
                                        flex-shrink-0 text-sm font-bold text-white"
                             style={{backgroundColor:'var(--color-navy)'}}>
                          {u.name.charAt(0)}
                        </div>
                        <div className="text-sm font-semibold flex items-center gap-2" style={{color:'#111827'}}>
                          {u.name}
                          {isMe && (
                            <span className="text-xs px-1.5 py-0.5 rounded font-medium"
                                  style={{backgroundColor:'rgba(201,168,76,0.2)',color:'#a8863c'}}>
                              You
                            </span>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>{u.email}</td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-1 rounded-full text-xs font-semibold"
                            style={{
                              backgroundColor: ROLE_COLORS[u.role]?.bg ?? '#f3f4f6',
                              color:           ROLE_COLORS[u.role]?.text ?? '#6b7280',
                            }}>
                        {u.role_label ?? '—'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-1 rounded-full text-xs font-semibold"
                            style={{
                              backgroundColor: u.is_active ? '#dcfce7' : '#f3f4f6',
                              color:           u.is_active ? '#15803d' : '#6b7280',
                            }}>
                        {u.is_active ? 'Active' : 'Disabled'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm" style={{color:'#6b7280'}}>
                      {u.last_login_at ?? 'Never'}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <button onClick={() => setEditing(u)}
                                className="text-xs px-2 py-1 rounded font-medium"
                                style={{color:'#d97706',backgroundColor:'rgba(217,119,6,0.08)'}}>
                          Edit
                        </button>
                        {!isMe && (
                          <button onClick={() => handleDelete(u)}
                                  disabled={deleting === u.id}
                                  className="text-xs px-2 py-1 rounded font-medium"
                                  style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
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
      if (isEdit && !data.password) delete data.password
      if (isEdit) { await updateUser(user.id, data) }
      else        { await createUser(data) }
      onSuccess()
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        if (err.response.data.message && !err.response.data.errors) {
          alert(err.response.data.message)
        }
      } else {
        alert('Something went wrong.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 flex items-center justify-center z-50 p-4"
         style={{backgroundColor:'rgba(0,0,0,0.5)'}}>
      <div className="bg-white rounded-2xl max-w-md w-full max-h-[90vh] overflow-y-auto shadow-2xl">
        <div className="px-6 py-4 flex items-center justify-between"
             style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <h3 className="text-lg font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {isEdit ? 'Edit User' : 'New User'}
          </h3>
          <button onClick={onClose} className="p-1 rounded hover:bg-gray-100">
            <svg className="w-5 h-5" style={{color:'#6b7280'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Full Name *
            </label>
            <input type="text" className="input-field" value={form.name}
                   onChange={set('name')} required/>
            {errors.name && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.name[0]}</p>}
          </div>

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Email Address *
            </label>
            <input type="email" className="input-field" value={form.email}
                   onChange={set('email')} required/>
            {errors.email && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.email[0]}</p>}
          </div>

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              {isEdit ? 'New Password (leave blank to keep current)' : 'Password *'}
            </label>
            <input type="password" className="input-field" value={form.password}
                   onChange={set('password')} required={!isEdit} minLength={8}
                   placeholder={isEdit ? '••••••••' : 'Minimum 8 characters'}/>
            {errors.password && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.password[0]}</p>}
          </div>

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Role *
            </label>
            <select className="input-field" value={form.role} onChange={set('role')} required>
              <option value="">Select a role</option>
              {roles.map(r => (
                <option key={r.name} value={r.name}>{r.label}</option>
              ))}
            </select>
            {errors.role && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.role[0]}</p>}
          </div>

          <div className="flex items-center gap-3 pt-2">
            <input type="checkbox" id="user_active" checked={form.is_active}
                   onChange={set('is_active')}
                   className="w-4 h-4" style={{accentColor:'var(--color-navy)'}}/>
            <label htmlFor="user_active" className="text-sm font-medium" style={{color:'#374151'}}>
              Account is active (user can log in)
            </label>
          </div>

          <div className="flex items-center justify-end gap-3 pt-4"
               style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <button type="button" onClick={onClose}
                    className="px-5 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
              Cancel
            </button>
            <button type="submit" disabled={loading} className="btn-primary px-6 py-2">
              {loading ? 'Saving...' : isEdit ? 'Update User' : 'Create User'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
