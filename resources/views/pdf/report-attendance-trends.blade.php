<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Helvetica', Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5; }
  .header { background: #0D1F3C; color: white; padding: 30px 40px; }
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
  .rate { text-align: right; font-weight: bold; }
  .rate-high { color: #15803d; }
  .rate-mid { color: #ca8a04; }
  .rate-low { color: #ba1a1a; }
  .empty { padding: 12px; color: #9ca3af; font-style: italic; font-size: 11px; }
  .summary-card { margin-top: 24px; padding: 20px; background: #0D1F3C; color: white; border-radius: 4px; }
  .summary-row { display: table; width: 100%; margin-bottom: 6px; }
  .summary-row .lbl { display: table-cell; font-size: 12px; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.5px; }
  .summary-row .val { display: table-cell; text-align: right; font-size: 14px; font-weight: bold; }
  .summary-card .highlight { border-top: 1px solid rgba(255,255,255,0.2); padding-top: 8px; margin-top: 8px; }
  .summary-card .highlight .lbl { color: #C9A84C; font-size: 13px; }
  .summary-card .highlight .val { font-size: 18px; color: #C9A84C; }
  .trend-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: bold; }
  .trend-up { background: #15803d; color: white; }
  .trend-down { background: #ba1a1a; color: white; }
  .trend-flat { background: #6b7280; color: white; }
  .footer { padding: 20px 40px; margin-top: 20px; border-top: 2px solid #E5E9F2; color: #9ca3af; font-size: 10px; text-align: center; }
</style>
</head>
<body>
  <div class="header">
    <span class="header-badge">{{ $branchName }}</span>
    <h1>Attendance Trends</h1>
    <p>Methodist Church Ghana &middot; {{ \Carbon\Carbon::parse($data['period']['from'])->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($data['period']['to'])->format('M j, Y') }}</p>
  </div>

  <div class="meta">
    <div class="meta-row">
      <div class="meta-cell">
        <div class="meta-label">Period</div>
        <div class="meta-value">{{ \Carbon\Carbon::parse($data['period']['from'])->format('F j, Y') }} &ndash; {{ \Carbon\Carbon::parse($data['period']['to'])->format('F j, Y') }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Grouping</div>
        <div class="meta-value">{{ ucfirst($data['period']['group_by']) }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Generated</div>
        <div class="meta-value">{{ $generatedAt }}</div>
      </div>
    </div>
    @php $trend = $data['summary']['trend'] ?? null; @endphp
    @if ($trend && !empty($trend['direction']))
    <div class="meta-row">
      <div class="meta-cell">
        <div class="meta-label">Trend</div>
        <div class="meta-value">
          @if ($trend['direction'] === 'up')
            <span class="trend-badge trend-up">↑ Improving</span>
          @elseif ($trend['direction'] === 'down')
            <span class="trend-badge trend-down">↓ Declining</span>
          @else
            <span class="trend-badge trend-flat">→ Stable</span>
          @endif
          @if (isset($trend['delta_pct']))
            <span style="margin-left: 8px; font-weight: normal; color: #6b7280;">{{ $trend['delta_pct'] > 0 ? '+' : '' }}{{ $trend['delta_pct'] }}%</span>
          @endif
        </div>
      </div>
    </div>
    @endif
  </div>

  <div class="content">
    <div class="section-title">Period Breakdown</div>
    @if (empty($data['rows']))
      <div class="empty">No attendance recorded in this period.</div>
    @else
      <table>
        <thead>
          <tr>
            <th>Period</th>
            <th class="num">Sessions</th>
            <th class="num">Present</th>
            <th class="num">Absent</th>
            <th class="num">Total</th>
            <th class="rate">Rate</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($data['rows'] as $row)
            @php
              $rate = (float) ($row['attendance_rate'] ?? 0);
              $rateClass = $rate >= 70 ? 'rate-high' : ($rate >= 50 ? 'rate-mid' : 'rate-low');
            @endphp
            <tr>
              <td>{{ $row['label'] ?? $row['period_start'] ?? '—' }}</td>
              <td class="num">{{ $row['sessions'] ?? '—' }}</td>
              <td class="num">{{ $row['records_present'] ?? '—' }}</td>
              <td class="num">{{ $row['records_absent'] ?? '—' }}</td>
              <td class="num">{{ $row['records_total'] ?? '—' }}</td>
              <td class="rate {{ $rateClass }}">{{ $row['attendance_rate'] ?? '—' }}%</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div class="summary-card">
      <div class="summary-row">
        <span class="lbl">Total Sessions</span>
        <span class="val">{{ $data['summary']['total_sessions'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Total Present</span>
        <span class="val">{{ $data['summary']['total_present'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Total Absent</span>
        <span class="val">{{ $data['summary']['total_absent'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Avg per Session</span>
        <span class="val">{{ $data['summary']['avg_per_session'] }}</span>
      </div>
      <div class="summary-row highlight">
        <span class="lbl">Overall Attendance Rate</span>
        <span class="val">{{ $data['summary']['overall_attendance_rate'] }}%</span>
      </div>
    </div>
  </div>

  <div class="footer">
    {{ $branchName }} &middot; Generated by WIS-CMS &middot; {{ $generatedAt }}
  </div>
</body>
</html>
