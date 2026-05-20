<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<style>
  body { font-family: 'Helvetica Neue', Arial, sans-serif; background:#F8F9FC; margin:0; padding:24px; color:#1f2937; }
  .wrap { max-width:600px; margin:0 auto; background:white; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
  .header { background:#0D1F3C; padding:32px 24px; color:white; text-align:center; }
  .badge { display:inline-block; padding:4px 12px; background:#C9A84C; color:#0D1F3C; font-size:12px; font-weight:bold; border-radius:999px; letter-spacing:0.5px; }
  .header h1 { font-family:'Playfair Display', Georgia, serif; font-size:24px; margin:16px 0 4px; }
  .header p { color:rgba(255,255,255,0.6); font-size:13px; margin:0; }
  .body { padding:32px 24px; line-height:1.6; }
  .body p { margin:0 0 16px; font-size:15px; color:#374151; }
  .greeting { color:#0D1F3C; font-weight:600; font-size:16px; }
  .footer { padding:20px 24px; background:#F8F9FC; border-top:1px solid #E5E9F2; text-align:center; }
  .footer p { color:#9ca3af; font-size:12px; margin:4px 0; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <span class="badge">{{ $branchName }}</span>
      <h1>{{ $subjectLine }}</h1>
      <p>Methodist Church Ghana</p>
    </div>
    <div class="body">
      <p class="greeting">Dear {{ $recipientName }},</p>
      <div style="white-space:pre-wrap;">{!! nl2br(e($messageBody)) !!}</div>
      <p style="margin-top:24px;">Grace and peace,<br><strong style="color:#0D1F3C;">{{ $branchName }} Leadership</strong></p>
    </div>
    <div class="footer">
      <p>This is an official communication from {{ $branchName }}.</p>
      <p>If you received this in error, please contact your church administrator.</p>
    </div>
  </div>
</body>
</html>
