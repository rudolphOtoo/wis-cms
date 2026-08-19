import { useState, useCallback } from 'react'
import { useAuth } from '../../context/AuthContext'
import { initializePayment, verifyPayment } from '../../api/payments'

const TYPES = [
  { value: 'tithe', label: 'Tithe' },
  { value: 'offering', label: 'Offering' },
  { value: 'welfare', label: 'Welfare' },
  { value: 'building_fund', label: 'Building Fund' },
  { value: 'special_seed', label: 'Special Seed' },
  { value: 'other', label: 'Other' },
]

const NETS = [
  { value: 'mtn', label: 'MTN MoMo', color: '#ffcc00', tc: '#000' },
  { value: 'telecel', label: 'Telecel', color: '#e30613', tc: '#fff' },
  { value: 'at', label: 'AT Money', color: '#00a0e3', tc: '#fff' },
]

const AMTS = [10, 20, 50, 100, 200, 500]

export default function GivePage() {
  const { user } = useAuth()
  const [step, setStep] = useState(1)
  const [form, setForm] = useState({ payment_type: '', amount: '', momo_network: '', momo_number: '', notes: '' })
  const [errors, setErrors] = useState({})
  const [loading, setLoading] = useState(false)
  const [polling, setPolling] = useState(false)
  const [result, setResult] = useState(null)
  const [displayText, setDisplayText] = useState('')

  const set = (f) => (e) => { setForm(v => ({ ...v, [f]: e.target.value })); setErrors(v => ({ ...v, [f]: null })) }

  const canNext = step === 1 ? !!form.payment_type : step === 2 ? form.amount > 0 : step === 3 ? form.momo_network && form.momo_number.length >= 9 : false

  const startPolling = useCallback((ref) => {
    let n = 0
    const poll = async () => {
      if (++n > 36) { setPolling(false); setResult({ status: 'timeout' }); return }
      try {
        const res = await verifyPayment(ref)
        const p = res.data.data
        if (p.status === 'success') { setPolling(false); setResult({ status: 'success', reference: p.reference, amount: p.amount }) }
        else if (p.status === 'failed') { setPolling(false); setResult({ status: 'failed' }) }
        else setTimeout(poll, 5000)
      } catch { setTimeout(poll, 5000) }
    }
    setTimeout(poll, 5000)
  }, [])

  const submit = async () => {
    setLoading(true); setErrors({})
    try {
      const res = await initializePayment({
        payment_type: form.payment_type, amount: Number(form.amount),
        channel: 'momo', momo_network: form.momo_network, momo_number: form.momo_number,
        email: user?.email, member_id: user?.member?.id, notes: form.notes || undefined,
      })
      setDisplayText(res.data.data.display_text)
      setStep(4); setPolling(true); startPolling(res.data.data.reference)
    } catch (err) {
      if (err.response?.status === 422) setErrors(err.response.data.errors ?? {})
      else setErrors({ _form: err.response?.data?.message || 'Something went wrong.' })
    } finally { setLoading(false) }
  }

  const reset = () => { setStep(1); setForm({ payment_type: '', amount: '', momo_network: '', momo_number: '', notes: '' }); setErrors({}); setResult(null); setPolling(false); setDisplayText('') }

  return (
    <div className="min-h-screen flex items-center justify-center p-4" style={{ backgroundColor: '#f8f9fc' }}>
      <div className="w-full max-w-lg">
        <div className="text-center mb-8">
          <h1 className="text-2xl font-bold" style={{ fontFamily: 'var(--font-display)', color: 'var(--color-navy)' }}>Give Online</h1>
          <p className="text-sm mt-1" style={{ color: '#6b7280' }}>Secure Mobile Money giving</p>
        </div>

        {!result && (
          <div className="flex items-center justify-center gap-2 mb-6">
            {[1, 2, 3].map(s => (
              <div key={s} className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                     style={{ backgroundColor: step >= s ? 'var(--color-navy)' : '#e5e7eb', color: step >= s ? 'white' : '#9ca3af' }}>
                  {step > s ? '✓' : s}
                </div>
                {s < 3 && <div className="w-8 h-0.5" style={{ backgroundColor: step > s ? 'var(--color-navy)' : '#e5e7eb' }} />}
              </div>
            ))}
          </div>
        )}

        <div className="rounded-2xl p-6 md:p-8" style={{ backgroundColor: 'white', boxShadow: '0 4px 24px rgba(0,0,0,0.06)' }}>

          {/* Result */}
          {result && (
            <div className="text-center space-y-4">
              {result.status === 'success' ? (
                <>
                  <div className="w-16 h-16 rounded-full flex items-center justify-center mx-auto" style={{ backgroundColor: '#dcfce7' }}>
                    <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="#15803d" strokeWidth={2.5}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                  </div>
                  <h2 className="text-xl font-bold" style={{ color: '#15803d' }}>Thank You!</h2>
                  <p className="text-sm" style={{ color: '#6b7280' }}>Your giving has been received successfully.</p>
                  <div className="p-4 rounded-xl" style={{ backgroundColor: '#f0fdf4', border: '1px solid #bbf7d0' }}>
                    <div className="text-xs uppercase tracking-wider mb-1" style={{ color: '#15803d' }}>Reference</div>
                    <div className="font-mono font-bold" style={{ color: '#15803d' }}>{result.reference}</div>
                    <div className="text-sm font-semibold mt-2" style={{ color: '#374151' }}>
                      GHS {Number(result.amount).toFixed(2)}
                    </div>
                  </div>
                  <button onClick={reset} className="btn-primary px-8 py-2.5 text-sm">Give Again</button>
                </>
              ) : (
                <>
                  <div className="w-16 h-16 rounded-full flex items-center justify-center mx-auto" style={{ backgroundColor: '#fee2e2' }}>
                    <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="#dc2626" strokeWidth={2.5}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </div>
                  <h2 className="text-xl font-bold" style={{ color: '#dc2626' }}>{result.status === 'timeout' ? 'Timed Out' : 'Payment Failed'}</h2>
                  <p className="text-sm" style={{ color: '#6b7280' }}>{result.status === 'timeout' ? 'The 3-minute window has expired.' : 'The payment was not completed.'}</p>
                  <button onClick={reset} className="btn-primary px-8 py-2.5 text-sm">Try Again</button>
                </>
              )}
            </div>
          )}

          {/* Polling */}
          {polling && !result && (
            <div className="text-center space-y-4">
              <div className="w-16 h-16 rounded-full flex items-center justify-center mx-auto" style={{ backgroundColor: '#fef9c3' }}>
                <svg className="animate-spin w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="#a16207" strokeWidth={2}>
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
              </div>
              <h2 className="text-lg font-bold" style={{ color: '#a16207' }}>Approve on Your Phone</h2>
              <p className="text-sm" style={{ color: '#6b7280' }}>{displayText || 'Check your phone for the payment prompt and enter your PIN.'}</p>
              <div className="text-xs" style={{ color: '#9ca3af' }}>Auto-checking every 5 seconds...</div>
            </div>
          )}

          {/* Steps */}
          {!polling && !result && (
            <>
              {/* Step 1: Type */}
              {step === 1 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-sm" style={{ color: 'var(--color-navy)' }}>What are you giving?</h3>
                  <div className="grid grid-cols-2 gap-3">
                    {TYPES.map(t => (
                      <button key={t.value} type="button" onClick={() => { setForm(f => ({ ...f, payment_type: t.value })); setStep(2) }}
                              className="p-4 rounded-xl text-left transition-all"
                              style={{ backgroundColor: form.payment_type === t.value ? '#dcfce7' : 'white', border: form.payment_type === t.value ? '2px solid #15803d' : '2px solid var(--color-surface-border)' }}>
                        <div className="font-bold text-sm" style={{ color: '#374151' }}>{t.label}</div>
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Step 2: Amount */}
              {step === 2 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-sm" style={{ color: 'var(--color-navy)' }}>How much?</h3>
                  <div className="grid grid-cols-3 gap-2">
                    {AMTS.map(a => (
                      <button key={a} type="button" onClick={() => setForm(f => ({ ...f, amount: String(a) }))}
                              className="py-3 rounded-xl text-sm font-semibold transition-all"
                              style={{ backgroundColor: form.amount === String(a) ? 'var(--color-navy)' : 'white', color: form.amount === String(a) ? 'white' : '#374151', border: form.amount === String(a) ? '2px solid var(--color-navy)' : '2px solid var(--color-surface-border)' }}>
                        GHS {a}
                      </button>
                    ))}
                  </div>
                  <div>
                    <input type="number" step="0.01" min="1" className="input-field text-center text-lg font-bold"
                           value={form.amount} onChange={set('amount')} placeholder="Custom amount" required />
                    {errors.amount && <p className="text-xs mt-1" style={{ color: '#dc2626' }}>{errors.amount[0]}</p>}
                  </div>
                  <button type="button" onClick={() => setStep(3)} disabled={!canNext}
                          className="w-full py-2.5 text-sm rounded-lg transition-colors" style={{ color: '#6b7280' }}>Back</button>
                </div>
              )}

              {/* Step 3: MoMo details */}
              {step === 3 && (
                <div className="space-y-4">
                  <h3 className="font-semibold text-sm" style={{ color: 'var(--color-navy)' }}>Mobile Money Details</h3>
                  <div>
                    <label className="block text-xs font-semibold mb-2 uppercase tracking-wider" style={{ color: '#6b7280' }}>Network *</label>
                    <div className="grid grid-cols-3 gap-2">
                      {NETS.map(n => (
                        <button key={n.value} type="button" onClick={() => setForm(f => ({ ...f, momo_network: n.value }))}
                                className="py-3 rounded-xl text-xs font-bold transition-all"
                                style={{ backgroundColor: form.momo_network === n.value ? n.color : 'white', color: form.momo_network === n.value ? n.tc : '#374151', border: form.momo_network === n.value ? `2px solid ${n.color}` : '2px solid var(--color-surface-border)' }}>
                          {n.label}
                        </button>
                      ))}
                    </div>
                    {errors.momo_network && <p className="text-xs mt-1" style={{ color: '#dc2626' }}>{errors.momo_network[0]}</p>}
                  </div>
                  <div>
                    <label className="block text-xs font-semibold mb-1.5 uppercase tracking-wider" style={{ color: '#6b7280' }}>Phone Number *</label>
                    <input type="tel" className="input-field" value={form.momo_number} onChange={set('momo_number')}
                           placeholder="0XX XXX XXXX" maxLength={20} required />
                    {errors.momo_number && <p className="text-xs mt-1" style={{ color: '#dc2626' }}>{errors.momo_number[0]}</p>}
                  </div>
                  <div>
                    <label className="block text-xs font-semibold mb-1.5 uppercase tracking-wider" style={{ color: '#6b7280' }}>Notes (optional)</label>
                    <input type="text" className="input-field" value={form.notes} onChange={set('notes')} placeholder="e.g. Sunday tithe" maxLength={500} />
                  </div>
                  {errors._form && <p className="text-xs text-center p-2 rounded-lg" style={{ color: '#dc2626', backgroundColor: '#fee2e2' }}>{errors._form}</p>}
                  <div className="flex gap-3">
                    <button type="button" onClick={() => setStep(2)} className="flex-1 py-2.5 text-sm rounded-lg"
                            style={{ border: '1px solid var(--color-surface-border)', color: '#374151' }}>Back</button>
                    <button type="button" onClick={submit} disabled={!canNext || loading}
                            className="flex-1 btn-primary py-2.5 text-sm">
                      {loading ? 'Processing...' : `Pay GHS ${form.amount || '0'}`}
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  )
}
