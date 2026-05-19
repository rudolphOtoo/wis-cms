import { useAuth } from '../context/AuthContext'

/**
 * Permission helper hook
 * Returns helpers for checking what the current user can do
 */
export function usePermission() {
  const { user, hasPermission, hasRole } = useAuth()

  return {
    user,
    can:    (permission) => hasPermission(permission),
    cannot: (permission) => !hasPermission(permission),
    is:     (role)       => hasRole(role),
    isAny:  (...roles)   => roles.some(r => hasRole(r)),
    // Convenience shortcuts
    isSuperAdmin: hasRole('super_admin'),
  }
}
