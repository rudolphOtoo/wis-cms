import { useState } from 'react'
import { Link } from 'react-router-dom'
import { forgotPassword } from '../../api/auth'

export default function ForgotPassword() {
  const [email,   setEmail]   = useState('')
  const [sent,    setSent]    = useState(false)
  const [loading, setLoading] = useState(false)
  const [error,   setError]   = useState(null)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError(null)
    try {
      await forgotPassword({ email })
      setSent(true)
    } catch (err) {
      setError(err.response?.data?.message ?? 'Something went wrong. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="min-h-screen flex items-center justify-center px-4" style={{backgroundColor:'var(--color-surface)'}}>
      <div className="w-full max-w-md">
        <div className="text-center mb-6">
          <div className="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center" style={{backgroundColor:'var(--color-navy)'}}>
            <svg className="w-7 h-7" style={{color:'var(--color-gold)'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
          </div>
          <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'28px',color:'var(--color-navy)'}}>Forgot Password</h1>
          <p className="text-sm mt-1" style={{color:'#6b7280'}}>Enter your email and we'll send a reset link.</p>
        </div>

        <div style={{backgroundColor:'#fff',border:'1px solid var(--color-surface-border)',borderRadius:'16px',boxShadow:'0 4px 12px rgba(13,31,60,0.05)',padding:'24px'}}>
          {sent ? (
            <div className="text-center space-y-4">
              <div className="w-12 h-12 rounded-full mx-auto flex items-center justify-center" style={{backgroundColor:'#dcfce7'}}>
                <svg className="w-6 h-6" style={{color:'#15803d'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7"/>
                </svg>
              </div>
              <p className="text-sm" style={{color:'#374151'}}>
                If that email is registered, a password reset link has been sent. Please check your inbox.
              </p>
              <div className="rounded-lg p-3" style={{backgroundColor:'#fffbeb',border:'1px solid #fde68a'}}>
                <p className="text-xs" style={{color:'#92400e'}}>
                  <strong>Dev note:</strong> email is in log mode — the reset link is written to <code>storage/logs/laravel.log</code> until SMTP credentials are configured.
                </p>
              </div>
              <Link to="/login" className="block text-sm font-semibold" style={{color:'var(--color-navy)'}}>← Back to sign in</Link>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Email Address</label>
                <input type="email" className="input-field" value={email}
                       onChange={e => setEmail(e.target.value)} required autoFocus
                       placeholder="you@example.com"/>
                {error && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{error}</p>}
              </div>
              <button type="submit" disabled={loading} className="btn-primary w-full" style={{padding:'12px'}}>
                {loading ? 'Sending...' : 'Send Reset Link'}
              </button>
              <Link to="/login" className="block text-center text-sm font-semibold" style={{color:'var(--color-navy)'}}>← Back to sign in</Link>
            </form>
          )}
        </div>
      </div>
    </main>
  )
}
