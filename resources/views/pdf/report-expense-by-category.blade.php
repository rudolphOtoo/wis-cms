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
  .meta-cell { display: table-cell; width: 50%; vertical-align: top; }
  .meta-label { color: #9ca3af; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
  .meta-value { font-size: 13px; font-weight: bold; color: #0D1F3C; margin-bottom: 8px; }
  .content { padding: 24px 40px; }
  .section-title { font-size: 14px; font-weight: bold; color: #ba1a1a; margin: 18px 0 12px; border-bottom: 1px solid #E5E9F2; padding-bottom: 6px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  th { text-align: left; padding: 8px 10px; background: #f9fafb; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; border-bottom: 1px solid #E5E9F2; }
  td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; font-size: 11px; }
  .amount { text-align: right; font-weight: bold; }
  .pct { text-align: right; color: #6b7280; }
  .subtotal-row td { font-weight: bold; background: #f9fafb; }
  .empty { padding: 12px; color: #9ca3af; font-style: italic; font-size: 11px; }
  .totals { margin-top: 24px; padding: 20px; background: #0D1F3C; color: white; border-radius: 4px; }
  .totals-row { display: table; width: 100%; margin-bottom: 6px; }
  .totals-row .lbl { display: table-cell; font-size: 12px; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.5px; }
  .totals-row .val { display: table-cell; text-align: right; font-size: 14px; font-weight: bold; }
  .totals .grand { border-top: 1px solid rgba(255,255,255,0.2); padding-top: 8px; margin-top: 8px; }
  .totals .grand .lbl { color: #C9A84C; font-size: 13px; }
  .totals .grand .val { font-size: 18px; color: #C9A84C; }
  .footer { padding: 20px 40px; margin-top: 20px; border-top: 2px solid #E5E9F2; color: #9ca3af; font-size: 10px; text-align: center; }
</style>
</head>
<body>
  <div class="header">
    <span class="header-badge">{{ $branchName }}</span>
    <h1>Expense by Category</h1>
    <p>Methodist Church Ghana &middot; {{ \Carbon\Carbon::parse($data['period']['from'])->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($data['period']['to'])->format('M j, Y') }}</p>
  </div>

  <div class="meta">
    <div class="meta-row">
      <div class="meta-cell">
        <div class="meta-label">Period</div>
        <div class="meta-value">{{ \Carbon\Carbon::parse($data['period']['from'])->format('F j, Y') }} &ndash; {{ \Carbon\Carbon::parse($data['period']['to'])->format('F j, Y') }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Generated</div>
        <div class="meta-value">{{ $generatedAt }}</div>
      </div>
    </div>
    <div class="meta-row">
      <div class="meta-cell">
        <div class="meta-label">Top Category</div>
        <div class="meta-value">{{ $data['summary']['top_category'] ?? '—' }}</div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Months Covered</div>
        <div class="meta-value">{{ $data['summary']['month_count'] }}</div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="section-title">Category Breakdown</div>
    @if (empty($data['summary']['category_totals']))
      <div class="empty">No expenses recorded in this period.</div>
    @else
      <table>
        <thead><tr><th>Category</th><th class="amount">Total (GHS)</th><th class="pct">Share</th></tr></thead>
        <tbody>
          @foreach ($data['summary']['category_totals'] as $cat)
            <tr>
              <td>{{ $cat['category_name'] }}</td>
              <td class="amount">{{ number_format($cat['total'], 2) }}</td>
              <td class="pct">{{ $cat['percentage'] }}%</td>
            </tr>
          @endforeach
          <tr class="subtotal-row">
            <td>Total Expenses</td>
            <td class="amount">{{ number_format($data['summary']['grand_total'], 2) }}</td>
            <td class="pct">100.0%</td>
          </tr>
        </tbody>
      </table>
    @endif

    <div class="section-title" style="color: #0D1F3C; margin-top: 28px;">Monthly Detail</div>
    @if (empty($data['rows']))
      <div class="empty">No detail rows.</div>
    @else
      <table>
        <thead><tr><th>Month</th><th>Category</th><th class="amount">Amount (GHS)</th></tr></thead>
        <tbody>
          @foreach ($data['rows'] as $row)
            <tr>
              <td>{{ $row['month'] }}</td>
              <td>{{ $row['category_name'] }}</td>
              <td class="amount">{{ number_format($row['total'], 2) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div class="totals">
      <div class="totals-row">
        <span class="lbl">Months Covered</span>
        <span class="val">{{ $data['summary']['month_count'] }}</span>
      </div>
      <div class="totals-row">
        <span class="lbl">Monthly Average</span>
        <span class="val">GHS {{ number_format($data['summary']['monthly_average'], 2) }}</span>
      </div>
      <div class="totals-row grand">
        <span class="lbl">Grand Total</span>
        <span class="val">GHS {{ number_format($data['summary']['grand_total'], 2) }}</span>
      </div>
    </div>
  </div>

  <div class="footer">
    {{ $branchName }} &middot; Generated by WIS-CMS &middot; {{ $generatedAt }}
  </div>
</body>
</html>
