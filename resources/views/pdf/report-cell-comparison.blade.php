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
  td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; font-size: 11px; vertical-align: top; }
  .num { text-align: right; }
  .rate { text-align: right; font-weight: bold; }
  .rate-high { color: #15803d; }
  .rate-mid { color: #ca8a04; }
  .rate-low { color: #ba1a1a; }
  .empty { padding: 12px; color: #9ca3af; font-style: italic; font-size: 11px; }
  .flag-badge { display: inline-block; padding: 2px 6px; border-radius: 8px; font-size: 9px; font-weight: bold; margin-right: 4px; margin-bottom: 2px; white-space: nowrap; }
  .flag-no-leader     { background: #fee2e2; color: #991b1b; }
  .flag-low-membership{ background: #fef3c7; color: #92400e; }
  .flag-no-recent     { background: #f3f4f6; color: #6b7280; }
  .leader-missing { color: #6b7280; font-style: italic; }
  .summary-card { margin-top: 24px; padding: 20px; background: #0D1F3C; color: white; border-radius: 4px; }
  .summary-row { display: table; width: 100%; margin-bottom: 6px; }
  .summary-row .lbl { display: table-cell; font-size: 12px; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.5px; }
  .summary-row .val { display: table-cell; text-align: right; font-size: 14px; font-weight: bold; }
  .summary-card .highlight { border-top: 1px solid rgba(255,255,255,0.2); padding-top: 8px; margin-top: 8px; }
  .summary-card .highlight .lbl { color: #C9A84C; font-size: 13px; }
  .summary-card .highlight .val { font-size: 18px; color: #C9A84C; }
  .footer { padding: 20px 40px; margin-top: 20px; border-top: 2px solid #E5E9F2; color: #9ca3af; font-size: 10px; text-align: center; }
</style>
</head>
<body>
  <div class="header">
    <span class="header-badge">{{ $branchName }}</span>
    <h1>Cell Comparison</h1>
    <p>Methodist Church Ghana &middot; Cell health snapshot &middot; {{ $generatedAt }}</p>
  </div>

  <div class="meta">
    <div class="meta-row">
      <div class="meta-cell">
        <div class="meta-label">Lookback Window</div>
        <div class="meta-value">Last {{ $data['period']['weeks'] }} week{{ $data['period']['weeks'] === 1 ? '' : 's' }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">From</div>
        <div class="meta-value">{{ \Carbon\Carbon::parse($data['period']['from'])->format('F j, Y') }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Generated</div>
        <div class="meta-value">{{ $generatedAt }}</div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="section-title">Cell Health</div>
    @if (empty($data['cells']))
      <div class="empty">No cells configured for this branch.</div>
    @else
      <table>
        <thead>
          <tr>
            <th>Cell</th>
            <th>Leader</th>
            <th class="num">Members</th>
            <th class="num">Sessions</th>
            <th class="rate">Rate</th>
            <th>Last Session</th>
            <th>Flags</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($data['cells'] as $cell)
            @php
              $rate = (float) ($cell['recent_attendance_rate'] ?? 0);
              $rateClass = $rate >= 70 ? 'rate-high' : ($rate >= 50 ? 'rate-mid' : 'rate-low');
            @endphp
            <tr>
              <td><strong>{{ $cell['name'] }}</strong></td>
              <td>
                @if (!empty($cell['leader']))
                  {{ $cell['leader']['name'] }}
                @else
                  <span class="leader-missing">— vacant —</span>
                @endif
              </td>
              <td class="num">{{ $cell['member_count'] }}</td>
              <td class="num">{{ $cell['recent_sessions'] ?? 0 }}</td>
              <td class="rate {{ $rateClass }}">
                @if (($cell['recent_sessions'] ?? 0) > 0)
                  {{ $cell['recent_attendance_rate'] }}%
                @else
                  —
                @endif
              </td>
              <td>{{ $cell['last_session_date'] ?? '—' }}</td>
              <td>
                @foreach ($cell['health_flags'] ?? [] as $flag)
                  @if ($flag === 'no_leader')
                    <span class="flag-badge flag-no-leader">No leader</span>
                  @elseif ($flag === 'low_membership')
                    <span class="flag-badge flag-low-membership">Low membership</span>
                  @elseif ($flag === 'no_recent_attendance')
                    <span class="flag-badge flag-no-recent">No recent attendance</span>
                  @else
                    <span class="flag-badge flag-no-recent">{{ $flag }}</span>
                  @endif
                @endforeach
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div class="summary-card">
      <div class="summary-row">
        <span class="lbl">Total Cells</span>
        <span class="val">{{ $data['summary']['total_cells'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">With Leader</span>
        <span class="val">{{ $data['summary']['cells_with_leader'] }} / {{ $data['summary']['total_cells'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Recent Attendance Recorded</span>
        <span class="val">{{ $data['summary']['cells_with_recent_attendance'] }} / {{ $data['summary']['total_cells'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Total Members</span>
        <span class="val">{{ $data['summary']['total_members'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Avg Members per Cell</span>
        <span class="val">{{ $data['summary']['avg_members_per_cell'] }}</span>
      </div>
      <div class="summary-row highlight">
        <span class="lbl">Avg Attendance Rate</span>
        <span class="val">{{ $data['summary']['avg_attendance_rate'] }}%</span>
      </div>
    </div>
  </div>

  <div class="footer">
    {{ $branchName }} &middot; Generated by WIS-CMS &middot; {{ $generatedAt }}
  </div>
</body>
</html>
