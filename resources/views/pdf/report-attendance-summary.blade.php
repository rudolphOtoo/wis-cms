<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Helvetica', Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5; }
  .header { background: #0D1F3C; color: white; padding: 30px 40px; }
  .header-top { display: table; width: 100%; }
  .header-logo { display: table-cell; width: 50px; vertical-align: middle; }
  .header-logo img { width: 44px; height: 44px; }
  .header-text { display: table-cell; vertical-align: middle; padding-left: 14px; }
  .header-badge { display: inline-block; padding: 3px 10px; background: #C9A84C; color: #0D1F3C; font-size: 10px; font-weight: bold; border-radius: 10px; letter-spacing: 0.5px; }
  .header h1 { font-size: 22px; margin: 12px 0 2px; }
  .header p { color: rgba(255,255,255,0.6); font-size: 11px; }
  .meta { padding: 24px 40px; border-bottom: 2px solid #E5E9F2; }
  .meta-row { display: table; width: 100%; margin-bottom: 8px; }
  .meta-cell { display: table-cell; width: 33%; vertical-align: top; }
  .meta-label { color: #9ca3af; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
  .meta-value { font-size: 13px; font-weight: bold; color: #0D1F3C; margin-bottom: 8px; }
  .content { padding: 24px 40px; }
  .section-title { font-size: 14px; font-weight: bold; color: #0D1F3C; margin: 18px 0 12px; border-bottom: 1px solid #E5E9F2; padding-bottom: 6px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  th { text-align: left; padding: 8px 10px; background: #f9fafb; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; border-bottom: 1px solid #E5E9F2; }
  td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; font-size: 11px; }
  .num { text-align: right; }
  .empty { padding: 12px; color: #9ca3af; font-style: italic; font-size: 11px; }

  /* KPI cards row */
  .kpi-row { display: table; width: 100%; margin-bottom: 18px; }
  .kpi-card { display: table-cell; width: 25%; padding: 16px; background: #f9fafb; border: 1px solid #E5E9F2; border-radius: 4px; }
  .kpi-card + .kpi-card { margin-left: 8px; }
  .kpi-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; font-weight: bold; }
  .kpi-value { font-size: 22px; font-weight: bold; color: #0D1F3C; margin-top: 4px; }
  .kpi-sub { font-size: 10px; color: #9ca3af; margin-top: 2px; }

  /* Trend badge */
  .trend-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: bold; }
  .trend-up { background: #15803d; color: white; }
  .trend-down { background: #ba1a1a; color: white; }
  .trend-flat { background: #6b7280; color: white; }

  /* Welfare pill */
  .welfare-pill { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: bold; margin-right: 3px; }
  .w-engaged { background: #dcfce7; color: #166534; }
  .w-moderate { background: #fef9c3; color: #854d0e; }
  .w-at-risk { background: #fee2e2; color: #991b1b; }
  .w-inactive { background: #e5e7eb; color: #374151; }

  /* At risk callout */
  .callout { margin-top: 14px; padding: 14px 18px; background: #FFF7ED; border: 1px solid #FDBA74; border-radius: 4px; }
  .callout-title { font-size: 12px; font-weight: bold; color: #9A3412; margin-bottom: 6px; }
  .callout-body { font-size: 11px; color: #78350F; }

  .footer { padding: 20px 40px; margin-top: 20px; border-top: 2px solid #E5E9F2; color: #9ca3af; font-size: 10px; text-align: center; }
</style>
</head>
<body>
  <div class="header">
    <div class="header-top">
      @if (!empty($logoPath))
        <div class="header-logo">
          <img src="{{ $logoPath }}" alt="Logo" />
        </div>
      @endif
      <div class="header-text">
        <span class="header-badge">{{ $branchName }}</span>
        <h1>Leaders' Meeting Report &mdash; Church Attendance Summary</h1>
        <p>Methodist Church Ghana &middot; {{ \Carbon\Carbon::parse($data['period']['from'])->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($data['period']['to'])->format('M j, Y') }}</p>
      </div>
    </div>
  </div>

  <div class="meta">
    <div class="meta-row">
      <div class="meta-cell">
        <div class="meta-label">Period</div>
        <div class="meta-value">{{ \Carbon\Carbon::parse($data['period']['from'])->format('F j, Y') }} &ndash; {{ \Carbon\Carbon::parse($data['period']['to'])->format('F j, Y') }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Sundays Covered</div>
        <div class="meta-value">{{ $data['summary']['total_sundays'] }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Generated</div>
        <div class="meta-value">{{ $generatedAt }}</div>
      </div>
    </div>
  </div>

  <div class="content">

    {{-- ── KPI CARDS ────────────────────────────────────────────── --}}
    <div class="kpi-row">
      <div class="kpi-card">
        <div class="kpi-label">Overall Attendance Rate</div>
        <div class="kpi-value">{{ $data['summary']['overall_attendance_rate'] }}%</div>
        <div class="kpi-sub">Across {{ $data['summary']['total_sundays'] }} Sundays</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Avg Attendance / Sunday</div>
        <div class="kpi-value">{{ $data['summary']['avg_per_sunday'] }}</div>
        <div class="kpi-sub">{{ $data['summary']['avg_adults'] }} adults &middot; {{ $data['summary']['avg_children'] }} children</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Active Members</div>
        <div class="kpi-value">{{ number_format($data['summary']['total_active_members']) }}</div>
        <div class="kpi-sub">Across {{ count($data['cell_summary']) }} Cells</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Attendance Trend</div>
        <div class="kpi-value">
          @php $trend = $data['summary']['trend'] ?? null; @endphp
          @if ($trend && $trend['direction'] !== 'unknown')
            <span class="trend-badge trend-{{ $trend['direction'] === 'up' ? 'up' : ($trend['direction'] === 'down' ? 'down' : 'flat') }}">
              @if ($trend['direction'] === 'up') &#8593; Improving
              @elseif ($trend['direction'] === 'down') &#8595; Declining
              @else &#8594; Stable
              @endif
            </span>
          @else
            <span style="color:#9ca3af;">N/A</span>
          @endif
        </div>
        <div class="kpi-sub">
          @if ($trend && $trend['delta'] != 0)
            {{ $trend['delta'] > 0 ? '+' : '' }}{{ $trend['delta'] }} avg
          @endif
        </div>
      </div>
    </div>

    {{-- ── CELL / CLASS BREAKDOWN TABLE ─────────────────────────── --}}
    <div class="section-title">Cell / Class Breakdown</div>
    @if (empty($data['cell_summary']))
      <div class="empty">No cell data available for this period.</div>
    @else
      <table>
        <thead>
          <tr>
            <th>Cell / Class</th>
            <th class="num">Members</th>
            <th class="num">Avg Attendance</th>
            <th class="num">Attendance Rate</th>
            <th class="num">Contribution</th>
            <th class="num">Pastoral Notes</th>
          </tr>
        </thead>
        <tbody>
          @php $grandTotal = array_sum(array_column($data['cell_summary'], 'avg_attendance')); @endphp
          @foreach ($data['cell_summary'] as $cell)
            @php $contribution = $grandTotal > 0 ? round(($cell['avg_attendance'] / $grandTotal) * 100, 1) : 0; @endphp
            <tr>
              <td style="font-weight: bold;">{{ $cell['name'] }}</td>
              <td class="num">{{ $cell['member_count'] }}</td>
              <td class="num">{{ $cell['avg_attendance'] }}</td>
              <td class="num">
                @if ($cell['attendance_rate'] !== null)
                  {{ $cell['attendance_rate'] }}%
                  @if ($cell['attendance_rate'] < 50)
                    <span style="color:#ba1a1a;font-weight:bold;"> &#9888;</span>
                  @endif
                @else
                  &mdash;
                @endif
              </td>
              <td class="num">{{ $contribution }}%</td>
              <td class="num">{{ $cell['recent_pastoral_notes_count'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    {{-- ── STATE OF THE MEMBERS ────────────────────────────────── --}}
    <div class="section-title">State of the Members &mdash; Welfare Overview</div>
    @php $ws = $data['summary']['welfare_summary']; @endphp
    @php $totalMembers = $ws['engaged'] + $ws['moderate'] + $ws['at_risk'] + $ws['inactive_risk']; @endphp
    <table>
      <thead>
        <tr>
          <th>Status</th>
          <th class="num">Count</th>
          <th class="num">Share</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><span class="welfare-pill w-engaged">Engaged</span></td>
          <td class="num">{{ $ws['engaged'] }}</td>
          <td class="num">{{ $totalMembers > 0 ? round(($ws['engaged'] / $totalMembers) * 100, 1) : 0 }}%</td>
        </tr>
        <tr>
          <td><span class="welfare-pill w-moderate">Moderate</span></td>
          <td class="num">{{ $ws['moderate'] }}</td>
          <td class="num">{{ $totalMembers > 0 ? round(($ws['moderate'] / $totalMembers) * 100, 1) : 0 }}%</td>
        </tr>
        <tr>
          <td><span class="welfare-pill w-at-risk">At Risk</span></td>
          <td class="num">{{ $ws['at_risk'] }}</td>
          <td class="num">{{ $totalMembers > 0 ? round(($ws['at_risk'] / $totalMembers) * 100, 1) : 0 }}%</td>
        </tr>
        <tr>
          <td><span class="welfare-pill w-inactive">Inactive Risk</span></td>
          <td class="num">{{ $ws['inactive_risk'] }}</td>
          <td class="num">{{ $totalMembers > 0 ? round(($ws['inactive_risk'] / $totalMembers) * 100, 1) : 0 }}%</td>
        </tr>
      </tbody>
    </table>

    @if (!empty($data['summary']['cells_at_risk']))
      <div class="callout">
        <div class="callout-title">&#9888; Cells Requiring Attention</div>
        <div class="callout-body">
          The following Cells have an average attendance rate below 50% and may require pastoral follow-up:
          <strong>{{ implode(', ', $data['summary']['cells_at_risk']) }}</strong>
        </div>
      </div>
    @endif

    {{-- ── WEEKLY TREND TABLE ───────────────────────────────────── --}}
    <div class="section-title">Weekly Attendance Trend</div>
    @if (empty($data['sundays']))
      <div class="empty">No attendance recorded in this period.</div>
    @else
      <table>
        <thead>
          <tr>
            <th>Sunday</th>
            <th class="num">Adults</th>
            <th class="num">Children</th>
            <th class="num">Total</th>
            <th class="num">Attendance Rate</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($data['sundays'] as $sunday)
            @php
              $rate = $data['summary']['total_active_members'] > 0
                ? round(($sunday['total_count'] / $data['summary']['total_active_members']) * 100, 1)
                : 0;
            @endphp
            <tr>
              <td style="font-weight: bold;">{{ $sunday['date_label'] }}</td>
              <td class="num">{{ $sunday['adult_count'] }}</td>
              <td class="num">{{ $sunday['children_count'] }}</td>
              <td class="num" style="font-weight: bold;">{{ $sunday['total_count'] }}</td>
              <td class="num">{{ $rate }}%</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

  </div>

  <div class="footer">
    {{ $branchName }} &middot; Leaders' Meeting Report &middot; Generated by WIS-CMS &middot; {{ $generatedAt }}
  </div>
</body>
</html>
