import React, { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'

export default function Login() {
  const { login, loading, error, isAuthenticated } = useAuth()
  const navigate = useNavigate()
  const [form, setForm]     = useState({ email: '', password: '' })
  const [showPass, setShowPass] = useState(false)

  useEffect(() => {
    if (isAuthenticated) navigate('/dashboard', { replace: true })
  }, [isAuthenticated])

  const handleSubmit = async (e) => {
    e.preventDefault()
    try {
      await login(form.email, form.password)
      navigate('/dashboard', { replace: true })
    } catch (_) {}
  }

  return (
    <div className="min-h-screen flex">
      {/* Left panel */}
      <div className="hidden lg:flex lg:w-1/2 flex-col justify-between p-12 relative overflow-hidden"
           style={{backgroundColor:'var(--color-navy-deeper)'}}>
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute -top-32 -right-32 w-96 h-96 rounded-full blur-3xl"
               style={{backgroundColor:'rgba(27,58,107,0.6)'}} />
          <div className="absolute -bottom-32 -left-32 w-96 h-96 rounded-full blur-3xl"
               style={{backgroundColor:'rgba(27,58,107,0.4)'}} />
        </div>
        <div className="relative z-10 flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl flex items-center justify-center"
               style={{backgroundColor:'var(--color-gold)'}}>
            <svg className="w-6 h-6" style={{color:'var(--color-navy-deeper)'}} fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.18L20 8.5v7L12 19.82 4 15.5v-7l8-4.32z"/>
            </svg>
          </div>
          <span className="text-white font-bold text-lg" style={{fontFamily:'var(--font-display)'}}>WIS-CMS</span>
        </div>
        <div className="relative z-10 space-y-6">
          <div className="w-16 h-1 rounded-full" style={{backgroundColor:'var(--color-gold)'}} />
          <h1 className="text-4xl font-bold text-white leading-tight" style={{fontFamily:'var(--font-display)'}}>
            Wesleyan<br />International<br />Society
          </h1>
          <p className="text-base leading-relaxed max-w-xs" style={{color:'rgba(255,255,255,0.6)'}}>
            The Methodist Church Ghana — Church Management System.
            Managing ministry with excellence.
          </p>
          <div className="grid grid-cols-3 gap-4 pt-4">
            {[{label:'Members',value:'800+'},{label:'Departments',value:'8'},{label:'Est.',value:'MCG'}].map(s => (
              <div key={s.label} className="rounded-xl p-4"
                   style={{backgroundColor:'rgba(255,255,255,0.05)',border:'1px solid rgba(255,255,255,0.1)'}}>
                <div className="font-bold text-xl" style={{fontFamily:'var(--font-display)',color:'var(--color-gold)'}}>{s.value}</div>
                <div className="text-xs mt-1" style={{color:'rgba(255,255,255,0.5)'}}>{s.label}</div>
              </div>
            ))}
          </div>
        </div>
        <div className="relative z-10">
          <p className="text-xs" style={{color:'rgba(255,255,255,0.3)'}}>
            © {new Date().getFullYear()} The Methodist Church Ghana. All rights reserved.
          </p>
        </div>
      </div>

      {/* Right panel */}
      <div className="flex-1 flex items-center justify-center p-8" style={{backgroundColor:'var(--color-surface)'}}>
        <div className="w-full max-w-md">
          <div className="flex items-center gap-3 mb-10 lg:hidden">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center"
                 style={{backgroundColor:'var(--color-navy)'}}>
              <svg className="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.18L20 8.5v7L12 19.82 4 15.5v-7l8-4.32z"/>
              </svg>
            </div>
            <span className="font-bold text-lg" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>WIS-CMS</span>
          </div>

          <div className="mb-8">
            <h2 className="text-3xl font-bold mb-2" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              Welcome back
            </h2>
            <p className="text-sm" style={{color:'#6b7280'}}>Sign in to your account to continue</p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-5">
            {error && (
              <div className="flex items-center gap-2 px-4 py-3 rounded-xl text-sm"
                   style={{backgroundColor:'#fef2f2',border:'1px solid #fecaca',color:'#dc2626'}}>
                <svg className="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd"/>
                </svg>
                {error}
              </div>
            )}

            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Email address
              </label>
              <input type="email" className="input-field" placeholder="you@example.com"
                     value={form.email} onChange={e => setForm({...form, email: e.target.value})}
                     required autoFocus />
            </div>

            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Password
              </label>
              <div className="relative">
                <input type={showPass ? 'text' : 'password'} className="input-field"
                       style={{paddingRight:'3rem'}}
                       placeholder="Enter your password"
                       value={form.password} onChange={e => setForm({...form, password: e.target.value})}
                       required />
                <button type="button" onClick={() => setShowPass(!showPass)}
                        className="absolute right-3 top-1/2 -translate-y-1/2 transition-colors"
                        style={{color:'#9ca3af'}}>
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    {showPass
                      ? <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 4.411m0 0L21 21" />
                      : <><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></>
                    }
                  </svg>
                </button>
              </div>
            </div>

            <button type="submit" disabled={loading} className="btn-primary w-full py-3 text-base mt-2">
              {loading
                ? <><svg className="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>Signing in...</>
                : 'Sign in'
              }
            </button>
          </form>

          <div className="mt-8 pt-6 text-center" style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <p className="text-xs" style={{color:'#9ca3af'}}>
              Methodist Church Ghana — Wesleyan International Society<br />
              For access issues, contact your system administrator.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
