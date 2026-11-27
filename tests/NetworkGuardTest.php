<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The suite's network guard (tests/TestCase.php) trips on a deliberate real
// request, whichever client makes it. This file sets no guard of its own: the
// base TestCase is the guard under test. The target is never contacted: the
// Laravel client refuses before any transfer, and the others are sent to the
// closed local proxy.

const NETWORK_GUARD_TARGET = 'https://api.anthropic.com/v1/models';

it('refuses a real request through the Laravel HTTP client', function (): void {
    expect(fn () => Http::get(NETWORK_GUARD_TARGET))->toThrow(StrayRequestException::class);
});

it('sends a raw Guzzle client to the closed local proxy', function (): void {
    $failure = null;
    try {
        (new Client(['timeout' => 5, 'connect_timeout' => 5]))->get(NETWORK_GUARD_TARGET);
    } catch (ConnectException $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(ConnectException::class)
        ->and($failure?->getMessage())->toContain('127.0.0.1');
});

it('sends raw curl to the closed local proxy', function (): void {
    $handle = curl_init(NETWORK_GUARD_TARGET);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 5]);
    $body = curl_exec($handle);
    $error = curl_error($handle);

    expect($body)->toBeFalse()
        ->and($error)->toContain('127.0.0.1');
});

it('points every proxy variable at the closed port with no exemptions', function (): void {
    foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'] as $name) {
        expect(getenv($name))->toBe(TestCase::BLOCKED_PROXY, $name);
    }
    foreach (['NO_PROXY', 'no_proxy'] as $name) {
        expect((string) getenv($name))->toBe('', $name);
    }
});
