<?php

/** Maximum AMF body size, including when a request omits Content-Length. */
function amfConfiguredBodyLimit(): int
{
    $configured = (int) (getenv('AMF_MAX_REQUEST_BYTES') ?: 0);

    return $configured > 0 && $configured < PHP_INT_MAX
        ? $configured
        : 16 * 1024 * 1024;
}

/** Read at most one byte beyond the limit to detect an oversized body. */
function amfReadBoundedRequestBody($stream, int $maxBytes, ?string $declaredLength = null): string
{
    if (!is_resource($stream) || $maxBytes < 1 || $maxBytes >= PHP_INT_MAX) {
        throw new InvalidArgumentException('Invalid AMF input stream or body limit.');
    }

    if ($declaredLength !== null && ctype_digit($declaredLength) && (int) $declaredLength > $maxBytes) {
        throw new LengthException('AMF request body is too large.');
    }

    $body = stream_get_contents($stream, $maxBytes + 1);
    if ($body === false) {
        throw new RuntimeException('Could not read AMF request body.');
    }
    if (strlen($body) > $maxBytes) {
        throw new LengthException('AMF request body is too large.');
    }

    return $body;
}
