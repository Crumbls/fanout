<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Validators;

use Crumbls\Fanout\Contracts\SignatureValidator;

/**
 * Generic HMAC-SHA256 validator. Header name + accepted signature prefix
 * are configurable, so the same class covers Spatie's webhook-client default
 * (`Signature` header, raw hex), GitHub-style (`sha256=...`) and most
 * homegrown webhook formats.
 *
 * Profile config keys:
 *   - secret             (required)
 *   - signature_header   (default: 'X-Signature')
 *   - signature_prefix   (default: ''; e.g. 'sha256=' for GitHub)
 */
class HmacSha256SignatureValidator implements SignatureValidator
{
    public function verify(string $rawBody, array $headers, array $config): bool
    {
        $secret = (string) ($config['secret'] ?? '');

        if ($secret === '') {
            return false;
        }

        $headerName = strtolower((string) ($config['signature_header'] ?? 'X-Signature'));
        $prefix     = (string) ($config['signature_prefix'] ?? '');

        $provided = $this->headerValue($headers, $headerName);

        if ($provided === null) {
            return false;
        }

        if ($prefix !== '' && str_starts_with($provided, $prefix)) {
            $provided = substr($provided, strlen($prefix));
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    protected function headerValue(array $headers, string $name): ?string
    {
        $normalised = [];

        foreach ($headers as $key => $value) {
            $normalised[strtolower((string) $key)] = is_array($value) ? ($value[0] ?? null) : $value;
        }

        return $normalised[$name] ?? null;
    }
}
