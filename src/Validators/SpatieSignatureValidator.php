<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Validators;

/**
 * Compatible with spatie/laravel-webhook-client's default `Signature`
 * header — raw hex HMAC-SHA256 with no prefix. Header name can still be
 * overridden via `signature_header` if you've customised the Spatie setup.
 */
class SpatieSignatureValidator extends HmacSha256SignatureValidator
{
    public function verify(string $rawBody, array $headers, array $config): bool
    {
        $config['signature_header'] = $config['signature_header'] ?? 'Signature';
        $config['signature_prefix'] = '';

        return parent::verify($rawBody, $headers, $config);
    }
}
