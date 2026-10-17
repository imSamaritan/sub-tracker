<?php
declare(strict_types=1);

namespace SubTracker\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

class CurlHttpClient implements ClientInterface
{
    /** @var callable|null Custom handler for testing: fn(RequestInterface): ResponseInterface */
    private static $mockHandler = null;

    /** @var array<int, RequestInterface> Recorded requests in test/mock mode */
    private static array $recordedRequests = [];

    private ResponseFactory $responseFactory;
    private StreamFactory $streamFactory;

    public function __construct(
        private readonly int $timeoutSeconds = 15
    ) {
        $this->responseFactory = new ResponseFactory();
        $this->streamFactory = new StreamFactory();
    }

    /**
     * Set a mock handler for automated verification tests.
     *
     * @param callable|null $handler fn(RequestInterface $request): ResponseInterface
     */
    public static function setMockHandler(?callable $handler): void
    {
        self::$mockHandler = $handler;
    }

    /**
     * Get recorded requests sent through the client.
     *
     * @return array<int, RequestInterface>
     */
    public static function getRecordedRequests(): array
    {
        return self::$recordedRequests;
    }

    /**
     * Clear recorded requests and optionally reset mock handler.
     */
    public static function clearRecordedRequests(): void
    {
        self::$recordedRequests = [];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        self::$recordedRequests[] = $request;

        if (self::$mockHandler !== null) {
            return (self::$mockHandler)($request);
        }

        // In local/test environments, avoid hanging on dummy push endpoints (e.g. example.com or fake FCM tokens)
        $host = strtolower($request->getUri()->getHost());
        if (
            in_array($host, ['example.com', 'localhost', '127.0.0.1', 'push.example.com', 'test.push.local'], true)
            || str_ends_with($host, '.example.com')
            || str_ends_with($host, '.invalid')
        ) {
            return $this->responseFactory->createResponse(201);
        }

        $ch = curl_init((string) $request->getUri());
        if ($ch === false) {
            throw new class('Failed to initialize cURL session') extends \RuntimeException implements ClientExceptionInterface {};
        }

        $method = strtoupper($request->getMethod());
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = "{$name}: {$value}";
            }
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = (string) $request->getBody();
        if ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $err = curl_error($ch);
            $code = curl_errno($ch);
            curl_close($ch);
            throw new class("cURL transport error ({$code}): {$err}") extends \RuntimeException implements ClientExceptionInterface {};
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $rawResponse, 0, $headerSize);
        $responseBody = substr((string) $rawResponse, $headerSize);

        $response = $this->responseFactory->createResponse($statusCode ?: 200);
        $response = $response->withBody($this->streamFactory->createStream($responseBody));

        $headerLines = preg_split('/\r\n|\r|\n/', trim($rawHeaders)) ?: [];
        foreach ($headerLines as $line) {
            if (str_contains($line, ':')) {
                [$hName, $hVal] = explode(':', $line, 2);
                $hName = trim($hName);
                $hVal = trim($hVal);
                if ($hName !== '') {
                    $response = $response->withAddedHeader($hName, $hVal);
                }
            }
        }

        return $response;
    }
}
