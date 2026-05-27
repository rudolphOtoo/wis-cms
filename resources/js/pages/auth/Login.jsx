import React, { useState, useEffect, useRef } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'

export default function Login() {
  const { login, loading, error, isAuthenticated, hasRole, user } = useAuth()
  const navigate = useNavigate()
  const [form, setForm]     = useState({ email: '', password: '' })
  const [showPass, setShowPass] = useState(false)
  const panelRef = useRef(null)

  useEffect(() => {
    if (isAuthenticated) {
      if (user?.must_change_password) navigate('/change-password', { replace: true })
      else navigate(hasRole('member') ? '/portal' : '/dashboard', { replace: true })
    }
  }, [isAuthenticated])

  useEffect(() => {
    const handler = (e) => {
      const mx = (e.clientX - window.innerWidth / 2) * 0.01
      const my = (e.clientY - window.innerHeight / 2) * 0.01
      panelRef.current?.querySelectorAll('[data-blur]').forEach((el, i) => {
        const s = (i + 1) * 0.5
        el.style.transform = `translate(${mx * s}px, ${my * s}px)`
      })
    }
    window.addEventListener('mousemove', handler)
    return () => window.removeEventListener('mousemove', handler)
  }, [])

  const handleSubmit = async (e) => {
    e.preventDefault()
    try {
      const data = await login(form.email, form.password)
      if (data?.user?.must_change_password) {
        navigate('/change-password', { replace: true })
      } else {
        navigate(data?.user?.roles?.includes('member') ? '/portal' : '/dashboard', { replace: true })
      }
    } catch (_) {}
  }

  return (
    <main className="min-h-screen flex flex-col md:flex-row"
          style={{backgroundColor:'var(--color-surface)'}}>
      <style>{`
        @keyframes loginFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .login-fade  { animation: loginFadeIn 0.8s ease-out forwards; }
        .login-fade2 { animation: loginFadeIn 0.8s ease-out 0.2s forwards; opacity: 0; }
      `}</style>

      {/* LEFT — Brand canvas */}
      <section ref={panelRef}
               className="md:w-1/2 flex flex-col justify-between relative overflow-hidden"
               style={{
                 background:'linear-gradient(135deg, #0d1f3c 0%, #1b3a6b 100%)',
                 minHeight:'400px',
                 padding:'40px',
               }}>
        {/* Blurred decorative circles */}
        <div data-blur className="absolute rounded-full"
             style={{width:'300px',height:'300px',background:'rgba(201,168,76,0.10)',filter:'blur(80px)',top:'-50px',left:'-50px',transition:'transform 0.2s ease-out'}} />
        <div data-blur className="absolute rounded-full"
             style={{width:'400px',height:'400px',background:'rgba(27,58,107,0.40)',filter:'blur(80px)',bottom:'-100px',right:'-50px',transition:'transform 0.2s ease-out'}} />
        <div data-blur className="absolute rounded-full"
             style={{width:'250px',height:'250px',background:'rgba(201,168,76,0.05)',filter:'blur(80px)',top:'40%',right:'10%',transition:'transform 0.2s ease-out'}} />

        {/* Centered brand block */}
        <div className="relative z-10 flex-grow flex flex-col items-center justify-center text-center login-fade">
          {/* Logo tile */}
          <div className="mb-2 flex items-center justify-center">
            <img src="/images/logo.png" alt="Methodist Church Ghana Logo" className="w-24 h-24 object-contain" />
          </div>

          <h1 className="font-bold mb-1"
              style={{fontFamily:'var(--font-display)',fontSize:'48px',lineHeight:'60px',letterSpacing:'-0.02em',color:'var(--color-gold-light)'}}>
            WIS-CMS
          </h1>
          <p className="italic"
             style={{fontFamily:'var(--font-display)',fontSize:'24px',lineHeight:'32px',color:'rgba(255,255,255,0.8)'}}>
            Serving the body of Christ
          </p>
          <div className="mt-10 rounded-full"
               style={{width:'64px',height:'4px',backgroundColor:'rgba(201,168,76,0.40)'}} />
        </div>

        {/* Footer */}
        <footer className="relative z-10 text-center md:text-left mt-auto">
          <p style={{fontSize:'14px',letterSpacing:'0.05em',color:'rgba(255,255,255,0.6)'}}>
            Methodist Church Ghana — Wesleyan International Society
          </p>
        </footer>
      </section>

      {/* RIGHT — Login form */}
      <section className="md:w-1/2 flex items-center justify-center"
               style={{backgroundColor:'var(--color-surface)',padding:'24px'}}>
        <div className="w-full login-fade2" style={{maxWidth:'440px'}}>
          <div className="mb-10">
            <h2 className="font-bold mb-1"
                style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
              Welcome back
            </h2>
            <p style={{fontSize:'18px',lineHeight:'28px',color:'rgba(68,71,79,0.8)'}}>
              Sign in to your account to continue
            </p>
          </div>

          {error && (
            <div className="flex items-center gap-2 rounded-lg mb-6"
                 style={{backgroundColor:'#ffdad6',color:'#93000a',padding:'12px',border:'1px solid rgba(186,26,26,0.2)'}}>
              <svg className="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd"/>
              </svg>
              <span style={{fontSize:'14px',fontWeight:600}}>{error}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-6">
            {/* Email */}
            <div className="space-y-1">
              <label className="block" htmlFor="email"
                     style={{fontSize:'14px',fontWeight:600,color:'#44474f'}}>
                Email Address
              </label>
              <input id="email" type="email" className="input-field"
                     placeholder="admin@wis-cms.local"
                     value={form.email} onChange={e => setForm({...form, email: e.target.value})}
                     required autoFocus />
            </div>

            {/* Password */}
            <div className="space-y-1">
              <label className="block" htmlFor="password"
                     style={{fontSize:'14px',fontWeight:600,color:'#44474f'}}>
                Password
              </label>
              <div className="relative">
                <input id="password" type={showPass ? 'text' : 'password'} className="input-field"
                       style={{paddingRight:'3rem'}}
                       placeholder="••••••••"
                       value={form.password} onChange={e => setForm({...form, password: e.target.value})}
                       required />
                <button type="button" onClick={() => setShowPass(!showPass)}
                        className="absolute right-3 top-1/2 -translate-y-1/2 transition-colors"
                        style={{color:'#747780'}}>
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    {showPass
                      ? <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 4.411m0 0L21 21" />
                      : <><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></>
                    }
                  </svg>
                </button>
              </div>
            </div>

            {/* Remember + Forgot */}
            <div className="flex items-center justify-between pt-1">
              <label className="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" className="w-4 h-4 rounded"
                       style={{accentColor:'var(--color-navy)'}} />
                <span style={{fontSize:'14px',color:'#44474f'}}>Remember device</span>
              </label>
              <Link to="/forgot-password"
                    style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>
                Forgot password?
              </Link>
            </div>

            {/* Sign in */}
            <button type="submit" disabled={loading}
                    className="btn-primary w-full"
                    style={{padding:'12px 24px',fontSize:'14px',marginTop:'8px',transition:'transform 0.1s ease, background-color 0.15s ease'}}
                    onMouseDown={e => e.currentTarget.style.transform = 'scale(0.98)'}
                    onMouseUp={e => e.currentTarget.style.transform = 'scale(1.01)'}
                    onMouseLeave={e => e.currentTarget.style.transform = 'scale(1)'}>
              {loading
                ? <><svg className="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>Signing in...</>
                : 'Sign in'
              }
            </button>
          </form>

          {/* Support line */}
          <div className="mt-16 text-center">
            <p style={{fontSize:'12px',letterSpacing:'0.03em',color:'rgba(68,71,79,0.6)'}}>
              Need assistance? Contact your system administrator
            </p>
          </div>
        </div>
      </section>
    </main>
  )
}
