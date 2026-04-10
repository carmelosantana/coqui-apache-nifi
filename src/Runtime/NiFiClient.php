<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitApacheNifi\Runtime;

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP client for the Apache NiFi REST API.
 *
 * Handles JWT bearer token authentication with automatic acquisition and refresh.
 * Auth flow: POST /access/token with username/password → receive JWT → attach as Bearer header.
 */
final class NiFiClient
{
    private const int TIMEOUT = 30;
    private const int TOKEN_REFRESH_MARGIN_SECONDS = 60;

    private string $resolvedBaseUrl = '';
    private string $resolvedUsername = '';
    private string $resolvedPassword = '';
    private string $bearerToken = '';
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly HttpClientInterface $httpClient = new CurlHttpClient(),
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            baseUrl: self::envString('NIFI_BASE_URL'),
            username: self::envString('NIFI_USERNAME'),
            password: self::envString('NIFI_PASSWORD'),
        );
    }

    // -- HTTP verbs -------------------------------------------------------

    /** @param array<string, mixed> $query */
    public function get(string $endpoint, array $query = []): NiFiResult
    {
        return $this->request('GET', $endpoint, query: $query);
    }

    /** @param array<string, mixed> $body */
    public function post(string $endpoint, array $body = []): NiFiResult
    {
        return $this->request('POST', $endpoint, body: $body);
    }

    /** @param array<string, mixed> $body */
    public function put(string $endpoint, array $body = []): NiFiResult
    {
        return $this->request('PUT', $endpoint, body: $body);
    }

    /** @param array<string, mixed> $query */
    public function delete(string $endpoint, array $query = []): NiFiResult
    {
        return $this->request('DELETE', $endpoint, query: $query);
    }

    // -- Authentication ---------------------------------------------------

    /**
     * Acquire a JWT bearer token from the NiFi access endpoint.
     */
    public function authenticate(): NiFiResult
    {
        $baseUrl = $this->resolveBaseUrl();
        if ($baseUrl === '') {
            return NiFiResult::error(
                'NIFI_BASE_URL is not configured. '
                . 'Set it via the credentials tool: credentials(action: "set", key: "NIFI_BASE_URL", value: "https://localhost:8443")',
            );
        }

        $username = $this->resolveUsername();
        $password = $this->resolvePassword();
        if ($username === '' || $password === '') {
            return NiFiResult::error(
                'NIFI_USERNAME and NIFI_PASSWORD are required for authentication. '
                . 'Set them via the credentials tool.',
            );
        }

        $url = $baseUrl . '/nifi-api/access/token';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'username' => $username,
                    'password' => $password,
                ],
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $token = $response->getContent(false);
                $this->bearerToken = trim($token);
                // Default to 12-hour expiry; NiFi tokens are configurable
                $this->tokenExpiresAt = time() + 43200;
                return new NiFiResult(success: true, data: 'Authenticated successfully.', statusCode: $statusCode);
            }

            return NiFiResult::error('Authentication failed (HTTP ' . $statusCode . ')', $statusCode);
        } catch (HttpExceptionInterface $e) {
            return NiFiResult::error('Authentication failed: ' . $e->getMessage(), $e->getResponse()->getStatusCode());
        } catch (TransportExceptionInterface $e) {
            return NiFiResult::error('Connection error during authentication: ' . $e->getMessage());
        }
    }

    /**
     * Check if we have a valid (non-expired) bearer token.
     */
    public function isAuthenticated(): bool
    {
        return $this->bearerToken !== '' && time() < ($this->tokenExpiresAt - self::TOKEN_REFRESH_MARGIN_SECONDS);
    }

    // -- Internal ---------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(
        string $method,
        string $endpoint,
        array $query = [],
        array $body = [],
    ): NiFiResult {
        $baseUrl = $this->resolveBaseUrl();
        if ($baseUrl === '') {
            return NiFiResult::error(
                'NIFI_BASE_URL is not configured. '
                . 'Set it via the credentials tool: credentials(action: "set", key: "NIFI_BASE_URL", value: "https://localhost:8443")',
            );
        }

        // Auto-authenticate if needed
        if (!$this->isAuthenticated()) {
            $authResult = $this->authenticate();
            if (!$authResult->success) {
                return $authResult;
            }
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->bearerToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
        ];

        if ($query !== []) {
            $options['query'] = $this->filterQuery($query);
        }

        if ($body !== [] && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options['json'] = $body;
        }

        $url = $baseUrl . '/nifi-api/' . ltrim($endpoint, '/');

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            return $this->parseResponse($response, $statusCode);
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            // If 401/403, token may have expired — try re-auth once
            if ($statusCode === 401 || $statusCode === 403) {
                $this->bearerToken = '';
                $authResult = $this->authenticate();
                if ($authResult->success) {
                    $options['headers']['Authorization'] = 'Bearer ' . $this->bearerToken;
                    try {
                        $response = $this->httpClient->request($method, $url, $options);
                        return $this->parseResponse($response, $response->getStatusCode());
                    } catch (\Throwable $retryError) {
                        return NiFiResult::error('Request failed after re-authentication: ' . $retryError->getMessage());
                    }
                }
                return $authResult;
            }

            return $this->parseErrorResponse($e);
        } catch (TransportExceptionInterface $e) {
            return NiFiResult::error('Transport error: ' . $e->getMessage());
        }
    }

    private function parseResponse(ResponseInterface $response, int $statusCode): NiFiResult
    {
        $content = $response->getContent(false);

        if ($content === '' || $content === '[]') {
            return new NiFiResult(
                success: $statusCode >= 200 && $statusCode < 300,
                data: null,
                statusCode: $statusCode,
            );
        }

        $json = json_decode($content, true);

        if (!is_array($json)) {
            return new NiFiResult(
                success: $statusCode >= 200 && $statusCode < 300,
                data: $content,
                statusCode: $statusCode,
            );
        }

        if ($statusCode >= 400) {
            $message = $json['message'] ?? $json['error'] ?? 'API error';
            return new NiFiResult(
                success: false,
                data: null,
                errors: [['message' => (string) $message]],
                statusCode: $statusCode,
            );
        }

        return new NiFiResult(success: true, data: $json, statusCode: $statusCode);
    }

    private function parseErrorResponse(HttpExceptionInterface $e): NiFiResult
    {
        $statusCode = $e->getResponse()->getStatusCode();
        try {
            $json = $e->getResponse()->toArray(false);
            $message = $json['message'] ?? $json['error'] ?? $e->getMessage();
        } catch (\Throwable) {
            $message = $e->getMessage();
        }

        return new NiFiResult(
            success: false,
            data: null,
            errors: [['message' => (string) $message]],
            statusCode: $statusCode,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function filterQuery(array $query): array
    {
        return array_filter($query, static fn(mixed $v): bool => $v !== null && $v !== '');
    }

    private function resolveBaseUrl(): string
    {
        if ($this->resolvedBaseUrl !== '') {
            return $this->resolvedBaseUrl;
        }
        if ($this->baseUrl !== '') {
            $this->resolvedBaseUrl = rtrim($this->baseUrl, '/');
            return $this->resolvedBaseUrl;
        }
        $env = getenv('NIFI_BASE_URL');
        $this->resolvedBaseUrl = is_string($env) && $env !== '' ? rtrim($env, '/') : '';
        return $this->resolvedBaseUrl;
    }

    private function resolveUsername(): string
    {
        if ($this->resolvedUsername !== '') {
            return $this->resolvedUsername;
        }
        if ($this->username !== '') {
            $this->resolvedUsername = $this->username;
            return $this->resolvedUsername;
        }
        $env = getenv('NIFI_USERNAME');
        $this->resolvedUsername = is_string($env) && $env !== '' ? $env : '';
        return $this->resolvedUsername;
    }

    private function resolvePassword(): string
    {
        if ($this->resolvedPassword !== '') {
            return $this->resolvedPassword;
        }
        if ($this->password !== '') {
            $this->resolvedPassword = $this->password;
            return $this->resolvedPassword;
        }
        $env = getenv('NIFI_PASSWORD');
        $this->resolvedPassword = is_string($env) && $env !== '' ? $env : '';
        return $this->resolvedPassword;
    }

    private static function envString(string $name): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : '';
    }
}
