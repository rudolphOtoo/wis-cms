import React from 'react'
import { useNavigate } from 'react-router-dom'
import { usePermission } from '../hooks/usePermission'

/**
 * Wrap a page in this component to require a permission.
 * Shows a friendly "no permission" screen if the user lacks access.
 */
export default function PermissionGate({ permission, children }) {
  const { can } = usePermission()
  const navigate = useNavigate()

  if (can(permission)) return children

  return (
    <div className="flex flex-col items-center justify-center py-24 text-center max-w-md mx-auto">
      <div className="w-20 h-20 rounded-full flex items-center justify-center mb-6"
           style={{backgroundColor:'rgba(220,38,38,0.1)'}}>
        <svg className="w-10 h-10" style={{color:'#dc2626'}}
             fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8}
                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
      </div>
      <h2 className="text-xl font-bold mb-2"
          style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
        Access Restricted
      </h2>
      <p className="text-sm mb-6" style={{color:'#6b7280'}}>
        You don't have permission to view this page. If you believe this is a mistake,
        please contact your system administrator.
      </p>
      <button onClick={() => navigate('/dashboard')} className="btn-primary px-6 py-2.5">
        Back to Dashboard
      </button>
    </div>
  )
}
