import { Component, createRef } from 'react'
import { AlertTriangle, RefreshCw } from 'lucide-react'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../constants/styles'
const styles = {
  container: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: '50vh',
    padding: '48px 24px',
    textAlign: 'center',
  },
  icon: {
    width: 56,
    height: 56,
    color: '#ba1a1a',
    marginBottom: 20,
  },
  title: {
    fontFamily: FONT_DISPLAY,
    fontSize: 22,
    fontWeight: 700,
    color: NAVY,
    marginBottom: 8,
  },
  message: {
    fontSize: 14,
    color: '#6b7280',
    maxWidth: 400,
    lineHeight: 1.6,
    marginBottom: 4,
  },
  detail: {
    fontSize: 12,
    color: PLACEHOLDER,
    maxWidth: 500,
    fontFamily: 'monospace',
    marginBottom: 24,
    padding: '8px 12px',
    backgroundColor: '#f3f4f6',
    borderRadius: 8,
    wordBreak: 'break-word',
  },
}

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error }
  }

  componentDidCatch(error, errorInfo) {
    console.error('[ErrorBoundary] Caught error:', error, errorInfo)
  }

  resetRef = createRef()

  handleReset = () => {
    this.setState({ hasError: false, error: null })
  }

  componentDidUpdate(_prevProps, prevState) {
    if (!prevState.hasError && this.state.hasError) {
      this.resetRef.current?.focus()
    }
  }

  render() {
    if (this.state.hasError) {
      const errMsg = this.state.error?.message ?? 'An unexpected error occurred.'

      return (
        <div role="alert" style={styles.container}>
          <AlertTriangle style={styles.icon} strokeWidth={1.5} />
          <h2 style={styles.title}>Something went wrong</h2>
          <p style={styles.message}>
            A page error was caught. Please try again, or contact support if
            the problem persists.
          </p>
          <p style={styles.detail}>{errMsg}</p>
          <button
            type="button"
            ref={this.resetRef}
            onClick={this.handleReset}
            className="btn-primary inline-flex items-center gap-2 px-5 py-2.5"
          >
            <RefreshCw size={16} strokeWidth={2} />
            Try Again
          </button>
        </div>
      )
    }

    return this.props.children
  }
}
