import { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import { useParams, useNavigate } from 'react-router-dom'
import { getMember, getMemberGiving, downloadGivingStatement, promoteMemberToLeader, createMemberLogin } from '../../api/members'
import { getCells } from '../../api/cells'
import { getDepartments } from '../../api/departments'
import { usePermission } from '../../hooks/usePermission'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const STATUS_COLORS = {
  active:      { bg: '#dcfce7', text: '#15803d' },
  inactive:    { bg: '#edeef1', text: '#6b7280' },
  transferred: { bg: '#dbeafe', text: '#2563eb' },
  deceased:    { bg: '#fce7f3', text: '#9d174d' },
}

const CATEGORY_BADGE = {
  Tithe:            { bg: '#d7e2ff', text: '#374766' },
  'Sunday Offering':{ bg: '#ffdcc1', text: '#693c0a' },
}
const badgeFor = (cat) => CATEGORY_BADGE[cat] ?? { bg: '#edeef1', text: '#44474f' }

const softLift = { boxShadow: '0 4px 12px rgba(13,31,60,0.05)' }
const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  ...softLift,
}

export default function MemberDetail() {
  const { id }   = useParams()
  const navigate = useNavigate()
  const [promoteOpen, setPromoteOpen] = useState(false)
  const [loginOpen, setLoginOpen] = useState(false)
  const { can }  = usePermission()

  const [member,  setMember]  = useState(null)
  const [loading, setLoading] = useState(true)

  const [giving,      setGiving]      = useState(null)
  const [givingYear,  setGivingYear]  = useState(null)
  const [givingLoad,  setGivingLoad]  = useState(false)
  const [downloading, setDownloading] = useState(false)

  useEffect(() => {
    getMember(id)
      .then(res => setMember(res.data.data))
      .catch(() => navigate('/members'))
      .finally(() => setLoading(false))
  }, [id])

  const fetchGiving = useCallback(async (year) => {
    setGivingLoad(true)
    try {
      const res = await getMemberGiving(id, year)
      setGiving(res.data.data)
      setGivingYear(res.data.data.year)
    } catch (err) {
      console.error(err)
    } finally {
      setGivingLoad(false)
    }
  }, [id])

  useEffect(() => {
    if (can('view finance')) fetchGiving()
  }, [fetchGiving])

  const handleDownload = async () => {
    setDownloading(true)
    try {
      const res = await downloadGivingStatement(id, givingYear)
      const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `giving-statement-${member.member_number}-${givingYear}.pdf`
      document.body.appendChild(a)
      a.click()
      a.remove()
      window.URL.revokeObjectURL(url)
    } catch {
      toast.error('Failed to download statement.')
    } finally {
      setDownloading(false)
    }
  }

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  if (!member) return null

  const sc = STATUS_COLORS[member.status] ?? STATUS_COLORS.inactive
  const hasFinance = can('view finance')

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Page header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button onClick={() => navigate('/members')}
                  className="w-10 h-10 flex items-center justify-center rounded-full transition-colors"
                  style={{border:'1px solid var(--color-surface-border)',backgroundColor:'white',color:'var(--color-navy)'}}>
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
            </svg>
          </button>
          <div>
            <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
              {member.full_name}
            </h1>
            <p style={{fontSize:'14px',color:'#44474f'}}>Member ID: {member.member_number}</p>
          </div>
        </div>
        {can('edit members') && (
          <button onClick={() => navigate(`/members/${id}/edit`)}
                  className="btn-primary gap-2" style={{padding:'10px 24px'}}>
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            Edit Profile
          </button>
        )}
        {can('manage users') && !member.has_user_account && (
          <button onClick={() => setPromoteOpen(true)}
                  className="gap-2 inline-flex items-center"
                  style={{padding:'10px 24px', backgroundColor:'var(--color-gold)', color:'var(--color-navy)', borderRadius:'8px', fontWeight:600}}>
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Make a Leader
          </button>
        )}
        {can('manage users') && !member.has_user_account && (
          <button onClick={() => setLoginOpen(true)}
                  className="gap-2 inline-flex items-center"
                  style={{padding:'10px 24px', backgroundColor:'white', color:'var(--color-navy)', border:'1px solid var(--color-navy)', borderRadius:'8px', fontWeight:600}}>
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
            </svg>
            Add Login
          </button>
        )}
      </div>

      {/* Two-column layout */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        {/* LEFT — profile */}
        <div className="lg:col-span-5">
          <div style={{...cardBase, padding:'24px'}}>
            <div className="flex items-center gap-4 mb-8">
              <div className="flex items-center justify-center flex-shrink-0 text-white font-bold"
                   style={{width:'96px',height:'96px',borderRadius:'16px',fontSize:'36px',fontFamily:'var(--font-display)',
                           background:'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)',boxShadow:'0 8px 16px rgba(0,36,82,0.12)'}}>
                {member.first_name.charAt(0)}{member.last_name.charAt(0)}
              </div>
              <div>
                <div className="flex items-center gap-2 mb-1">
                  <h2 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>
                    {member.full_name}
                  </h2>
                  <span className="uppercase tracking-wider"
                        style={{padding:'2px 10px',borderRadius:'9999px',fontSize:'11px',fontWeight:700,
                                backgroundColor:sc.bg,color:sc.text}}>
                    {member.status}
                  </span>
                </div>
                {member.occupation && <p style={{color:'#44474f',fontSize:'16px'}}>{member.occupation}</p>}
              </div>
            </div>

            <div className="grid grid-cols-2 gap-y-6 pt-8" style={{borderTop:'1px solid var(--color-surface-border)'}}>
              {[
                ['Gender', member.gender, true],
                ['Phone', member.phone],
                ['Email', member.email],
                ['Date of Birth', member.date_of_birth],
                ['Marital Status', member.marital_status, true],
                ['Join Date', member.join_date],
                ['Baptised', member.is_baptised ? 'Yes' : 'No'],
                ['Address', member.address],
              ].map(([label, value, cap]) => (
                <div key={label}>
                  <p className="uppercase mb-1" style={{fontSize:'11px',fontWeight:700,letterSpacing:'0.03em',color:'#747780'}}>{label}</p>
                  <p className={cap ? 'capitalize' : ''} style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>
                    {value || '—'}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* RIGHT — giving */}
        <div className="lg:col-span-7">
          {hasFinance ? (
            <div style={{...cardBase, overflow:'hidden'}}>
              {/* Header */}
              <div className="flex items-center justify-between"
                   style={{padding:'24px',borderBottom:'1px solid var(--color-surface-border)'}}>
                <div className="flex items-center gap-2">
                  <svg className="w-6 h-6" style={{color:'var(--color-navy)'}} fill="none" stroke="currentColor" strokeWidth={1.8} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 12V7H5a2 2 0 010-4h14v4M3 5v14a2 2 0 002 2h16v-5M18 12a2 2 0 000 4h4v-4h-4z"/>
                  </svg>
                  <h3 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Giving History</h3>
                </div>
                <div className="flex items-center gap-2">
                  {giving?.available_years?.length > 0 && (
                    <select className="input-field" style={{width:'auto',padding:'6px 12px'}}
                            value={givingYear ?? ''} onChange={e => fetchGiving(e.target.value)}>
                      {giving.available_years.map(y => <option key={y} value={y}>{y}</option>)}
                    </select>
                  )}
                  {giving?.total > 0 && (
                    <button onClick={handleDownload} disabled={downloading}
                            className="flex items-center gap-1.5 transition-colors"
                            style={{padding:'6px 16px',borderRadius:'8px',fontSize:'14px',fontWeight:600,
                                    border:'1px solid var(--color-navy)',color:'var(--color-navy)',backgroundColor:'white'}}>
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                      </svg>
                      {downloading ? 'Generating...' : 'Statement'}
                    </button>
                  )}
                </div>
              </div>

              {/* Body */}
              <div style={{padding:'24px'}}>
                {givingLoad ? (
                  <div className="text-center py-8" style={{color:'#9ca3af'}}>Loading giving...</div>
                ) : !giving || giving.total === 0 ? (
                  <div className="text-center py-10">
                    <div className="text-3xl mb-2">💝</div>
                    <div className="text-sm font-semibold" style={{color:'var(--color-navy)'}}>
                      No giving recorded for {givingYear}
                    </div>
                  </div>
                ) : (
                  <>
                    {/* Hero */}
                    <div className="relative overflow-hidden flex flex-col items-center justify-center mb-8"
                         style={{borderRadius:'16px',padding:'40px',background:'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)'}}>
                      <div className="absolute rounded-full" style={{top:'-40px',right:'-40px',width:'160px',height:'160px',background:'rgba(255,255,255,0.06)',filter:'blur(40px)'}}/>
                      <div className="absolute rounded-full" style={{bottom:'-40px',left:'-40px',width:'160px',height:'160px',background:'rgba(255,255,255,0.06)',filter:'blur(40px)'}}/>
                      <span className="relative z-10 mb-2" style={{fontSize:'14px',fontWeight:600,color:'rgba(255,255,255,0.7)'}}>
                        Total Given in {givingYear}
                      </span>
                      <h2 className="relative z-10" style={{fontFamily:'var(--font-display)',fontSize:'42px',fontWeight:700,color:'var(--color-gold-light)'}}>
                        {fmt(giving.total)}
                      </h2>
                      <div className="relative z-10 flex gap-3 mt-6 flex-wrap justify-center">
                        {giving.by_category.map(cat => (
                          <div key={cat.category}
                               style={{padding:'8px 16px',borderRadius:'8px',backgroundColor:'rgba(255,255,255,0.1)',border:'1px solid rgba(255,255,255,0.2)'}}>
                            <p className="uppercase tracking-widest" style={{fontSize:'10px',fontWeight:700,color:'rgba(255,255,255,0.6)'}}>
                              {cat.category} ({cat.count})
                            </p>
                            <p style={{fontWeight:600,color:'white'}}>{fmt(cat.total)}</p>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Transaction table */}
                    <div className="overflow-x-auto">
                      <table className="w-full text-left border-collapse">
                        <thead>
                          <tr style={{backgroundColor:'#f8f9fc',borderBottom:'1px solid var(--color-surface-border)'}}>
                            <th className="uppercase" style={{padding:'12px 16px',fontSize:'11px',fontWeight:700,color:'#747780'}}>Date</th>
                            <th className="uppercase" style={{padding:'12px 16px',fontSize:'11px',fontWeight:700,color:'#747780'}}>Category</th>
                            <th className="uppercase" style={{padding:'12px 16px',fontSize:'11px',fontWeight:700,color:'#747780'}}>Reference</th>
                            <th className="uppercase text-right" style={{padding:'12px 16px',fontSize:'11px',fontWeight:700,color:'#747780'}}>Amount</th>
                          </tr>
                        </thead>
                        <tbody>
                          {giving.transactions.map((t, i) => {
                            const b = badgeFor(t.category)
                            return (
                              <tr key={t.id} style={{borderBottom:'1px solid var(--color-surface-border)'}}>
                                <td style={{padding:'16px',fontSize:'14px',color:'#191c1e'}}>{t.date}</td>
                                <td style={{padding:'16px'}}>
                                  <span className="uppercase" style={{padding:'4px 8px',borderRadius:'4px',fontSize:'11px',fontWeight:700,backgroundColor:b.bg,color:b.text}}>
                                    {t.category}
                                  </span>
                                </td>
                                <td style={{padding:'16px',fontSize:'14px',color:'#44474f',fontFamily:'monospace'}}>{t.reference ?? '—'}</td>
                                <td className="text-right" style={{padding:'16px',fontSize:'14px',fontWeight:700,color:'#15803d'}}>{fmt(t.amount)}</td>
                              </tr>
                            )
                          })}
                        </tbody>
                      </table>
                    </div>
                  </>
                )}
              </div>
            </div>
          ) : (
            <div style={{...cardBase, padding:'40px'}} className="text-center">
              <div className="text-3xl mb-2">🔒</div>
              <div className="text-sm font-semibold" style={{color:'var(--color-navy)'}}>
                Giving history is restricted
              </div>
              <p className="text-xs mt-1" style={{color:'#9ca3af'}}>
                You don't have permission to view financial records.
              </p>
            </div>
          )}
        </div>
      </div>
      {promoteOpen && (
        <PromoteModal member={member} onClose={() => setPromoteOpen(false)}
                      onSuccess={() => { setPromoteOpen(false); window.location.reload() }} />
      )}
      {loginOpen && (
        <LoginAccountModal member={member} onClose={() => setLoginOpen(false)}
                           onSuccess={() => { setLoginOpen(false); window.location.reload() }} />
      )}
    </div>
  )
}

function PromoteModal({ member, onClose, onSuccess }) {
  const [mode, setMode]       = useState('form') // form | submitting | temp-password
  const [type, setType]       = useState('cell')
  const [units, setUnits]     = useState([])
  const [targetId, setTargetId] = useState('')
  const [email, setEmail]     = useState(
    `${(member.first_name ?? '').toLowerCase()}.${(member.last_name ?? '').toLowerCase()}@wis-cms.local`.replace(/\s+/g, '')
  )
  const [nameOverride, setNameOverride] = useState('')
  const [errors, setErrors]   = useState({})
  const [serverErr, setServerErr] = useState('')
  const [tempPassword, setTempPassword] = useState('')
  const [unitName, setUnitName] = useState('')
  const [copied, setCopied]   = useState(false)

  // Load leaderless units when type changes
  useEffect(() => {
    let cancelled = false
    setUnits([]); setTargetId('')
    const fetcher = type === 'cell' ? getCells : getDepartments
    fetcher({ per_page: 100 }).then(res => {
      if (cancelled) return
      const all = res.data.data ?? []
      // Only show units without a current leader (matches backend hard-reject rule)
      setUnits(all.filter(u => !u.leader_user_id && !u.leader))
    }).catch(() => !cancelled && setServerErr('Could not load units.'))
    return () => { cancelled = true }
  }, [type])

  const submit = async () => {
    setMode('submitting'); setErrors({}); setServerErr('')
    try {
      const res = await promoteMemberToLeader(member.id, {
        leadership_type: type,
        target_id: targetId,
        email: email.trim(),
        name: nameOverride.trim() || null,
      })
      setTempPassword(res.data.temp_password)
      setUnitName(res.data.data.unit.name)
      setMode('temp-password')
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        if (err.response.data.message) setServerErr(err.response.data.message)
      } else {
        setServerErr(err.response?.data?.message ?? 'Promotion failed.')
      }
      setMode('form')
    }
  }

  const copy = async () => {
    try { await navigator.clipboard.writeText(tempPassword); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch {}
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{backgroundColor:'rgba(13,31,60,0.6)'}}>
      <div className="bg-white rounded-2xl p-6 w-full max-w-lg" style={{boxShadow:'0 20px 50px rgba(0,0,0,0.2)'}}>
        <div className="flex justify-between items-start mb-4">
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'22px',color:'var(--color-navy)'}}>
            {mode === 'temp-password' ? 'Leader Created' : `Make ${member.full_name} a Leader`}
          </h2>
          <button onClick={onClose} className="text-gray-500 hover:text-gray-800 text-2xl leading-none">×</button>
        </div>

        {mode === 'temp-password' ? (
          <div className="space-y-4">
            <div className="rounded-lg p-3" style={{backgroundColor:'#dcfce7',border:'1px solid #86efac'}}>
              <div style={{fontSize:'13px',fontWeight:600,color:'#15803d'}}>
                {member.full_name} now leads {unitName}.
              </div>
              <div style={{fontSize:'12px',color:'#15803d',marginTop:'2px'}}>
                Share this password — it will not be shown again.
              </div>
            </div>
            <div className="rounded-lg p-3 flex items-center justify-between gap-3" style={{backgroundColor:'#f8f9fc',border:'1px solid var(--color-surface-border)'}}>
              <code style={{fontFamily:'monospace',fontSize:'16px',fontWeight:600,color:'var(--color-navy)',wordBreak:'break-all'}}>{tempPassword}</code>
              <button onClick={copy} className="px-3 py-1.5 rounded text-xs font-semibold whitespace-nowrap"
                      style={{backgroundColor: copied ? '#15803d' : 'var(--color-navy)', color:'white'}}>
                {copied ? 'Copied ✓' : 'Copy'}
              </button>
            </div>
            <p style={{fontSize:'12px',color:'#747780'}}>
              The new leader will be required to change this password on first login.
            </p>
            <div className="flex justify-end">
              <button onClick={onSuccess} className="btn-primary px-6 py-2">Done</button>
            </div>
          </div>
        ) : (
          <div className="space-y-4">
            {serverErr && (
              <div className="rounded p-2" style={{backgroundColor:'#fee2e2',border:'1px solid #fecaca',fontSize:'12px',color:'#b91c1c'}}>
                {serverErr}
              </div>
            )}
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Leadership Type *</label>
              <div className="flex gap-3">
                {[{ v:'cell', label:'Cell Leader' }, { v:'department', label:'Department Leader' }].map(opt => (
                  <label key={opt.v} className="flex-1 cursor-pointer">
                    <input type="radio" name="ltype" value={opt.v} checked={type === opt.v} onChange={() => setType(opt.v)} className="sr-only peer"/>
                    <div className="rounded-lg p-3 text-center text-sm font-semibold transition-all" style={{
                      border: type === opt.v ? '2px solid var(--color-navy)' : '1px solid var(--color-surface-border)',
                      backgroundColor: type === opt.v ? '#eef2ff' : 'white',
                      color: 'var(--color-navy)',
                    }}>{opt.label}</div>
                  </label>
                ))}
              </div>
            </div>
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>{type === 'cell' ? 'Cell' : 'Department'} to Lead *</label>
              <select className="input-field" value={targetId} onChange={e => setTargetId(e.target.value)}>
                <option value="">{units.length === 0 ? 'No leaderless units available' : 'Choose…'}</option>
                {units.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
              </select>
              {errors.target_id && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.target_id[0]}</p>}
            </div>
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Login Email *</label>
              <input type="email" className="input-field" value={email} onChange={e => setEmail(e.target.value)}/>
              {errors.email && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.email[0]}</p>}
            </div>
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Login Name (optional override)</label>
              <input type="text" className="input-field" placeholder={member.full_name} value={nameOverride} onChange={e => setNameOverride(e.target.value)}/>
            </div>
            <div className="flex justify-end gap-3 pt-2">
              <button onClick={onClose} className="px-5 py-2 rounded-lg text-sm font-semibold"
                      style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>Cancel</button>
              <button onClick={submit} disabled={mode === 'submitting' || !targetId || !email}
                      className="btn-primary px-6 py-2" style={{opacity: (!targetId || !email) ? 0.5 : 1}}>
                {mode === 'submitting' ? 'Making a Leader…' : 'Make a Leader & Generate Login'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

function LoginAccountModal({ member, onClose, onSuccess }) {
  const [mode, setMode]       = useState('form')
  const [email, setEmail]     = useState(
    `${(member.first_name ?? '').toLowerCase()}.${(member.last_name ?? '').toLowerCase()}@wis-cms.local`.replace(/\s+/g, '')
  )
  const [nameOverride, setNameOverride] = useState('')
  const [errors, setErrors]   = useState({})
  const [serverErr, setServerErr] = useState('')
  const [tempPassword, setTempPassword] = useState('')
  const [copied, setCopied]   = useState(false)

  const submit = async () => {
    setMode('submitting'); setErrors({}); setServerErr('')
    try {
      const res = await createMemberLogin(member.id, {
        email: email.trim(),
        name: nameOverride.trim() || null,
      })
      setTempPassword(res.data.temp_password)
      setMode('temp-password')
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        if (err.response.data.message) setServerErr(err.response.data.message)
      } else {
        setServerErr(err.response?.data?.message ?? 'Could not create login.')
      }
      setMode('form')
    }
  }

  const copy = async () => {
    try { await navigator.clipboard.writeText(tempPassword); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch {}
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{backgroundColor:'rgba(13,31,60,0.6)'}}>
      <div className="bg-white rounded-2xl p-6 w-full max-w-md" style={{boxShadow:'0 20px 50px rgba(0,0,0,0.2)'}}>
        <div className="flex justify-between items-start mb-4">
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'22px',color:'var(--color-navy)'}}>
            {mode === 'temp-password' ? 'Login Created' : `Add Login for ${member.full_name}`}
          </h2>
          <button onClick={onClose} className="text-gray-500 hover:text-gray-800 text-2xl leading-none">×</button>
        </div>

        {mode === 'temp-password' ? (
          <div className="space-y-4">
            <div className="rounded-lg p-3" style={{backgroundColor:'#dcfce7',border:'1px solid #86efac'}}>
              <div style={{fontSize:'13px',fontWeight:600,color:'#15803d'}}>
                {member.full_name} can now sign in to the member portal.
              </div>
              <div style={{fontSize:'12px',color:'#15803d',marginTop:'2px'}}>
                Share this password — it will not be shown again.
              </div>
            </div>
            <div className="rounded-lg p-3 flex items-center justify-between gap-3" style={{backgroundColor:'#f8f9fc',border:'1px solid var(--color-surface-border)'}}>
              <code style={{fontFamily:'monospace',fontSize:'16px',fontWeight:600,color:'var(--color-navy)',wordBreak:'break-all'}}>{tempPassword}</code>
              <button onClick={copy} className="px-3 py-1.5 rounded text-xs font-semibold whitespace-nowrap"
                      style={{backgroundColor: copied ? '#15803d' : 'var(--color-navy)', color:'white'}}>
                {copied ? 'Copied ✓' : 'Copy'}
              </button>
            </div>
            <p style={{fontSize:'12px',color:'#747780'}}>
              They will be required to change this password on first login.
            </p>
            <div className="flex justify-end">
              <button onClick={onSuccess} className="btn-primary px-6 py-2">Done</button>
            </div>
          </div>
        ) : (
          <div className="space-y-4">
            {serverErr && (
              <div className="rounded p-2" style={{backgroundColor:'#fee2e2',border:'1px solid #fecaca',fontSize:'12px',color:'#b91c1c'}}>
                {serverErr}
              </div>
            )}
            <p style={{fontSize:'13px',color:'#44474f'}}>
              Create a member-portal login for {member.full_name}. They will be assigned the 'member' role
              and can view their giving, attendance, and profile.
            </p>
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Login Email *</label>
              <input type="email" className="input-field" value={email} onChange={e => setEmail(e.target.value)}/>
              {errors.email && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.email[0]}</p>}
            </div>
            <div>
              <label className="block mb-1.5" style={{fontSize:'13px',fontWeight:600,color:'var(--color-navy)'}}>Login Name (optional)</label>
              <input type="text" className="input-field" placeholder={member.full_name} value={nameOverride} onChange={e => setNameOverride(e.target.value)}/>
            </div>
            <div className="flex justify-end gap-3 pt-2">
              <button onClick={onClose} className="px-5 py-2 rounded-lg text-sm font-semibold"
                      style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>Cancel</button>
              <button onClick={submit} disabled={mode === 'submitting' || !email}
                      className="btn-primary px-6 py-2" style={{opacity: !email ? 0.5 : 1}}>
                {mode === 'submitting' ? 'Creating…' : 'Create Login'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
