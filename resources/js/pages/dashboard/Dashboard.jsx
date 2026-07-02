import React, { lazy, Suspense, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  LineChart, Line, BarChart, Bar, XAxis, YAxis, Tooltip,
  ResponsiveContainer, CartesianGrid, Legend,
} from 'recharts'
import {
  Users, CalendarDays, Wallet, UserPlus, ClipboardList,
  TrendingUp, TrendingDown,
} from 'lucide-react'
import { useAuth } from '../../context/AuthContext'
import { getDashboard } from '../../api/dashboard'

import { DashboardSkeleton } from '../../components/ui/Skeletons'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
const LeaderDashboard = lazy(() => import('./LeaderDashboard'))

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const fmtShort = (n) => {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000)     return `${(n / 1_000).toFixed(1)}K`
  return n
}
const greeting = () => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
}

export default function Dashboard() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const controller = new AbortController()
    let mounted = true

    getDashboard(undefined, controller.signal)
      .then(res => { if (mounted) setData(res.data.data) })
      .catch(err => {
        if (err?.code === 'ERR_CANCELED') return
        if (!mounted) return
        if (err.response?.status === 403) {
          navigate('/portal', { replace: true })
        } else {
          console.error(err)
        }
      })
      .finally(() => { if (mounted) setLoading(false) })

    return () => { mounted = false; controller.abort() }
  }, [])

  if (loading) return <DashboardSkeleton />

  if (data.mode === 'department_leader') {
    return (
      <Suspense fallback={<DashboardSkeleton />}>
        <LeaderDashboard data={data} user={user} navigate={navigate} />
      </Suspense>
    )
  }

  const { hero, gender_split, counts, attendance_chart, finance_chart,
          top_categories, recent_members, recent_transactions } = data

  const totalGender = gender_split.male + gender_split.female
  const malePct   = totalGender > 0 ? Math.round((gender_split.male   / totalGender) * 100) : 0
  const femalePct = totalGender > 0 ? Math.round((gender_split.female / totalGender) * 100) : 0

  const stats = [
    { label: 'Active Members',         value: hero.total_members,   icon: Users },
    { label: 'Last Sunday Attendance', value: hero.last_attendance, icon: CalendarDays },
    { label: 'This Month Income',      value: hero.month_income,    icon: Wallet,   money: true, growth: hero.income_growth },
    { label: 'New Visitors This Month',value: hero.month_visitors,  icon: UserPlus },
  ]

  return (
    <div className="space-y-6" style={{ maxWidth: '1440px' }}>

      <section
        className="rounded-xl relative overflow-hidden p-6 md:p-10"
        style={{ background: 'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)' }}
      >
        <div className="relative z-10">
          <h2
            className="font-bold text-white text-2xl md:text-4xl leading-tight"
            style={{ fontFamily: FONT_DISPLAY }}
          >
            {greeting()}, {user?.name?.split(' ')[0]}
          </h2>
          <p className="mt-1 text-sm md:text-base" style={{ color: 'rgba(255,255,255,0.8)' }}>
            Here is what's happening at Wesleyan International today.
          </p>
        </div>
        <div
          className="absolute rounded-full pointer-events-none"
          style={{ right: '-10%', top: '-50%', width: '384px', height: '384px', background: 'rgba(255,255,255,0.05)', filter: 'blur(60px)' }}
        />
      </section>

      {/* Stat cards */}
      <section className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {stats.map(s => {
          const IconComp = s.icon
          return (
            <div key={s.label} className="surface-card p-6 flex flex-col justify-between" style={{ minHeight: '140px' }}>
              <div className="flex justify-between items-start">
                <p style={{ fontSize: '14px', fontWeight: 600, color: '#44474f' }}>{s.label}</p>
                <IconComp size={22} strokeWidth={1.8} style={{ color: NAVY }} aria-hidden="true" />
              </div>
              <div className="mt-6">
                {s.money ? (
                  <>
                    <div className="flex items-baseline gap-1">
                      <span style={{ fontFamily: FONT_DISPLAY, fontSize: '22px', fontWeight: 700, color: NAVY }}>GHS</span>
                      <span style={{ fontFamily: FONT_DISPLAY, fontSize: '40px', fontWeight: 700, lineHeight: 1, color: NAVY }}>
                        {Number(s.value).toLocaleString('en-GH')}
                      </span>
                    </div>
                    {s.growth !== null && s.growth !== undefined && (
                      <div
                        className="flex items-center gap-1 mt-1"
                        style={{ fontSize: '12px', fontWeight: 700, color: s.growth >= 0 ? '#15803d' : '#ba1a1a' }}
                      >
                        {s.growth >= 0
                          ? <TrendingUp size={12} strokeWidth={2} aria-hidden="true" />
                          : <TrendingDown size={12} strokeWidth={2} aria-hidden="true" />
                        }
                        <span>{Math.abs(s.growth)}% from last month</span>
                      </div>
                    )}
                  </>
                ) : (
                  <span style={{ fontFamily: FONT_DISPLAY, fontSize: '48px', fontWeight: 700, lineHeight: 1, color: NAVY }}>
                    {s.value}
                  </span>
                )}
              </div>
            </div>
          )
        })}
      </section>

      {/* Charts row */}
      <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="surface-card p-6">
          <div className="flex justify-between items-center mb-6">
            <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', fontWeight: 600, color: NAVY }}>
              Attendance Trend
            </h3>
            <button
              onClick={() => navigate('/attendance')}
              className="text-xs font-semibold hover:underline transition-colors"
              style={{ color: NAVY }}
              aria-label="View all attendance records"
            >
              View all →
            </button>
          </div>
          {attendance_chart.length === 0 ? (
            <div className="text-center py-12 text-sm" style={{ color: PLACEHOLDER }}>No attendance data yet</div>
          ) : (
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={attendance_chart}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2" />
                <XAxis dataKey="date" stroke="#9ca3af" style={{ fontSize: '12px' }} />
                <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} />
                <Tooltip contentStyle={{ backgroundColor: 'white', border: BORDER, borderRadius: '8px', fontSize: '12px' }} />
                <Line type="monotone" dataKey="count" stroke="var(--color-navy)" strokeWidth={2.5}
                      dot={{ fill: NAVY, r: 4 }} activeDot={{ r: 6 }} />
              </LineChart>
            </ResponsiveContainer>
          )}
        </div>

        <div className="surface-card p-6">
          <div className="flex justify-between items-center mb-6">
            <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', fontWeight: 600, color: NAVY }}>
              Finance Overview
            </h3>
            <button
              onClick={() => navigate('/finance')}
              className="text-xs font-semibold hover:underline transition-colors"
              style={{ color: NAVY }}
              aria-label="View all finance records"
            >
              View all →
            </button>
          </div>
          <ResponsiveContainer width="100%" height={240}>
            <BarChart data={finance_chart}>
              <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2" />
              <XAxis dataKey="month" stroke="#9ca3af" style={{ fontSize: '12px' }} />
              <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} tickFormatter={fmtShort} />
              <Tooltip contentStyle={{ backgroundColor: 'white', border: BORDER, borderRadius: '8px', fontSize: '12px' }} formatter={(v) => fmt(v)} />
              <Legend wrapperStyle={{ fontSize: '12px' }} />
              <Bar dataKey="income"   fill="#059669" name="Income"   radius={[4, 4, 0, 0]} />
              <Bar dataKey="expenses" fill="#ba1a1a" name="Expenses" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </section>

      {/* Bottom row */}
      <section className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* Membership composition */}
        <div className="surface-card p-6">
          <h3 className="mb-6" style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', fontWeight: 600, color: NAVY }}>
            Membership Composition
          </h3>
          <div className="space-y-6">
            <div className="space-y-3">
              <div className="flex justify-between" style={{ fontSize: '14px', fontWeight: 600, color: '#191c1e' }}>
                <span>Male</span><span>{gender_split.male} ({malePct}%)</span>
              </div>
              <div className="w-full rounded-full" style={{ height: '8px', backgroundColor: '#edeef1' }}>
                <div className="rounded-full" style={{ height: '8px', width: `${malePct}%`, backgroundColor: NAVY }} />
              </div>
            </div>
            <div className="space-y-3">
              <div className="flex justify-between" style={{ fontSize: '14px', fontWeight: 600, color: '#191c1e' }}>
                <span>Female</span><span>{gender_split.female} ({femalePct}%)</span>
              </div>
              <div className="w-full rounded-full" style={{ height: '8px', backgroundColor: '#edeef1' }}>
                <div className="rounded-full" style={{ height: '8px', width: `${femalePct}%`, backgroundColor: 'var(--color-gold)' }} />
              </div>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3 mt-6 pt-6" style={{ borderTop: BORDER }}>
            <div>
              <div className="text-lg font-bold" style={{ color: NAVY }}>{counts.departments}</div>
              <div className="text-xs" style={{ color: PLACEHOLDER }}>Active departments</div>
            </div>
            <div>
              <div className="text-lg font-bold" style={{ color: '#d97706' }}>{counts.pending_visitors}</div>
              <div className="text-xs" style={{ color: PLACEHOLDER }}>Pending follow-ups</div>
            </div>
          </div>
        </div>

        {/* Top income sources */}
        <div className="surface-card p-6">
          <h3 className="mb-6" style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', fontWeight: 600, color: NAVY }}>
            Top Income Sources
          </h3>
          {top_categories.length === 0 ? (
            <div className="text-center py-8 text-sm" style={{ color: PLACEHOLDER }}>No income recorded this month</div>
          ) : (
            <div className="space-y-6">
              {top_categories.map((cat) => {
                const max = Math.max(...top_categories.map(c => c.total))
                const pct = max > 0 ? (cat.total / max) * 100 : 0
                return (
                  <div key={cat.name} className="space-y-3">
                    <div className="flex justify-between" style={{ fontSize: '14px', fontWeight: 600, color: '#191c1e' }}>
                      <span>{cat.name}</span>
                      <span style={{ fontFamily: FONT_DISPLAY, color: NAVY }}>{fmt(cat.total)}</span>
                    </div>
                    <div className="w-full rounded-full" style={{ height: '8px', backgroundColor: '#edeef1' }}>
                      <div className="rounded-full" style={{ height: '8px', width: `${pct}%`, backgroundColor: '#059669' }} />
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        <div className="surface-card p-6">
          <h3 className="mb-6" style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', fontWeight: 600, color: NAVY }}>
            Quick Actions
          </h3>
          <div className="grid grid-cols-2 gap-3">
            {[
              { to: '/members/new',    label: 'Add Member',      icon: UserPlus },
              { to: '/attendance/new', label: 'Take Attendance', icon: ClipboardList },
              { to: '/finance/new',    label: 'Record Income',   icon: Wallet },
              { to: '/visitors/new',   label: 'Add Visitor',     icon: Users },
            ].map(a => {
              const IconComp = a.icon
              return (
                <button
                  key={a.to}
                  onClick={() => navigate(a.to)}
                  className="group flex flex-col items-center justify-center gap-2 rounded-xl p-6 transition-shadow duration-200 hover:shadow-sm active:scale-95"
                  style={{ backgroundColor: '#f2f3f6', border: '1px solid transparent' }}
                >
                  <span className="transition-transform duration-200 group-hover:scale-110">
                    <IconComp size={22} strokeWidth={1.8} style={{ color: NAVY }} aria-hidden="true" />
                  </span>
                  <span style={{ fontSize: '12px', fontWeight: 600, textAlign: 'center', color: NAVY }}>
                    {a.label}
                  </span>
                </button>
              )
            })}
          </div>
        </div>
      </section>

      {/* Recent activity */}
      <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="surface-card p-6">
          <div className="flex justify-between items-center mb-4">
            <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', fontWeight: 600, color: NAVY }}>
              Recent Members
            </h3>
            <button
              onClick={() => navigate('/members')}
              className="text-xs font-semibold hover:underline transition-colors"
              style={{ color: NAVY }}
              aria-label="View all members"
            >
              View all →
            </button>
          </div>
          {recent_members.length === 0 ? (
            <div className="text-center py-6 text-sm" style={{ color: PLACEHOLDER }}>No members yet</div>
          ) : (
            <div className="space-y-3">
              {recent_members.map((m, i) => (
                <div key={i} className="flex items-center gap-3">
                  <div
                    className="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                    style={{ backgroundColor: NAVY }}
                    aria-hidden="true"
                  >
                    {m.name.charAt(0)}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold truncate" style={{ color: '#111827' }}>{m.name}</div>
                    <div className="text-xs" style={{ color: PLACEHOLDER }}>{m.detail} · {m.when}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="surface-card p-6">
          <div className="flex justify-between items-center mb-4">
            <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', fontWeight: 600, color: NAVY }}>
              Recent Transactions
            </h3>
            <button
              onClick={() => navigate('/finance')}
              className="text-xs font-semibold hover:underline transition-colors"
              style={{ color: NAVY }}
              aria-label="View all transactions"
            >
              View all →
            </button>
          </div>
          {recent_transactions.length === 0 ? (
            <div className="text-center py-6 text-sm" style={{ color: PLACEHOLDER }}>No transactions yet</div>
          ) : (
            <div className="space-y-3">
              {recent_transactions.map((t, i) => (
                <div key={i} className="flex items-center gap-3">
                  <div
                    className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
                    style={{ backgroundColor: t.type === 'income' ? '#dcfce7' : '#ffdad6' }}
                    aria-hidden="true"
                  >
                    {t.type === 'income'
                      ? <TrendingUp size={16} strokeWidth={2} style={{ color: '#059669' }} />
                      : <TrendingDown size={16} strokeWidth={2} style={{ color: '#ba1a1a' }} />
                    }
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold truncate" style={{ color: '#111827' }}>{t.detail}</div>
                    <div className="text-xs" style={{ color: PLACEHOLDER }}>{t.when}</div>
                  </div>
                  <div
                    className="text-sm font-bold flex-shrink-0"
                    style={{ color: t.type === 'income' ? '#15803d' : '#ba1a1a' }}
                  >
                    {t.type === 'income' ? '+' : '−'} {fmt(t.amount)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>
    </div>
  )
}
