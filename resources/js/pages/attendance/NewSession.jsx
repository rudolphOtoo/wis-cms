import React, { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'
import { getServiceTypes, createSession } from '../../api/attendance'
import { getDepartments } from '../../api/departments'
import { getCells } from '../../api/cells'
import { useAuth } from '../../context/AuthContext'

export default function NewSession() {
  const navigate = useNavigate()
  const { hasRole } = useAuth()
  const notAdmin = !hasRole('super_admin') && !hasRole('pastor') && !hasRole('secretary')
  const isDeptLeader = hasRole('department_leader') && notAdmin
  const isCellLeader = hasRole('cell_leader') && notAdmin
  const isLeader = isDeptLeader || isCellLeader

  const [serviceTypes, setServiceTypes] = useState([])
  const [myDepartments, setMyDepartments] = useState([])
  const [myCells, setMyCells] = useState([])
  const [form, setForm] = useState({
    service_type_id: '',
    department_id: '',
    cell_id: '',
    service_date: new Date().toISOString().split('T')[0],
    notes: '',
  })
  const [loading,  setLoading]  = useState(false)
  const [fetching, setFetching] = useState(true)
  const [errors,   setErrors]   = useState({})

  useEffect(() => {
    Promise.all([
      getServiceTypes().then(res => res.data.data),
      isDeptLeader ? getDepartments().then(res => res.data.data).catch(() => []) : Promise.resolve([]),
      isCellLeader ? getCells().then(res => res.data.data).catch(() => []) : Promise.resolve([]),
    ]).then(([types, depts, cells]) => {
      setServiceTypes(types)
      setMyDepartments(depts)
      setMyCells(cells)
      if (isDeptLeader) {
        const deptMeeting = types.find(t => t.name === 'Department Meeting')
        setForm(f => ({
          ...f,
          service_type_id: deptMeeting?.id ?? '',
          department_id: depts.length === 1 ? depts[0].id : '',
        }))
      }
      if (isCellLeader) {
        const cellMeeting = types.find(t => t.name === 'Cell Meeting')
        setForm(f => ({
          ...f,
          service_type_id: cellMeeting?.id ?? '',
          cell_id: cells.length === 1 ? cells[0].id : '',
        }))
      }
    }).finally(() => setFetching(false))
  }, [isDeptLeader, isCellLeader])

  const set = (field) => (e) => {
    setForm(f => ({ ...f, [field]: e.target.value }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})
    try {
      // Only send the scope field that's relevant
      const payload = { ...form }
      if (!payload.department_id) delete payload.department_id
      if (!payload.cell_id) delete payload.cell_id
      const res = await createSession(payload)
      navigate(`/attendance/${res.data.data.id}`)
    } catch (err) {
      if (err.response?.status === 422) {
        if (err.response.data.session_id) {
          navigate(`/attendance/${err.response.data.session_id}`)
        } else {
          setErrors(err.response.data.errors ?? {})
        }
      } else if (err.response?.status === 403) {
        toast.error(err.response.data.message ?? 'Not allowed.')
      } else {
        toast.error('Something went wrong.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="max-w-lg mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/attendance')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {isCellLeader ? 'Record Cell Meeting' : isDeptLeader ? 'Record Department Meeting' : 'Take Attendance'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isCellLeader ? 'Record attendance for your cell meeting'
              : isDeptLeader ? 'Record attendance for your department meeting'
              : 'Select the service and date to begin'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="card space-y-4">
        {/* Department picker — dept leaders only */}
        {isDeptLeader && (
          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Department *
            </label>
            {myDepartments.length === 0 ? (
              <div className="input-field" style={{color:'#9ca3af'}}>You don't lead a department yet.</div>
            ) : (
              <select className="input-field" value={form.department_id}
                      onChange={set('department_id')} required>
                <option value="">Select department...</option>
                {myDepartments.map(d => (
                  <option key={d.id} value={d.id}>{d.name}</option>
                ))}
              </select>
            )}
            {errors.department_id && (
              <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.department_id[0]}</p>
            )}
          </div>
        )}

        {/* Cell picker — cell leaders only */}
        {isCellLeader && (
          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Cell *
            </label>
            {myCells.length === 0 ? (
              <div className="input-field" style={{color:'#9ca3af'}}>You don't lead a cell yet.</div>
            ) : (
              <select className="input-field" value={form.cell_id}
                      onChange={set('cell_id')} required>
                <option value="">Select cell...</option>
                {myCells.map(c => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            )}
            {errors.cell_id && (
              <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.cell_id[0]}</p>
            )}
          </div>
        )}

        {/* Service type — hidden for leaders (auto-set to Department Meeting) */}
        {!isLeader && (
          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Service Type *
            </label>
            {fetching ? (
              <div className="input-field" style={{color:'#9ca3af'}}>Loading services...</div>
            ) : (
              <select className="input-field" value={form.service_type_id}
                      onChange={set('service_type_id')} required>
                <option value="">Select service...</option>
                {serviceTypes.filter(st => st.name !== 'Department Meeting').map(st => (
                  <option key={st.id} value={st.id}>{st.name}</option>
                ))}
              </select>
            )}
            {errors.service_type_id && (
              <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.service_type_id[0]}</p>
            )}
          </div>
        )}

        <div>
          <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
            {(isDeptLeader || isCellLeader) ? 'Meeting Date *' : 'Service Date *'}
          </label>
          <input type="date" className="input-field" value={form.service_date}
                 onChange={set('service_date')} required/>
          {errors.service_date && (
            <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.service_date[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
            Notes (optional)
          </label>
          <textarea className="input-field" value={form.notes}
                    onChange={set('notes')} rows={2}
                    placeholder={isCellLeader ? 'e.g. Bible study, Prayer meeting' : isDeptLeader ? 'e.g. Weekly rehearsal' : 'e.g. Easter Sunday, Special service...'}/>
        </div>

        <div className="flex gap-3 pt-2">
          <button type="button" onClick={() => navigate('/attendance')}
                  className="flex-1 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading || (isDeptLeader && myDepartments.length === 0) || (isCellLeader && myCells.length === 0)} className="flex-1 btn-primary py-2.5">
            {loading ? 'Starting...' : 'Start Taking Attendance →'}
          </button>
        </div>
      </form>
    </div>
  )
}
