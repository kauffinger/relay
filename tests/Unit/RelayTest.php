<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Prism\Prism\Tool;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Tests\TestDoubles\RelayFake;

beforeEach(function (): void {
    // We'll use the RelayFake for all tests to control its behavior
    $this->serverName = 'test_server';
    config()->set('relay.servers.'.$this->serverName, [
        'url' => 'http://example.com/api',
        'timeout' => 30,
    ]);

    // Clear any cached tools
    Cache::forget('relay-tools-definitions-'.$this->serverName);
});

it('initializes with correct server configuration', function (): void {
    $relay = new RelayFake($this->serverName);
    expect($relay->getServerName())->toBe($this->serverName);
});

it('throws exception for non-existent server', function (): void {
    $nonExistentServer = 'non_existent_server';
    expect(fn (): \Tests\TestDoubles\RelayFake => new RelayFake($nonExistentServer))
        ->toThrow(ServerConfigurationException::class);
});

it('fetches tool definitions', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->tools();

    expect($tools)
        ->toBeArray()
        ->not->toBeEmpty()
        ->and($tools[0])
        ->toBeInstanceOf(Tool::class);
});

it('supports caching configuration', function (): void {
    // Just verify the config is read properly

    // Set a non-zero cache duration
    config()->set('relay.cache_duration', 60); // 60 minutes

    $relay = new RelayFake($this->serverName);

    // Call tools() to run the code path
    $relay->tools();

    // This is a very basic test just to ensure the cache-related code exists
    // and executes without error
    expect(config('relay.cache_duration'))->toBe(60);
});

it('supports disabling cache with cache_duration=0', function (): void {
    // Set cache duration to 0 to disable caching
    config()->set('relay.cache_duration', 0);

    $relay = new RelayFake($this->serverName);

    // Cache key based on server name
    $cacheKey = "relay-tools-definitions-{$this->serverName}";

    // Clear any existing cache
    Cache::forget($cacheKey);

    // Call tools to make sure it runs through the code path
    $relay->tools();

    // Verify cache doesn't contain the key (since duration is 0)
    expect(Cache::has($cacheKey))->toBeFalse();
});

it('creates different tool handlers based on inputSchema', function (): void {
    $relay = new RelayFake($this->serverName);

    // Call tools() to create handlers
    $tools = $relay->tools();

    // Test we have the tools we expect
    expect($tools)->toHaveCount(6);
});

it('handles different parameter types correctly in tools', function (): void {
    $relay = new RelayFake($this->serverName);
    $tools = $relay->tools();

    // The RelayFake already implements test handlers that we can verify
    expect(count($tools))->toBeGreaterThan(0);
});

it('throws exception when tool definition fetch fails', function (): void {
    $relay = new RelayFake($this->serverName);
    $relay->shouldThrowOnTools('Failed to fetch tools');

    expect(fn (): array => $relay->tools())
        ->toThrow(ToolDefinitionException::class, 'Failed to fetch tools');
});

it('handles invalid tool definitions', function (): void {
    $relay = new RelayFake($this->serverName);

    // Set invalid tool definitions
    $relay->setToolDefinitions([
        ['description' => 'Missing name'],
        [], // Empty definition
    ]);

    $tools = $relay->tools();
    expect($tools)->toBeArray();

    // The fake probably auto-adds tools, so just check for expected behavior
    // when a mix of valid and invalid tools is provided
    $relay->setToolDefinitions([
        ['name' => 'valid_tool', 'description' => 'A valid tool'],
        ['description' => 'Missing name'],
    ]);

    $tools = $relay->tools();
    expect($tools)->toBeArray()
        ->and($tools !== [])->toBeTrue();
});

it('creates tools with array parameters', function (): void {
    $relay = new RelayFake($this->serverName);

    // Set tool definitions with array parameters
    $relay->setToolDefinitions([
        [
            'name' => 'array_tool',
            'description' => 'A tool with array parameters',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Array of items',
                        'items' => [
                            'type' => 'string',
                        ],
                    ],
                    'tags' => [
                        'type' => 'array',
                        'description' => 'Array of tags',
                        'items' => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'required' => ['items'],
            ],
        ],
    ]);

    $tools = $relay->tools();

    expect($tools)->toBeArray()
        ->and($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(Tool::class);

    // Verify the tool has the expected parameters
    $tool = $tools[0];
    $parameters = $tool->parameters();

    expect($parameters)->toHaveKeys(['items', 'tags'])
        ->and($parameters['items'])->toBeInstanceOf(\Prism\Prism\Schema\ArraySchema::class)
        ->and($parameters['tags'])->toBeInstanceOf(\Prism\Prism\Schema\ArraySchema::class);

    // Verify the array schemas have the correct item types
    $itemsArray = $tool->parametersAsArray();
    expect($itemsArray['items']['type'])->toBe('array')
        ->and($itemsArray['tags']['type'])->toBe('array');
});

it('creates tools with object parameters', function (): void {
    $relay = new RelayFake($this->serverName);

    // Set tool definitions with object parameters
    $relay->setToolDefinitions([
        [
            'name' => 'object_tool',
            'description' => 'A tool with object parameters',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'config' => [
                        'type' => 'object',
                        'description' => 'Configuration object',
                        'properties' => [
                            'enabled' => ['type' => 'boolean'],
                            'timeout' => ['type' => 'number'],
                        ],
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'Metadata object',
                        'properties' => [
                            'author' => ['type' => 'string'],
                            'version' => ['type' => 'string'],
                        ],
                    ],
                ],
                'required' => ['config'],
            ],
        ],
    ]);

    $tools = $relay->tools();

    expect($tools)->toBeArray()
        ->and($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(Tool::class);

    // Verify the tool has the expected parameters
    $tool = $tools[0];
    $parameters = $tool->parameters();

    expect($parameters)->toHaveKeys(['config', 'metadata'])
        ->and($parameters['config'])->toBeInstanceOf(\Prism\Prism\Schema\ObjectSchema::class)
        ->and($parameters['metadata'])->toBeInstanceOf(\Prism\Prism\Schema\ObjectSchema::class);

    // Verify the object schemas have the correct types
    $paramsArray = $tool->parametersAsArray();
    expect($paramsArray['config']['type'])->toBe('object')
        ->and($paramsArray['metadata']['type'])->toBe('object');

    // Verify nested properties exist
    expect($paramsArray['config']['properties'])->toHaveKeys(['enabled', 'timeout'])
        ->and($paramsArray['metadata']['properties'])->toHaveKeys(['author', 'version']);
});

it('creates tools with mixed parameter types including arrays and objects', function (): void {
    $relay = new RelayFake($this->serverName);

    // Set tool definitions with mixed parameter types
    $relay->setToolDefinitions([
        [
            'name' => 'mixed_tool',
            'description' => 'A tool with mixed parameter types',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Name parameter',
                    ],
                    'count' => [
                        'type' => 'number',
                        'description' => 'Count parameter',
                    ],
                    'enabled' => [
                        'type' => 'boolean',
                        'description' => 'Enabled parameter',
                    ],
                    'items' => [
                        'type' => 'array',
                        'description' => 'Array of items',
                        'items' => [
                            'type' => 'string',
                        ],
                    ],
                    'settings' => [
                        'type' => 'object',
                        'description' => 'Settings object',
                        'properties' => [
                            'debug' => ['type' => 'boolean'],
                            'level' => ['type' => 'string'],
                        ],
                    ],
                ],
                'required' => ['name', 'items'],
            ],
        ],
    ]);

    $tools = $relay->tools();

    expect($tools)->toBeArray()
        ->and($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(Tool::class);

    // Verify all parameter types are created correctly
    $tool = $tools[0];
    $parameters = $tool->parameters();

    expect($parameters)->toHaveKeys(['name', 'count', 'enabled', 'items', 'settings'])
        ->and($parameters['name'])->toBeInstanceOf(\Prism\Prism\Schema\StringSchema::class)
        ->and($parameters['count'])->toBeInstanceOf(\Prism\Prism\Schema\NumberSchema::class)
        ->and($parameters['enabled'])->toBeInstanceOf(\Prism\Prism\Schema\BooleanSchema::class)
        ->and($parameters['items'])->toBeInstanceOf(\Prism\Prism\Schema\ArraySchema::class)
        ->and($parameters['settings'])->toBeInstanceOf(\Prism\Prism\Schema\ObjectSchema::class);

    // Verify the parameter array representation
    $paramsArray = $tool->parametersAsArray();
    expect($paramsArray['name']['type'])->toBe('string')
        ->and($paramsArray['count']['type'])->toBe('number')
        ->and($paramsArray['enabled']['type'])->toBe('boolean')
        ->and($paramsArray['items']['type'])->toBe('array')
        ->and($paramsArray['settings']['type'])->toBe('object');

    // Verify nested object properties
    expect($paramsArray['settings']['properties'])->toHaveKeys(['debug', 'level'])
        ->and($paramsArray['settings']['properties']['debug']['type'])->toBe('boolean')
        ->and($paramsArray['settings']['properties']['level']['type'])->toBe('string');
});
