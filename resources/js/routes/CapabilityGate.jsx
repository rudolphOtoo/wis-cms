import { useState, useEffect } from 'react'
import { Navigate, Outlet } from 'react-router-dom'
import { loadDiocese, capability } from '../diocese/registry'

/**
 * Route-level capability gate. When the active diocese profile has
 * `path` set to false, the route is unreachable — the user is redirected
 * instead of rendering the (disabled) page. Permissions are still enforced
 * separately by the page/API layer; this only hides disabled modules.
 *
 * While the profile loads we render nothing (a one-time, sub-100ms gap for
 * most installs since the bootstrap is warmed at app startup).
 */
export default function CapabilityGate({ path, redirectTo = '/dashboard', children }) {
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    loadDiocese().finally(() => setLoaded(true))
  }, [])

  if (!loaded) return null

  if (capability(path, true) === false) {
    return <Navigate to={redirectTo} replace />
  }

  return children ?? <Outlet />
}
