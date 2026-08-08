import { createContext, useContext, useState, useCallback } from 'react'
import { login as loginApi, logout as logoutApi } from '../api/auth'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user,    setUser]    = useState(() => {
    try { return JSON.parse(localStorage.getItem('wis_user')) } catch { return null }
  })
  const [token,   setToken]   = useState(() => localStorage.getItem('wis_token'))
  const [loading, setLoading] = useState(false)
  const [error,   setError]   = useState(null)

  const login = async (email, password) => {
    setLoading(true)
    setError(null)
    try {
      const { data } = await loginApi({ email, password })
      localStorage.setItem('wis_token', data.token)
      localStorage.setItem('wis_user',  JSON.stringify(data.user))
      setToken(data.token)
      setUser(data.user)
      return data
    } catch (err) {
      setError(err.response?.data?.message || 'Login failed. Please try again.')
      throw err
    } finally {
      setLoading(false)
    }
  }

  const logout = async () => {
    try { await logoutApi() } catch { /* local session must still clear */ }
    localStorage.removeItem('wis_token')
    localStorage.removeItem('wis_user')
    setToken(null)
    setUser(null)
  }

  const updateUser = (newUser) => {
    localStorage.setItem('wis_user', JSON.stringify(newUser))
    setUser(newUser)
  }

  const hasRole       = useCallback((role)       => user?.roles?.includes(role), [user])
  const hasPermission = useCallback((permission) => user?.permissions?.includes(permission), [user])

  return (
    <AuthContext.Provider value={{
      user, token, loading, error,
      login, logout, updateUser, hasRole, hasPermission,
      isAuthenticated: !!token,
    }}>
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
