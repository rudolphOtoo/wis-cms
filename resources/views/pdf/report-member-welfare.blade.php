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
  .flag-engaged { color: #15803d; font-weight: bold; }
  .flag-moderate { color: #2e7d32; }
  .flag-at_risk { color: #ca8a04; font-weight: bold; }
  .flag-inactive_risk { color: #ba1a1a; font-weight: bold; }
  .flag-none { color: #9ca3af; }
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
    <div class="header-top">
      @if (!empty($logoPath))
        <div class="header-logo">
          <img src="{{ $logoPath }}" alt="Logo" />
        </div>
      @endif
      <div class="header-text">
        <span class="header-badge">{{ $branchName }}</span>
        <h1>Member Welfare Report</h1>
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
        <div class="meta-label">Window</div>
        <div class="meta-value">{{ $data['period']['window_weeks'] }} weeks</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Generated</div>
        <div class="meta-value">{{ $generatedAt }}</div>
      </div>
    </div>
  </div>

  <div class="content">
    {{-- Cell Breakdown --}}
    @if (!empty($data['summary']['by_cell']))
    <div class="section-title">Cell Welfare Overview</div>
    <table>
      <thead>
        <tr>
          <th>Cell</th>
          <th class="num">Members</th>
          <th class="num">Avg Rate</th>
          <th class="num">Engaged</th>
          <th class="num">Moderate</th>
          <th class="num">At Risk</th>
          <th class="num">Inactive Risk</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($data['summary']['by_cell'] as $cell)
        <tr>
          <td style="font-weight: bold;">{{ $cell['name'] }}</td>
          <td class="num">{{ $cell['member_count'] }}</td>
          <td class="num">{{ $cell['avg_attendance_rate'] }}%</td>
          <td class="num" style="color: #15803d;">{{ $cell['engaged'] }}</td>
          <td class="num" style="color: #2e7d32;">{{ $cell['moderate'] }}</td>
          <td class="num" style="color: #ca8a04;">{{ $cell['at_risk'] }}</td>
          <td class="num" style="color: #ba1a1a;">{{ $cell['inactive_risk'] }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif

    {{-- Detailed Member List --}}
    <div class="section-title">Member Details</div>
    @if (empty($data['members']))
      <div class="empty">No members found.</div>
    @else
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Cell</th>
            <th>Flag</th>
            <th class="num">Rate</th>
            <th class="num">Attended</th>
            <th class="num">Giving</th>
            <th>Last Attendance</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($data['members'] as $member)
          <tr>
            <td>{{ $member['name'] }}</td>
            <td>{{ $member['cell_name'] }}</td>
            <td class="flag-{{ $member['welfare_flag'] }}">{{ ucfirst(str_replace('_', ' ', $member['welfare_flag'])) }}</td>
            <td class="num">{{ $member['attendance_rate'] }}%</td>
            <td class="num">{{ $member['attended_services'] }} / {{ $member['total_sundays_in_window'] }}</td>
            <td class="num">GHS {{ number_format($member['giving_total'], 2) }}</td>
            <td>{{ $member['last_attendance_date'] ?? '—' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div class="summary-card">
      <div class="summary-row">
        <span class="lbl">Total Members</span>
        <span class="val">{{ $data['summary']['total_members'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Avg Attendance Rate</span>
        <span class="val">{{ $data['summary']['avg_attendance_rate'] }}%</span>
      </div>
      @foreach ($data['summary']['flag_counts'] as $flag => $count)
      <div class="summary-row">
        <span class="lbl">{{ ucfirst(str_replace('_', ' ', $flag)) }}</span>
        <span class="val">{{ $count }}</span>
      </div>
      @endforeach
    </div>
  </div>

  <div class="footer">
    {{ $branchName }} &middot; Generated by WIS-CMS &middot; {{ $generatedAt }}
  </div>
</body>
</html>
