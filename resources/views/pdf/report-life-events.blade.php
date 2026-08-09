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
  .month-title { font-size: 12px; font-weight: bold; color: #374766; margin: 14px 0 6px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  th { text-align: left; padding: 8px 10px; background: #f9fafb; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; border-bottom: 1px solid #E5E9F2; }
  td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; font-size: 11px; }
  .num { text-align: right; }
  .empty { padding: 12px; color: #9ca3af; font-style: italic; font-size: 11px; }
  .deaths { color: #ba1a1a; }
  .births { color: #15803d; }
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
        <h1>Year in Review — Life Events</h1>
        <p>{{ $data['year'] }} &middot; Those who left us &amp; those who were born</p>
      </div>
    </div>
  </div>

  <div class="meta">
    <div class="meta-row">
      <div class="meta-cell">
        <div class="meta-label">Year</div>
        <div class="meta-value">{{ $data['year'] }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Period</div>
        <div class="meta-value">Jan 1 &ndash; Dec 31, {{ $data['year'] }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Generated</div>
        <div class="meta-value">{{ $generatedAt }}</div>
      </div>
    </div>
  </div>

  <div class="content">
    {{-- Monthly Breakdown --}}
    <div class="section-title">Monthly Breakdown</div>
    <table>
      <thead>
        <tr>
          <th>Month</th>
          <th class="num deaths">Deaths</th>
          <th class="num births">Births</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($data['monthly'] as $month)
        <tr>
          <td>{{ $month['label'] }}</td>
          <td class="num">{{ $month['deaths'] }}</td>
          <td class="num">{{ $month['births'] }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>

    {{-- Deaths --}}
    <div class="section-title">Those Who Left Us ({{ count($data['deaths']) }})</div>
    @if (empty($data['deaths']))
      <div class="empty">No deaths recorded for {{ $data['year'] }}.</div>
    @else
      @php $currentMonth = null; @endphp
      @foreach ($data['deaths'] as $death)
        @if ($death['month_label'] !== $currentMonth)
          @php $currentMonth = $death['month_label']; @endphp
          <div class="month-title">{{ $death['month_label'] }}</div>
        @endif
        <table>
          <tbody>
            <tr>
              <td style="width: 140px; color: #6b7280;">
                {{ \Carbon\Carbon::parse($death['event_date'])->format('M j, Y') }}
                @if (!empty($death['burial_date']))
                  <br/><span style="font-size: 10px;">Buried: {{ \Carbon\Carbon::parse($death['burial_date'])->format('M j, Y') }}</span>
                @endif
              </td>
              <td style="font-weight: bold;">{{ $death['name'] }}</td>
              <td style="color: #9ca3af;">{{ $death['notes'] ?? '' }}</td>
            </tr>
          </tbody>
        </table>
      @endforeach
    @endif

    {{-- Births --}}
    <div class="section-title">Those Who Were Born ({{ count($data['births']) }})</div>
    @if (empty($data['births']))
      <div class="empty">No births recorded for {{ $data['year'] }}.</div>
    @else
      @php $currentMonth = null; @endphp
      @foreach ($data['births'] as $birth)
        @if ($birth['month_label'] !== $currentMonth)
          @php $currentMonth = $birth['month_label']; @endphp
          <div class="month-title">{{ $birth['month_label'] }}</div>
        @endif
        <table>
          <tbody>
            <tr>
              <td style="width: 140px; color: #6b7280;">{{ \Carbon\Carbon::parse($birth['event_date'])->format('M j, Y') }}</td>
              <td style="font-weight: bold;">{{ $birth['name'] }}</td>
              <td style="color: #9ca3af;">
                @if (!empty($birth['father_name']))
                  Father: {{ $birth['father_name'] }} &middot;
                @endif
                Mother: {{ $birth['mother_name'] }}
              </td>
              <td style="color: #9ca3af;">{{ $birth['notes'] ?? '' }}</td>
            </tr>
          </tbody>
        </table>
      @endforeach
    @endif

    <div class="summary-card">
      <div class="summary-row">
        <span class="lbl">Total Deaths</span>
        <span class="val">{{ $data['totals']['deaths'] }}</span>
      </div>
      <div class="summary-row">
        <span class="lbl">Total Births</span>
        <span class="val">{{ $data['totals']['births'] }}</span>
      </div>
    </div>
  </div>

  <div class="footer">
    {{ $branchName }} &middot; Generated by WIS-CMS &middot; {{ $generatedAt }}
  </div>
</body>
</html>
