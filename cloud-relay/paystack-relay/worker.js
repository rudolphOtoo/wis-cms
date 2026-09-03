// Paystack → mNotify Cloud Relay (off-grid SMS receipt for WIS-CMS).
//
// Deployed as a Cloudflare Worker, this tiny endpoint receives Paystack's
// charge.success webhooks while the church desktop PC is powered off and
// delivers the instant receipt SMS straight from the cloud via mNotify —
// no local runtime required.
//
// Flow:
//   member pays MoMo link  →  Paystack  →  POST /api/webhooks/paystack  (this worker)
//                                          ├─ verify HMAC SHA512 signature
//                                          ├─ POST https://api.mnotify.com/api/sms/quick
//                                          │     → instant receipt SMS to the payer
//                                          └─ (optional) forward raw webhook to the
//                                                church PC's /api/webhooks/payments/paystack
//                                                so local state also updates when online
//
// Always responds 200 (even on mNotify failure) so Paystack stops retrying;
// the reconciliation command on the PC is the durable backstop (see
// payments:reconcile-paystack) and flags such payments sms_pending.
//
// Required Worker secrets (wrangler secret put ...):
//   PAYSTACK_WEBHOOK_SECRET   — HMAC secret from Paystack dashboard
//   MNOTIFY_API_KEY           — mNotify API key
// Optional plain vars in wrangler.toml:
//   MNOTIFY_SENDER_ID         — registered mNotify sender (default "WIS")
//   MNOTIFY_BASE_URL          — default https://api.mnotify.com/api
//   PAYSTACK_FORWARD_URL      — e.g. https://giving.example.org/api/webhooks/payments/paystack
//                               (best-effort; skipped when unset)

export default {
	async fetch(request, env, ctx) {
		if (request.method !== 'POST') {
			return json({ message: 'Method not allowed' }, 405);
		}

		const rawBody = await request.text();

		// 1. Verify Paystack signature (HMAC SHA512 over the raw body).
		const signature = request.headers.get('X-Paystack-Signature') || '';
		const expected = await crypto.subtle.importKey(
			'raw',
			new TextEncoder().encode(env.PAYSTACK_WEBHOOK_SECRET || ''),
			{ name: 'HMAC', hash: 'SHA-512' },
			false,
			['sign'],
		);
		const signed = await crypto.subtle.sign('HMAC', expected, new TextEncoder().encode(rawBody));
		const computed = toHex(new Uint8Array(signed));

		if (!timingSafeEqual(computed, signature)) {
			return json({ message: 'Invalid signature' }, 403);
		}

		let event;
		try {
			event = JSON.parse(rawBody);
		} catch {
			return json({ message: 'Invalid JSON' }, 400);
		}

		// Only completed charges matter.
		if (event.event !== 'charge.success' || event.data?.status !== 'success') {
			return json({ message: 'Event ignored' }, 200);
		}

		// 2. Deliver the instant receipt SMS via mNotify (best-effort).
		const sms = buildReceiptSms(event.data);
		if (sms.recipient) {
			ctx.waitUntil(dispatchSms(sms, env));
		} else {
			console.warn('paystack-relay: no recipient phone found, receipt SMS skipped', {
				reference: event.data?.reference,
			});
		}

		// 3. Optionally relay the payload to the church PC's webhook so local
		// state (payments + ledger) also updates when the desktop is online.
		if (env.PAYSTACK_FORWARD_URL) {
			ctx.waitUntil(forwardWebhook(env, rawBody, signature));
		}

		return json({ message: 'Processed' }, 200);
	},
};

function buildReceiptSms(data) {
	const metadata = data.metadata || {};
	const amount = (data.amount || 0) / 100;
	const paymentType = metadata.payment_type || 'offering';
	const reference = data.reference || '';

	const recipient =
		metadata.phone || metadata.phonenumber || metadata.momo_number ||
		data.customer?.phone || data.authorization?.mobile_money?.phone ||
		null;

	const paidAt = data.paid_at ? new Date(data.paid_at) : new Date();
	const dateTime = paidAt.toLocaleString('en-GB', {
		day: '2-digit', month: 'short', year: 'numeric',
		hour: '2-digit', minute: '2-digit',
	});

	const message = `Thank you! We received your ${paymentType} of ${data.currency || 'GHS'} ${amount.toFixed(2)} on ${dateTime}. Ref: ${reference}.`;

	return { recipient, senderDefault: 'WIS', message };
}

async function dispatchSms(sms, env) {
	const base = (env.MNOTIFY_BASE_URL || 'https://api.mnotify.com/api').replace(/\/$/, '');
	const key = env.MNOTIFY_API_KEY;

	if (!key) {
		console.warn('paystack-relay: MNOTIFY_API_KEY not configured, receipt SMS dropped');
		return;
	}

	try {
		const response = await fetch(`${base}/sms/quick?key=${encodeURIComponent(key)}`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				recipient: [sms.recipient],
				sender: env.MNOTIFY_SENDER_ID || sms.senderDefault,
				message: sms.message,
				is_schedule: false,
				schedule_date: '',
			}),
		});

		if (!response.ok) {
			console.error('paystack-relay: mNotify rejected receipt SMS', {
				status: response.status,
				body: await response.text(),
				recipient: sms.recipient,
			});
		}
	} catch (err) {
		// Never let a relay failure throw — the PC reconciliation poll is
		// the durable fallback and will flag this payment sms_pending.
		console.error('paystack-relay: mNotify unreachable', err.message);
	}
}

async function forwardWebhook(env, rawBody, signature) {
	try {
		await fetch(env.PAYSTACK_FORWARD_URL, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-Paystack-Signature': signature,
			},
			body: rawBody,
		});
	} catch (err) {
		console.warn('paystack-relay: forward to church PC failed', err.message);
	}
}

// Constant-time hex comparison (defensive; both are hex ASCII).
function timingSafeEqual(a, b) {
	if (a.length !== b.length) return false;
	let diff = 0;
	for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
	return diff === 0;
}

function toHex(bytes) {
	return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function json(payload, status) {
	return new Response(JSON.stringify(payload), {
		status,
		headers: { 'Content-Type': 'application/json' },
	});
}