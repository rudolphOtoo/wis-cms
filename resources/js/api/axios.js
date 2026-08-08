import axios from 'axios'
import { toast } from 'sonner'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('wis_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('wis_token')
      localStorage.removeItem('wis_user')
      const onAuthPage = ['/login', '/forgot-password', '/reset-password']
        .includes(window.location.pathname)
      // Avoid a reload loop when an unauthenticated request fires from an
      // auth page (e.g. bootstrap during the login screen) — just reject and
      // let the page render.
      if (!onAuthPage) {
        window.location.href = '/login'
      }
      return Promise.reject(error)
    }

    if (error.response?.status === 423 && window.location.pathname !== '/change-password') {
      window.location.href = '/change-password'
      return Promise.reject(error)
    }

    const status = error.response?.status
    const data   = error.response?.data

    if (!error.response) {
      toast.error('Network error. Check your connection.')
    } else if (status === 403) {
      toast.error(data?.message ?? 'You do not have permission to perform this action.')
    } else if (status === 422 && data?.errors) {
      const firstField = Object.values(data.errors)[0]
      if (firstField) toast.error(firstField[0])
    } else if (status === 500) {
      toast.error('Something went wrong. Please try again.')
    }

    return Promise.reject(error)
  }
)

export default api
