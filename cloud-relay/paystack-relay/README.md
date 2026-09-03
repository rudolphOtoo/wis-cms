# Paystack → mNotify Cloud Relay

Instant off-grid SMS receipt for mobile money gifts made while the WIS-CMS
church desktop PC is powered off.

## Why

Paystack sends `charge.success` webhooks the moment a mobile money payment
completes. When the church PC is off, its local Laravel instance cannot
receive those webhooks — so the member would get no instant receipt. This
Cloudflare Worker receives the webhook **in the cloud**, verifies Paystack's
HMAC signature, and calls mNotify's API (`POST /api/sms/quick`) directly to
deliver the receipt SMS immediately.

The worker never stores data and always answers `200` fast so Paystack stops
retrying.

## Components

- `worker.js` — the Worker (signature check, receipt builder, mNotify send,
  optional best-effort forward to the church PC).
- `wrangler.toml` — Cloudflare deployment config.

## Deploy

```bash
cd cloud-relay/paystack-relay

wrangler login
wrangler secret put PAYSTACK_WEBHOOK_SECRET   # HMAC secret from Paystack dashboard
wrangler secret put MNOTIFY_API_KEY           # mNotify API key
wrangler deploy
```

Optional vars (edit `wrangler.toml`):

| Var                 | Purpose                                                            |
|---------------------|--------------------------------------------------------------------|
| `MNOTIFY_SENDER_ID` | Registered mNotify sender name (default `WIS`).                    |
| `MNOTIFY_BASE_URL`  | Defaults to `https://api.mnotify.com/api`.                         |
| `PAYSTACK_FORWARD_URL` | If set, the worker also forwards the verified webhook to this URL (e.g. the church PC's `https://giving.example.org/api/webhooks/payments/paystack`) so local state updates too when online. Best-effort — failures are logged, never fatal. |

Then in Paystack (Settings → Developer → Webhooks) set the callback URL to
the worker's `*/webhooks/paystack` route, e.g.
`https://paystack-relay.your-subdomain.workers.dev/webhooks/paystack` and
leave the Paystack webhook secret exactly as configured on the Worker.

## Receipt message

```
Thank you! We received your tithe of GHS 50.00 on 29 Aug 2026, 14:05. Ref: 9f3a...x1.
```

Recipient comes from `metadata.phone` / `metadata.phonenumber` /
`metadata.momo_number`, then `customer.phone`, then `authorization.mobile_money.phone`.

## Fallback when the relay is offline

If the worker can't reach mNotify, the payment is still flagged `sms_pending`
when the `payments:reconcile-paystack` command back-fills it during the next
PC boot, and `MnotifySmsService::sendReceipt()` flushes the receipt then.
This is the Phase 3 (Option B) queued-SMS fallback — receipts are never lost,
only delayed until the next sync.