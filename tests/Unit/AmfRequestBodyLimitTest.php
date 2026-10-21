<?php

require_once __DIR__.'/../../public/farmville/flashservices/amfphp/Helpers/request_body.php';

test('AMF body reader accepts a body at the byte limit', function (): void {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, '1234');
    rewind($stream);

    try {
        expect(amfReadBoundedRequestBody($stream, 4, '4'))->toBe('1234');
    } finally {
        fclose($stream);
    }
});

test('AMF body reader rejects an oversized declared length before reading', function (): void {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, '1234');
    rewind($stream);

    try {
        expect(fn () => amfReadBoundedRequestBody($stream, 4, '5'))
            ->toThrow(LengthException::class);
        expect(ftell($stream))->toBe(0);
    } finally {
        fclose($stream);
    }
});

test('AMF body reader rejects actual oversized bytes without a usable length', function (): void {
    foreach ([null, 'unknown', '4'] as $declaredLength) {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '12345');
        rewind($stream);

        try {
            expect(fn () => amfReadBoundedRequestBody($stream, 4, $declaredLength))
                ->toThrow(LengthException::class);
            expect(ftell($stream))->toBe(5);
        } finally {
            fclose($stream);
        }
    }
});
