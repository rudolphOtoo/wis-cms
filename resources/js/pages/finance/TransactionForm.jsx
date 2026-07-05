import { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import {
  createTransaction, updateTransaction, getTransaction, getFinanceCategories,
} from '../../api/finance'
import { getMembers } from '../../api/members'

const FIELD = ({ label, error, children }) => (
  <div>
    <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>{label}</label>
    {children}
    {error && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{error}</p>}
  </div>
)

export default function TransactionForm() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const isEdit   = Boolean(id)

  const [form, setForm] = useState({
    type:'income', category_id:'', member_id:'', amount:'',
    transaction_date: new Date().toISOString().split('T')[0],
    reference:'', notes:'',
  })

  const [categories, setCats]      = useState([])
  const [members,    setMembers]   = useState([])
  const [memberSearch, setMemberSearch] = useState('')
  const [memberOpen,   setMemberOpen]   = useState(false)
  const [errors,     setErrors]    = useState({})
  const [loading,    setLoading]   = useState(false)
  const [fetching,   setFetching]  = useState(isEdit)

  useEffect(() => {
    Promise.all([
      getFinanceCategories(),
      getMembers({ per_page: 500, status: 'active' }),
    ]).then(([cRes, mRes]) => {
      setCats(cRes.data.data)
      setMembers(mRes.data.data)
    })

    if (isEdit) {
      setFetching(true)
      getTransaction(id)
        .then(res => {
          const t = res.data.data
          setForm({
            type:             t.type             ?? 'income',
            category_id:      t.category?.id     ?? '',
            member_id:        t.member?.id       ?? '',
            amount:           t.amount           ?? '',
            transaction_date: t.transaction_date ?? '',
            reference:        t.reference        ?? '',
            notes:            t.notes            ?? '',
          })
        })
        .catch(() => navigate('/finance'))
        .finally(() => setFetching(false))
    }
  }, [id, isEdit])

  const set = (field) => (e) => {
    setForm(f => ({ ...f, [field]: e.target.value }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  // Filter categories by selected type
  const visibleCategories = categories.filter(c => c.type === form.type)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})
    try {
      const payload = { ...form, member_id: form.member_id || null }
      if (isEdit) { await updateTransaction(id, payload) }
      else        { await createTransaction(payload) }
      navigate('/finance')
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
      } else {
        toast.error('Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  if (fetching) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}}
           fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10"
                stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="max-w-2xl mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/finance')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {isEdit ? 'Edit Transaction' : 'Record Transaction'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isEdit ? 'Update transaction details' : 'Add a new income or expense entry'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">

        {/* Type toggle */}
        <div className="card">
          <label className="block text-sm font-semibold mb-3" style={{color:'#374151'}}>
            Transaction Type *
          </label>
          <div className="grid grid-cols-2 gap-3">
            {[
              { v:'income',  label:'💰 Income',  desc:'Tithes, offerings, donations' },
              { v:'expense', label:'📤 Expense', desc:'Utilities, salaries, supplies' },
            ].map(opt => (
              <button key={opt.v} type="button"
                      onClick={() => { setForm(f => ({ ...f, type: opt.v, category_id:'' })) }}
                      className="p-4 rounded-xl text-left transition-all"
                      style={{
                        backgroundColor: form.type === opt.v
                          ? (opt.v === 'income' ? '#dcfce7' : '#fee2e2')
                          : 'white',
                        border: form.type === opt.v
                          ? `2px solid ${opt.v === 'income' ? '#15803d' : '#dc2626'}`
                          : '2px solid var(--color-surface-border)',
                      }}>
                <div className="font-bold mb-1"
                     style={{color: form.type === opt.v
                       ? (opt.v === 'income' ? '#15803d' : '#dc2626')
                       : '#374151'}}>
                  {opt.label}
                </div>
                <div className="text-xs" style={{color:'#6b7280'}}>{opt.desc}</div>
              </button>
            ))}
          </div>
        </div>

        {/* Transaction details */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider"
              style={{color:'var(--color-navy)'}}>
            Transaction Details
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="Category *" error={errors.category_id?.[0]}>
              <select className="input-field" value={form.category_id}
                      onChange={set('category_id')} required>
                <option value="">Select category</option>
                {visibleCategories.map(c => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </FIELD>
            <FIELD label="Amount (GHS) *" error={errors.amount?.[0]}>
              <input type="number" step="0.01" min="0" className="input-field"
                     value={form.amount} onChange={set('amount')}
                     required placeholder="0.00"/>
            </FIELD>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="Transaction Date *" error={errors.transaction_date?.[0]}>
              <input type="date" className="input-field" value={form.transaction_date}
                     onChange={set('transaction_date')} required/>
            </FIELD>
            <FIELD label="Reference (optional)" error={errors.reference?.[0]}>
              <input type="text" className="input-field" value={form.reference}
                     onChange={set('reference')} placeholder="Receipt # / Invoice #"/>
            </FIELD>
          </div>

          {form.type === 'income' && (
            <FIELD label="Member (for personal contributions like tithe)" error={errors.member_id?.[0]}>
              {(() => {
                const selected = members.find(m => m.id === form.member_id)
                const q = memberSearch.trim().toLowerCase()
                const filtered = q
                  ? members.filter(m =>
                      (m.full_name || '').toLowerCase().includes(q) ||
                      (m.member_number || '').toLowerCase().includes(q))
                      .slice(0, 8)
                  : members.slice(0, 8)
                return (
                  <div style={{position:'relative'}}>
                    {selected ? (
                      <div className="input-field flex items-center justify-between"
                           style={{cursor:'pointer'}}
                           onClick={() => { setMemberOpen(true); setMemberSearch('') }}>
                        <span>{selected.full_name} <span style={{color:'#9ca3af'}}>({selected.member_number})</span></span>
                        <button type="button" aria-label="Clear selection"
                                onClick={(e) => { e.stopPropagation(); setForm(f => ({...f, member_id:''})); setMemberSearch(''); setMemberOpen(false) }}
                                style={{color:'#6b7280',padding:'0 8px',fontSize:'18px',lineHeight:1}}>×</button>
                      </div>
                    ) : (
                      <input type="text" className="input-field"
                             placeholder="Anonymous / General — type to search by name or number..."
                             value={memberSearch}
                             onChange={e => { setMemberSearch(e.target.value); setMemberOpen(true) }}
                             onFocus={() => setMemberOpen(true)}
                             onBlur={() => setTimeout(() => setMemberOpen(false), 150)}/>
                    )}
                    {memberOpen && !selected && (
                      <div style={{position:'absolute',top:'100%',left:0,right:0,marginTop:'4px',
                                   backgroundColor:'white',border:'1px solid var(--color-surface-border)',
                                   borderRadius:'8px',boxShadow:'0 4px 12px rgba(0,0,0,0.08)',
                                   maxHeight:'260px',overflowY:'auto',zIndex:20}}>
                        {filtered.length === 0 ? (
                          <div style={{padding:'10px 12px',color:'#9ca3af',fontSize:'13px'}}>No members match that search.</div>
                        ) : filtered.map(m => (
                          <div key={m.id}
                               onMouseDown={(e) => { e.preventDefault(); setForm(f => ({...f, member_id: m.id})); setMemberOpen(false); setMemberSearch('') }}
                               style={{padding:'10px 12px',cursor:'pointer',fontSize:'14px',
                                       borderBottom:'1px solid #f3f4f6'}}
                               onMouseEnter={(e) => e.currentTarget.style.backgroundColor = '#f8f9fc'}
                               onMouseLeave={(e) => e.currentTarget.style.backgroundColor = 'white'}>
                            <div style={{fontWeight:600,color:'var(--color-navy)'}}>{m.full_name}</div>
                            <div style={{fontSize:'12px',color:'#6b7280'}}>{m.member_number}</div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )
              })()}
            </FIELD>
          )}

          <FIELD label="Notes" error={errors.notes?.[0]}>
            <textarea className="input-field" value={form.notes}
                      onChange={set('notes')} rows={2}
                      placeholder="Additional details about this transaction..."/>
          </FIELD>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/finance')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',
                          border:'1px solid var(--color-surface-border)',color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="btn-primary px-8 py-2.5">
            {loading ? 'Saving...' : isEdit ? 'Update Transaction' : 'Record Transaction'}
          </button>
        </div>
      </form>
    </div>
  )
}
