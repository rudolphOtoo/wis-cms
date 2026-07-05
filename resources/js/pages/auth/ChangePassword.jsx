import { useState } from 'react'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'
import { changePassword, getMe } from '../../api/auth'
import { useAuth } from '../../context/AuthContext'

export default function ChangePassword() {
  const navigate = useNavigate()
  const { user, updateUser, hasRole } = useAuth()
  const forced = Boolean(user?.must_change_password)

  const [form, setForm] = useState({ current_password: '', new_password: '', new_password_confirmation: '' })
  const [errors,  setErrors]  = useState({})
  const [loading, setLoading] = useState(false)

  const set = (field) => (e) => {
    setForm(f => ({ ...f, [field]: e.target.value }))
    setErrors(er => ({ ...er, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (form.new_password !== form.new_password_confirmation) {
      setErrors({ new_password_confirmation: ['Passwords do not match.'] })
      return
    }
    setLoading(true)
    setErrors({})
    try {
      await changePassword(form)
      // Refresh the user so the cleared flag propagates (avoids guard loop)
      const me = await getMe()
      updateUser(me.data.user)
      navigate(me.data.user.roles?.includes('member') ? '/portal' : '/dashboard', { replace: true })
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
      } else {
        toast.error('Could not change password. Please try again.')
      }
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
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
          </div>
          <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'28px',color:'var(--color-navy)'}}>
            {forced ? 'Set a New Password' : 'Change Password'}
          </h1>
          <p className="text-sm mt-1" style={{color:'#6b7280'}}>
            {forced
              ? 'For your security, please choose a new password before continuing.'
              : 'Update the password you use to sign in.'}
          </p>
        </div>

        <div style={{backgroundColor:'#fff',border:'1px solid var(--color-surface-border)',borderRadius:'16px',boxShadow:'0 4px 12px rgba(13,31,60,0.05)',padding:'24px'}}>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                {forced ? 'Current (temporary) Password *' : 'Current Password *'}
              </label>
              <input type="password" className="input-field" value={form.current_password}
                     onChange={set('current_password')} required autoFocus/>
              {errors.current_password && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.current_password[0]}</p>}
            </div>
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>New Password *</label>
              <input type="password" className="input-field" value={form.new_password}
                     onChange={set('new_password')} required minLength={8} placeholder="Minimum 8 characters"/>
              {errors.new_password && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.new_password[0]}</p>}
            </div>
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Confirm New Password *</label>
              <input type="password" className="input-field" value={form.new_password_confirmation}
                     onChange={set('new_password_confirmation')} required minLength={8}/>
              {errors.new_password_confirmation && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.new_password_confirmation[0]}</p>}
            </div>

            <button type="submit" disabled={loading} className="btn-primary w-full" style={{padding:'12px'}}>
              {loading ? 'Saving...' : 'Update Password'}
            </button>

            {!forced && (
              <button type="button" onClick={() => navigate(-1)}
                      className="w-full py-2 rounded-lg text-sm font-semibold"
                      style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
                Cancel
              </button>
            )}
          </form>
        </div>
      </div>
    </main>
  )
}
