<?php

namespace App\News\Source;

/**
 * Thrown when a user-supplied URL fails the SSRF guard (non-http(s) scheme, or
 * a host that resolves to a private/loopback/link-local address). Distinct from
 * a transport error so callers can surface "this URL isn't allowed" specifically.
 */
final class UnsafeUrlException extends \RuntimeException
{
}
