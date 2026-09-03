import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import IdleWarningModal from '../../resources/js/components/IdleWarningModal'

describe('IdleWarningModal', () => {
  const defaultProps = {
    open: true,
    remainingSeconds: 60,
    onStayLoggedIn: vi.fn(),
    onLogoutNow: vi.fn(),
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders warning title and message when open', () => {
    render(<IdleWarningModal {...defaultProps} />)

    expect(screen.getByText('Session Expiring Soon')).toBeInTheDocument()
    expect(screen.getByText(/session is about to expire due to inactivity/)).toBeInTheDocument()
  })

  it('displays remaining seconds in countdown text', () => {
    render(<IdleWarningModal {...defaultProps} remainingSeconds={45} />)

    expect(screen.getByText(/Logging out in 45 seconds/)).toBeInTheDocument()
  })

  it('shows singular "second" when remainingSeconds is 1', () => {
    render(<IdleWarningModal {...defaultProps} remainingSeconds={1} />)

    expect(screen.getByText(/Logging out in 1 second/)).toBeInTheDocument()
  })

  it('calls onStayLoggedIn when Stay Logged In button is clicked', () => {
    const onStayLoggedIn = vi.fn()
    render(<IdleWarningModal {...defaultProps} onStayLoggedIn={onStayLoggedIn} />)

    fireEvent.click(screen.getByText('Stay Logged In'))
    expect(onStayLoggedIn).toHaveBeenCalledTimes(1)
  })

  it('calls onLogoutNow when Log Out Now button is clicked', () => {
    const onLogoutNow = vi.fn()
    render(<IdleWarningModal {...defaultProps} onLogoutNow={onLogoutNow} />)

    fireEvent.click(screen.getByText('Log Out Now'))
    expect(onLogoutNow).toHaveBeenCalledTimes(1)
  })

  it('calls onLogoutNow when close X button is clicked', () => {
    const onLogoutNow = vi.fn()
    render(<IdleWarningModal {...defaultProps} onLogoutNow={onLogoutNow} />)

    fireEvent.click(screen.getByLabelText('Close'))
    expect(onLogoutNow).toHaveBeenCalledTimes(1)
  })

  it('does not render when open is false', () => {
    render(<IdleWarningModal {...defaultProps} open={false} />)

    expect(screen.queryByText('Session Expiring Soon')).not.toBeInTheDocument()
    expect(screen.queryByText('Stay Logged In')).not.toBeInTheDocument()
  })
})
