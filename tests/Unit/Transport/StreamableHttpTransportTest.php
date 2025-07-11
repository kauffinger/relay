<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\TransportException;
use Prism\Relay\Transport\StreamableHttpTransport;

it('can be instantiated with config', function (): void {
    $config = ['url' => 'http://example.com/api', 'timeout' => 30];
    $transport = new StreamableHttpTransport($config);

    expect($transport)->toBeInstanceOf(StreamableHttpTransport::class);
});

it('sends requests with proper headers including accept for SSE', function (): void {
    Http::fake(function ($request) {
        expect($request->headers())->toHaveKey('Accept');
        expect($request->header('Accept')[0])->toBe('application/json, text/event-stream');

        return Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['status' => 'success'],
        ]);
    });

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    $result = $transport->sendRequest('test.method', ['param' => 'value']);

    expect($result)->toBe(['status' => 'success']);
});

it('includes API key when configured', function (): void {
    Http::fake(function ($request) {
        expect($request->headers())->toHaveKey('Authorization');
        expect($request->header('Authorization')[0])->toBe('Bearer test-api-key');

        return Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['authenticated' => true],
        ]);
    });

    $config = ['url' => 'http://example.com/api', 'api_key' => 'test-api-key'];
    $transport = new StreamableHttpTransport($config);

    $result = $transport->sendRequest('test.method');

    expect($result)->toBe(['authenticated' => true]);
});

it('handles SSE responses correctly', function (): void {
    $sseResponse = "event: message\n";
    $sseResponse .= "data: {\"jsonrpc\":\"2.0\",\"id\":\"1\",\"result\":{\"streaming\":true}}\n\n";

    Http::fake([
        'http://example.com/api' => Http::response($sseResponse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    $result = $transport->sendRequest('test.stream');

    expect($result)->toBe(['streaming' => true]);
});

it('handles multiple SSE events and finds matching response', function (): void {
    $sseResponse = "event: notification\n";
    $sseResponse .= "data: {\"jsonrpc\":\"2.0\",\"method\":\"log\",\"params\":{\"message\":\"Processing...\"}}\n\n";
    $sseResponse .= "event: message\n";
    $sseResponse .= "data: {\"jsonrpc\":\"2.0\",\"id\":\"1\",\"result\":{\"found\":true}}\n\n";

    Http::fake([
        'http://example.com/api' => Http::response($sseResponse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    $result = $transport->sendRequest('test.request');

    expect($result)->toBe(['found' => true]);
});

it('handles JSON responses correctly', function (): void {
    Http::fake([
        'http://example.com/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['json' => true],
        ], 200, ['Content-Type' => 'application/json']),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    $result = $transport->sendRequest('test.json');

    expect($result)->toBe(['json' => true]);
});

it('manages session ID from response headers', function (): void {
    // Track whether session ID is included in requests
    $requestCount = 0;

    Http::fake(function ($request) use (&$requestCount) {
        $requestCount++;

        if ($requestCount === 1) {
            // First request should not have session ID
            expect($request->headers())->not->toHaveKey('Mcp-Session-Id');

            return Http::response(
                ['jsonrpc' => '2.0', 'id' => '1', 'result' => ['connected' => true]],
                200,
                ['Mcp-Session-Id' => 'session-123']
            );
        }

        // Second request should have session ID
        expect($request->headers())->toHaveKey('Mcp-Session-Id');
        expect($request->header('Mcp-Session-Id')[0])->toBe('session-123');

        return Http::response(
            ['jsonrpc' => '2.0', 'id' => '2', 'result' => ['session_active' => true]],
            200
        );
    });

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    // First request sets session ID
    $transport->sendRequest('connect');

    // Second request should include session ID
    $result = $transport->sendRequest('test.with.session');

    expect($result)->toBe(['session_active' => true]);
});

it('throws exception on HTTP error', function (): void {
    Http::fake([
        'http://example.com/api' => Http::response(null, 500),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    expect(fn (): array => $transport->sendRequest('test.method'))
        ->toThrow(TransportException::class, 'HTTP request failed with status code: 500');
});

it('throws exception on invalid JSON-RPC response', function (): void {
    Http::fake([
        'http://example.com/api' => Http::response([
            'invalid' => 'response',
        ]),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    expect(fn (): array => $transport->sendRequest('test.method'))
        ->toThrow(TransportException::class, 'Invalid JSON-RPC 2.0 response received');
});

it('handles JSON-RPC error responses', function (): void {
    Http::fake([
        'http://example.com/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'error' => [
                'code' => -32600,
                'message' => 'Invalid Request',
                'data' => ['detail' => 'Missing method parameter'],
            ],
        ]),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    expect(fn (): array => $transport->sendRequest('test.method'))
        ->toThrow(
            TransportException::class,
            'JSON-RPC error: Invalid Request (code: -32600) Details: {"detail":"Missing method parameter"}'
        );
});

it('throws exception when no matching response in SSE stream', function (): void {
    $sseResponse = "event: notification\n";
    $sseResponse .= "data: {\"jsonrpc\":\"2.0\",\"method\":\"log\",\"params\":{\"message\":\"No matching response\"}}\n\n";

    Http::fake([
        'http://example.com/api' => Http::response($sseResponse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    expect(fn (): array => $transport->sendRequest('test.method'))
        ->toThrow(TransportException::class, 'No response found for request ID: 1');
});

it('parses SSE fields correctly', function (): void {
    $sseResponse = "id: msg-001\n";
    $sseResponse .= "event: message\n";
    $sseResponse .= "retry: 1000\n";
    $sseResponse .= "data: {\"jsonrpc\":\"2.0\",\"id\":\"1\",\"result\":{\"parsed\":true}}\n\n";

    Http::fake([
        'http://example.com/api' => Http::response($sseResponse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    $result = $transport->sendRequest('test.sse.fields');

    expect($result)->toBe(['parsed' => true]);
});

it('respects configured timeout', function (): void {
    // Skip this test as Laravel's HTTP client doesn't support timeouts in fake mode
    $this->markTestSkipped('Laravel HTTP client does not support timeout testing in fake mode');
});

it('clears session ID on close', function (): void {
    $requestCount = 0;

    Http::fake(function ($request) use (&$requestCount) {
        $requestCount++;

        if ($requestCount === 1) {
            return Http::response(
                ['jsonrpc' => '2.0', 'id' => '1', 'result' => []],
                200,
                ['Mcp-Session-Id' => 'session-456']
            );
        }

        // After close(), second request should not have session ID
        expect($request->headers())->not->toHaveKey('Mcp-Session-Id');

        return Http::response(
            ['jsonrpc' => '2.0', 'id' => '2', 'result' => []],
            200
        );
    });

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    $transport->sendRequest('first');
    $transport->close();
    $transport->sendRequest('second');
});

it('generates session ID when configured', function (): void {
    $requestCount = 0;

    Http::fake(function ($request) use (&$requestCount) {
        $requestCount++;

        expect($request->headers())->toHaveKey('Mcp-Session-Id');
        $sessionId = $request->header('Mcp-Session-Id')[0];
        expect($sessionId)->toBeString();
        expect(strlen($sessionId))->toBeGreaterThan(20);

        if ($requestCount === 1) {
            // Initialize request
            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $request->data()['id'],
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass,
                ],
            ]);
        }

        if ($requestCount === 2) {
            // Initialized notification
            return Http::response('', 200);
        }

        // Regular request
        return Http::response(['jsonrpc' => '2.0', 'id' => $request->data()['id'], 'result' => []]);
    });

    $config = [
        'url' => 'http://example.com/api',
        'send_initialize' => false,
    ];
    $transport = new StreamableHttpTransport($config);
    $transport->start();

    $transport->sendRequest('test');
});

it('handles session ID requirement error properly', function (): void {
    Http::fake([
        'http://example.com/api' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'error' => [
                'code' => -32600,
                'message' => 'Mcp-Session-Id header required for POST requests',
            ],
        ]),
    ]);

    $config = ['url' => 'http://example.com/api'];
    $transport = new StreamableHttpTransport($config);

    expect(fn (): array => $transport->sendRequest('test'))
        ->toThrow(TransportException::class, 'Mcp-Session-Id header required for POST requests');
});

it('sends MCP initialize request on start', function (): void {
    $requestCount = 0;

    Http::fake(function ($request) use (&$requestCount) {
        $requestCount++;
        $payload = $request->data();

        if ($requestCount === 1) {
            // First request should be initialize (without session ID)
            expect($payload['method'])->toBe('initialize');
            expect($payload['params']['protocolVersion'])->toBe('2024-11-05');
            expect($payload['params']['clientInfo']['name'])->toBe('prism-relay');
            expect($request->headers())->not->toHaveKey('Mcp-Session-Id');

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass,
                    'serverInfo' => [
                        'name' => 'test-server',
                        'version' => '1.0.0',
                    ],
                ],
            ], 200, ['Mcp-Session-Id' => 'server-generated-session-123']);
        }

        if ($requestCount === 2) {
            // Second request should be initialized notification (with session ID from server)
            expect($payload['method'])->toBe('notifications/initialized');
            expect($payload)->not->toHaveKey('id'); // Notifications don't have IDs
            expect($request->header('Mcp-Session-Id')[0])->toBe('server-generated-session-123');

            return Http::response('', 200); // Empty response for notification
        }

        return Http::response(['error' => 'Unexpected request']);
    });

    $config = [
        'url' => 'http://example.com/api',
        'send_initialize' => true, // Explicitly enable initialization
    ];
    $transport = new StreamableHttpTransport($config);

    $transport->start();

    expect($requestCount)->toBe(2);
});
