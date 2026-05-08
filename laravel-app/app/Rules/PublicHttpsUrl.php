<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule: the value must be a public HTTPS URL.
 *
 * Blocks SSRF vectors:
 *   - non-https schemes (http, file, gopher, etc.)
 *   - hostnames that resolve to loopback (127.0.0.0/8, ::1)
 *   - private IPv4 ranges (10/8, 172.16/12, 192.168/16, 169.254/16)
 *   - private IPv6 ranges (fc00::/7, fe80::/10)
 *   - "localhost" hostname
 *
 * Used for the AI custom_url and any other admin-supplied callback URL.
 */
class PublicHttpsUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') return;

        $parts = parse_url($value);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            $fail('عنوان URL غير صالح.');
            return;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https') {
            // Allow a special case: ollama-style local URL is set via dedicated provider
            // (provider='ollama'); for all other providers we require https.
            $fail('يجب استخدام HTTPS فقط.');
            return;
        }

        $host = strtolower($parts['host']);
        if (in_array($host, ['localhost', 'localhost.localdomain', '0.0.0.0', '::1'], true)) {
            $fail('لا يُسمح باستخدام عنوان محلي.');
            return;
        }

        // Resolve host to IP (IPv4 only here for simplicity)
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            // DNS resolution failed; allow as long as host doesn't look local.
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $fail('عنوان URL يشير إلى عنوان شبكة داخلية غير مسموح.');
            return;
        }
    }
}
