<?php

declare(strict_types=1);

namespace App\Http\Requests\Biometric;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authenticates + validates a biometric scan payload.
 *
 * The endpoint is public (no Sanctum) and guarded by the shared fleet
 * secret, mirroring the member-intake webhook. The secret may arrive in
 * the X-Biometric-Secret header (preferred) or the `secret` body field.
 */
class RecordBiometricScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = (string) config('services.biometric.webhook_secret', '');

        if ($expected === '') {
            return false;
        }

        $provided = (string) ($this->header('X-Biometric-Secret') ?? $this->input('secret', ''));

        return hash_equals($expected, $provided);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'device_serial' => ['required', 'string', 'max:100'],
            'device_user_id' => ['required', 'string', 'max:100'],
            'verification_mode' => ['nullable', 'string', 'in:fingerprint,card,face,password'],
            'event_id' => ['nullable', 'string', 'max:191'],
            'scanned_at' => ['nullable', 'date'],
        ];
    }
}
