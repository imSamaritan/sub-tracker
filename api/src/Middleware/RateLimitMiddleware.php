<?php
declare(strict_types=1);

namespace SubTracker\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use SubTracker\Services\RateLimiter;
use SubTracker\Support\ApiResponse;

class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param RateLimiter $rateLimiter
     * @param string $action Action prefix (e.g. 'register', 'login')
     * @param int $maxAttempts Maximum allowed requests in window
     * @param int $windowSeconds Duration of window in seconds
     * @param (callable(Request): string)|null $keyResolver Optional custom key generator
     * @param ResponseFactoryInterface|null $responseFactory
     */
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly string $action,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
        private readonly mixed $keyResolver = null,
        private readonly ?ResponseFactoryInterface $responseFactory = null
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $bucketKey = $this->resolveBucketKey($request);
        $result = $this->rateLimiter->attempt($bucketKey, $this->maxAttempts, $this->windowSeconds);

        if (!$result['allowed']) {
            $response = $this->responseFactory?->createResponse(429)
                ?? new \Slim\Psr7\Response(429);

            $response = $response->withHeader('Retry-After', (string) $result['retryAfter']);

            return ApiResponse::error(
                $response,
                'RATE_LIMITED',
                sprintf('Too many requests. Please try again in %d seconds.', $result['retryAfter']),
                429
            );
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
    }

    private function resolveBucketKey(Request $request): string
    {
        if (is_callable($this->keyResolver)) {
            $customKey = ($this->keyResolver)($request);
            return "{$this->action}:{$customKey}";
        }

        $ip = $this->getClientIp($request);
        return "{$this->action}:ip:{$ip}";
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
            $first = trim($parts[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        if (!empty($serverParams['HTTP_CLIENT_IP']) && filter_var($serverParams['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
            return $serverParams['HTTP_CLIENT_IP'];
        }

        if (!empty($serverParams['REMOTE_ADDR']) && filter_var($serverParams['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return $serverParams['REMOTE_ADDR'];
        }

        return '127.0.0.1';
    }
}
