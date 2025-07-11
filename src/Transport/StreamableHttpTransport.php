<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\TransportException;

class StreamableHttpTransport implements Transport
{
    protected int $requestId = 0;

    protected ?string $sessionId = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config
    ) {}

    #[\Override]
    public function start(): void
    {
        if ($this->shouldSendInitializeRequest()) {
            $this->sendInitializeRequest();
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    #[\Override]
    public function sendRequest(string $method, array $params = []): array
    {
        $this->requestId++;
        $requestPayload = $this->createRequestPayload($method, $params);

        try {
            $response = $this->sendHttpRequest($requestPayload);

            // Update session ID if present in response
            $this->updateSessionIdFromResponse($response);

            // Handle response based on content type
            $contentType = $response->header('Content-Type') ?? '';

            if (str_contains($contentType, 'text/event-stream')) {
                return $this->processStreamingResponse($response);
            }

            return $this->processJsonResponse($response);
        } catch (\Throwable $e) {
            if ($e instanceof TransportException) {
                throw $e;
            }

            throw new TransportException(
                "Failed to send request to MCP server: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    #[\Override]
    public function close(): void
    {
        // Optionally clear session ID
        $this->sessionId = null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function createRequestPayload(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendHttpRequest(array $payload): Response
    {
        $request = Http::timeout($this->getTimeout())
            ->withHeaders($this->getHeaders())
            ->when(
                $this->hasApiKey(),
                fn ($http) => $http->withToken($this->getApiKey())
            );

        return $request->post($this->getServerUrl(), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendHttpRequestWithoutSessionId(array $payload): Response
    {
        $request = Http::timeout($this->getTimeout())
            ->withHeaders($this->getHeaders(skipSessionId: true))
            ->when(
                $this->hasApiKey(),
                fn ($http) => $http->withToken($this->getApiKey())
            );

        return $request->post($this->getServerUrl(), $payload);
    }

    /**
     * @return array<string, string>
     */
    protected function getHeaders(bool $skipSessionId = false): array
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
        ];

        if (! $skipSessionId && $this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return $headers;
    }

    protected function updateSessionIdFromResponse(Response $response): void
    {
        $sessionId = $response->header('Mcp-Session-Id');
        if ($sessionId !== '') {
            $this->sessionId = $sessionId;
        }
    }

    /**
     * Process a Server-Sent Events (SSE) streaming response
     *
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function processStreamingResponse(Response $response): array
    {
        $this->validateHttpResponse($response);

        $body = $response->body();
        $events = $this->parseServerSentEvents($body);

        // Find the response with matching ID
        $result = null;
        foreach ($events as $event) {
            if (isset($event['data'])) {
                $data = json_decode($event['data'], true);
                if ($data && isset($data['id']) && (string) $data['id'] === (string) $this->requestId) {
                    $result = $data;
                    break;
                }
            }
        }

        if (! $result) {
            throw new TransportException(
                'No response found for request ID: '.$this->requestId
            );
        }

        $this->validateJsonRpcResponse($result);

        if (isset($result['error'])) {
            $this->handleJsonRpcError($result['error']);
        }

        return $result['result'] ?? [];
    }

    /**
     * Parse Server-Sent Events from response body
     *
     * @return array<int, array<string, string>>
     */
    protected function parseServerSentEvents(string $body): array
    {
        $events = [];
        $currentEvent = [];
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            $line = trim($line);

            // Empty line indicates end of event
            if ($line === '') {
                if ($currentEvent !== []) {
                    $events[] = $currentEvent;
                    $currentEvent = [];
                }

                continue;
            }

            // Parse field
            if (str_contains($line, ':')) {
                [$field, $value] = explode(':', $line, 2);
                $field = trim($field);
                $value = ltrim($value); // Remove single leading space if present

                if (in_array($field, ['event', 'data', 'id', 'retry'])) {
                    $currentEvent[$field] = $value;
                }
            }
        }

        // Add last event if not empty
        if ($currentEvent !== []) {
            $events[] = $currentEvent;
        }

        return $events;
    }

    /**
     * Process a regular JSON response
     *
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function processJsonResponse(Response $response): array
    {
        $this->validateHttpResponse($response);
        $jsonResponse = $response->json();
        $this->validateJsonRpcResponse($jsonResponse);

        if (isset($jsonResponse['error'])) {
            $this->handleJsonRpcError($jsonResponse['error']);
        }

        return $jsonResponse['result'] ?? [];
    }

    protected function getTimeout(): int
    {
        return $this->config['timeout'] ?? 30;
    }

    protected function hasApiKey(): bool
    {
        return isset($this->config['api_key']) && $this->config['api_key'] !== null;
    }

    protected function getApiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    protected function getServerUrl(): string
    {
        return $this->config['url'];
    }

    /**
     * @throws TransportException
     */
    protected function validateHttpResponse(Response $response): void
    {
        if ($response->failed()) {
            throw new TransportException(
                "HTTP request failed with status code: {$response->status()}"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $jsonResponse
     *
     * @throws TransportException
     */
    protected function validateJsonRpcResponse(array $jsonResponse): void
    {
        if (! isset($jsonResponse['jsonrpc']) ||
            $jsonResponse['jsonrpc'] !== '2.0' ||
            ! isset($jsonResponse['id']) ||
            (string) $jsonResponse['id'] !== (string) $this->requestId
        ) {
            throw new TransportException(
                'Invalid JSON-RPC 2.0 response received'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $error
     *
     * @throws TransportException
     */
    protected function handleJsonRpcError(array $error): void
    {
        $errorMessage = $error['message'] ?? 'Unknown error';
        $errorCode = $error['code'] ?? -1;
        $errorData = isset($error['data']) ? json_encode($error['data']) : '';

        $detailsSuffix = '';
        if (! ($errorData === '' || $errorData === '0' || $errorData === false) && $errorData !== '0' && $errorData !== 'false') {
            $detailsSuffix = " Details: {$errorData}";
        }

        throw new TransportException(
            "JSON-RPC error: {$errorMessage} (code: {$errorCode}){$detailsSuffix}"
        );
    }

    /**
     * Check if we should send initialize request on start
     */
    protected function shouldSendInitializeRequest(): bool
    {
        return $this->config['send_initialize'] ?? true;
    }

    /**
     * Send MCP initialize request and handle initialization sequence
     *
     * @throws TransportException
     */
    protected function sendInitializeRequest(): void
    {
        try {
            // Send initialize request (without session ID)
            $this->requestId++;
            $initializePayload = [
                'jsonrpc' => '2.0',
                'id' => (string) $this->requestId,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass,
                    'clientInfo' => [
                        'name' => 'prism-relay',
                        'version' => '1.0.0',
                    ],
                ],
            ];

            $response = $this->sendHttpRequestWithoutSessionId($initializePayload);
            $this->updateSessionIdFromResponse($response);

            // Process initialize response
            $contentType = $response->header('Content-Type') ?? '';
            if (str_contains($contentType, 'text/event-stream')) {
                $this->processStreamingResponse($response);
            } else {
                $this->processJsonResponse($response);
            }

            // Send initialized notification
            $this->sendInitializedNotification();
        } catch (\Throwable $e) {
            throw new TransportException(
                "Failed to initialize MCP session: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Send initialized notification to complete the MCP handshake
     *
     * @throws TransportException
     */
    protected function sendInitializedNotification(): void
    {
        try {
            $notificationPayload = [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ];

            $response = $this->sendHttpRequest($notificationPayload);
            $this->updateSessionIdFromResponse($response);

            // Process notification response (may be empty)
            if ($response->successful()) {
                $body = trim($response->body());
                if ($body !== '') {
                    $contentType = $response->header('Content-Type') ?? '';
                    if (str_contains($contentType, 'text/event-stream')) {
                        $this->processStreamingResponse($response);
                    } else {
                        $this->processJsonResponse($response);
                    }
                }
            }
        } catch (\Throwable $e) {
            throw new TransportException(
                "Failed to send initialized notification: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}
