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
  .meta-row { width: 100%; }
  .meta-col { display: inline-block; width: 48%; vertical-align: top; }
  .meta-label { color: #9ca3af; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
  .meta-value { font-size: 13px; font-weight: bold; color: #0D1F3C; margin-bottom: 10px; }
  .content { padding: 24px 40px; }
  .section-title { font-size: 14px; font-weight: bold; color: #0D1F3C; margin-bottom: 12px; border-bottom: 1px solid #E5E9F2; padding-bottom: 6px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  th { text-align: left; padding: 8px 10px; background: #f9fafb; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; border-bottom: 1px solid #E5E9F2; }
  td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; font-size: 11px; }
  .amount { text-align: right; font-weight: bold; }
  .summary-table td { font-size: 12px; }
  .total-row { background: #0D1F3C; color: white; }
  .total-row td { font-size: 14px; font-weight: bold; padding: 12px 10px; }
  .total-row .gold { color: #C9A84C; }
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
        <h1>Annual Giving Statement</h1>
        <p>Methodist Church Ghana &middot; Year {{ $year }}</p>
      </div>
    </div>
  </div>

  <div class="meta">
    <div class="meta-row">
      <div class="meta-col">
        <div class="meta-label">Member Name</div>
        <div class="meta-value">{{ $member->full_name }}</div>
        <div class="meta-label">Member Number</div>
        <div class="meta-value">{{ $member->member_number }}</div>
      </div>
      <div class="meta-col">
        <div class="meta-label">Statement Period</div>
        <div class="meta-value">Jan 1 &ndash; Dec 31, {{ $year }}</div>
        <div class="meta-label">Generated</div>
        <div class="meta-value">{{ $generatedAt }}</div>
      </div>
    </div>
  </div>

  <div class="content">
    <div class="section-title">Summary by Category</div>
    <table class="summary-table">
      <thead>
        <tr><th>Category</th><th class="amount">Total (GHS)</th></tr>
      </thead>
      <tbody>
        @foreach ($byCategory as $cat)
          <tr>
            <td>{{ $cat['category'] }}</td>
            <td class="amount">{{ number_format($cat['total'], 2) }}</td>
          </tr>
        @endforeach
        <tr class="total-row">
          <td>TOTAL GIVING FOR {{ $year }}</td>
          <td class="amount gold">GHS {{ number_format($total, 2) }}</td>
        </tr>
      </tbody>
    </table>

    <div class="section-title">Transaction Detail</div>
    <table>
      <thead>
        <tr><th>Date</th><th>Category</th><th>Reference</th><th class="amount">Amount (GHS)</th></tr>
      </thead>
      <tbody>
        @forelse ($transactions as $t)
          <tr>
            <td>{{ $t->transaction_date->format('d M Y') }}</td>
            <td>{{ $t->category?->name ?? 'Uncategorised' }}</td>
            <td>{{ $t->reference ?? '—' }}</td>
            <td class="amount">{{ number_format($t->amount, 2) }}</td>
          </tr>
        @empty
          <tr><td colspan="4" style="text-align:center; color:#9ca3af; padding:20px;">No giving recorded for {{ $year }}.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="footer">
    <p>This statement is provided for your records by {{ $branchName }}.</p>
    <p>Thank you for your faithful giving. &mdash; "Each of you should give what you have decided in your heart to give."</p>
  </div>
</body>
</html>
