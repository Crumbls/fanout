<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Validators;

use Crumbls\Fanout\Contracts\SignatureValidator;

/**
 * Stripe-Signature header: `t=<unix>,v1=<hash>,v1=<hash>...`
 * https://stripe.com/docs/webhooks#verify-manually
 *
 * Profile config keys:
 *   - secret      (required)  webhook signing secret
 *   - tolerance   (optional)  max age in seconds (default 300)
 */
class StripeSignatureValidator implements SignatureValidator
{
    public function verify(string $rawBody, array $headers, array $config): bool
    {
        $secret = (string) ($config['secret'] ?? '');

        if ($secret === '') {
            return false;
        }

        $tolerance = (int) ($config['tolerance'] ?? 300);
        $header = $this->headerValue($headers, 'stripe-signature');

        if ($header === null) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $kv = explode('=', $part, 2);

            if (count($kv) !== 2) {
                continue;
            }

            [$k, $v] = $kv;

            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    protected function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }
}
