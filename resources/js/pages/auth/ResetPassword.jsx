import React, { useState, useEffect } from 'react'
import { useNavigate, useSearchParams, Link } from 'react-router-dom'
import { resetPassword } from '../../api/auth'

export default function ResetPassword() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const emailFromUrl = params.get('email') ?? ''

  const [form, setForm] = useState({
    email: emailFromUrl,
    password: '',
    password_confirmation: '',
  })
  const [errors,  setErrors]  = useState({})
  const [loading, setLoading] = useState(false)
  const [done,    setDone]    = useState(false)

  useEffect(() => {
    setForm(f => ({ ...f, email: emailFromUrl }))
  }, [emailFromUrl])

  const set = (field) => (e) => {
    setForm(f => ({ ...f, [field]: e.target.value }))
    setErrors(er => ({ ...er, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (form.password !== form.password_confirmation) {
      setErrors({ password_confirmation: ['Passwords do not match.'] })
      return
    }
    setLoading(true)
    setErrors({})
    try {
      await resetPassword({ ...form, token })
      setDone(true)
      setTimeout(() => navigate('/login', { replace: true }), 2000)
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
      } else {
        alert('Could not reset password. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  const invalidLink = !token || !emailFromUrl

  return (
    <main className="min-h-screen flex items-center justify-center px-4" style={{backgroundColor:'var(--color-surface)'}}>
      <div className="w-full max-w-md">
        <div className="text-center mb-6">
          <div className="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center" style={{backgroundColor:'var(--color-navy)'}}>
            <svg className="w-7 h-7" style={{color:'var(--color-gold)'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
          </div>
          <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'28px',color:'var(--color-navy)'}}>Reset Password</h1>
          <p className="text-sm mt-1" style={{color:'#6b7280'}}>Choose a new password for your account.</p>
        </div>

        <div style={{backgroundColor:'#fff',border:'1px solid var(--color-surface-border)',borderRadius:'16px',boxShadow:'0 4px 12px rgba(13,31,60,0.05)',padding:'24px'}}>
          {invalidLink ? (
            <div className="text-center space-y-4">
              <p className="text-sm" style={{color:'#dc2626'}}>This reset link is missing its token or email. Please request a new one.</p>
              <Link to="/forgot-password" className="block text-sm font-semibold" style={{color:'var(--color-navy)'}}>Request a new link</Link>
            </div>
          ) : done ? (
            <div className="text-center space-y-3">
              <div className="w-12 h-12 rounded-full mx-auto flex items-center justify-center" style={{backgroundColor:'#dcfce7'}}>
                <svg className="w-6 h-6" style={{color:'#15803d'}} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7"/></svg>
              </div>
              <p className="text-sm" style={{color:'#374151'}}>Your password has been reset. Redirecting to sign in...</p>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Email</label>
                <input type="email" className="input-field" value={form.email} onChange={set('email')} required/>
                {errors.email && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.email[0]}</p>}
              </div>
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>New Password</label>
                <input type="password" className="input-field" value={form.password} onChange={set('password')}
                       required minLength={8} placeholder="Minimum 8 characters" autoFocus/>
                {errors.password && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.password[0]}</p>}
              </div>
              <div>
                <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Confirm New Password</label>
                <input type="password" className="input-field" value={form.password_confirmation} onChange={set('password_confirmation')} required minLength={8}/>
                {errors.password_confirmation && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.password_confirmation[0]}</p>}
              </div>
              <button type="submit" disabled={loading} className="btn-primary w-full" style={{padding:'12px'}}>
                {loading ? 'Resetting...' : 'Reset Password'}
              </button>
            </form>
          )}
        </div>
      </div>
    </main>
  )
}
