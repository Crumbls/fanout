<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Contracts;

interface SignatureValidator
{
    /**
     * Verify the signature carried on an inbound webhook request.
     *
     * @param  string                $rawBody  Raw request body, byte-for-byte.
     * @param  array<string, mixed>  $headers  Lower-cased header bag (single value or list per header).
     * @param  array<string, mixed>  $config   The full profile config — secret, signature_header, tolerance, etc.
     */
    public function verify(string $rawBody, array $headers, array $config): bool;
}
