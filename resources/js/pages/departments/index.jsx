import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getDepartments, deleteDepartment, getDepartmentStats } from '../../api/departments'

export default function DepartmentsPage() {
  const navigate = useNavigate()
  const [departments, setDepartments] = useState([])
  const [stats,       setStats]       = useState(null)
  const [loading,     setLoading]     = useState(true)
  const [deleting,    setDeleting]    = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [dRes, sRes] = await Promise.all([
        getDepartments(),
        getDepartmentStats(),
      ])
      setDepartments(dRes.data.data)
      setStats(sRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchData() }, [fetchData])

  const handleDelete = async (dept) => {
    if (!confirm(`Delete "${dept.name}"? Members will be removed from this department.`)) return
    setDeleting(dept.id)
    try {
      await deleteDepartment(dept.id)
      fetchData()
    } catch {
      alert('Failed to delete department.')
    } finally {
      setDeleting(null)
    }
  }

  return (
    <div className="space-y-6">

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {[
          { label: 'Total Departments',   value: stats?.total                  ?? '—' },
          { label: 'Active Departments',  value: stats?.active                 ?? '—' },
          { label: 'Members Assigned',    value: stats?.total_members_assigned ?? '—' },
        ].map(s => (
          <div key={s.label} className="card py-4">
            <div className="text-2xl font-bold"
                 style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              {s.value}
            </div>
            <div className="text-xs mt-1" style={{color:'#6b7280'}}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Departments & Groups
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {departments.length} departments
          </p>
        </div>
        <button onClick={() => navigate('/departments/new')} className="btn-primary gap-2">
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
          </svg>
          New Department
        </button>
      </div>

      {/* Department Cards */}
      {loading ? (
        <div className="flex items-center justify-center py-24">
          <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}}
               fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10"
                    stroke="currentColor" strokeWidth="4"/>
            <path className="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
        </div>
      ) : departments.length === 0 ? (
        <div className="card text-center py-16">
          <div className="text-5xl mb-4">🏛️</div>
          <h3 className="font-bold text-lg mb-2"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            No departments yet
          </h3>
          <p className="text-sm mb-6" style={{color:'#6b7280'}}>
            Create your first department to organise members into groups
          </p>
          <button onClick={() => navigate('/departments/new')} className="btn-primary">
            Create First Department
          </button>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {departments.map(dept => (
            <div key={dept.id} className="card hover:shadow-md transition-shadow">
              <div className="flex items-start justify-between mb-4">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-lg"
                       style={{backgroundColor:'var(--color-navy)'}}>
                    {dept.name.charAt(0)}
                  </div>
                  <div>
                    <h3 className="font-bold text-sm" style={{color:'#111827'}}>{dept.name}</h3>
                    {dept.leader && (
                      <p className="text-xs" style={{color:'#9ca3af'}}>
                        Leader: {dept.leader.name}
                      </p>
                    )}
                  </div>
                </div>
                <span className="px-2 py-0.5 rounded-full text-xs font-semibold"
                      style={{
                        backgroundColor: dept.is_active ? '#dcfce7' : '#f3f4f6',
                        color:           dept.is_active ? '#15803d' : '#6b7280',
                      }}>
                  {dept.is_active ? 'Active' : 'Inactive'}
                </span>
              </div>

              {dept.description && (
                <p className="text-sm mb-4 line-clamp-2" style={{color:'#6b7280'}}>
                  {dept.description}
                </p>
              )}

              <div className="flex items-center justify-between pt-4"
                   style={{borderTop:'1px solid var(--color-surface-border)'}}>
                <div className="flex items-center gap-1.5">
                  <svg className="w-4 h-4" style={{color:'#9ca3af'}}
                       fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                  <span className="text-sm font-semibold" style={{color:'var(--color-navy)'}}>
                    {dept.members_count}
                  </span>
                  <span className="text-xs" style={{color:'#9ca3af'}}>members</span>
                </div>
                <div className="flex items-center gap-2">
                  <button onClick={() => navigate(`/departments/${dept.id}`)}
                          className="text-xs px-2 py-1 rounded font-medium"
                          style={{color:'var(--color-navy)',backgroundColor:'rgba(27,58,107,0.08)'}}>
                    Manage
                  </button>
                  <button onClick={() => navigate(`/departments/${dept.id}/edit`)}
                          className="text-xs px-2 py-1 rounded font-medium"
                          style={{color:'#d97706',backgroundColor:'rgba(217,119,6,0.08)'}}>
                    Edit
                  </button>
                  <button onClick={() => handleDelete(dept)}
                          disabled={deleting === dept.id}
                          className="text-xs px-2 py-1 rounded font-medium"
                          style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                    {deleting === dept.id ? '...' : 'Delete'}
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
