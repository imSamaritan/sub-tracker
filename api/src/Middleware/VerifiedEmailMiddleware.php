<?php
declare(strict_types=1);

namespace SubTracker\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use SubTracker\Support\ApiResponse;

class VerifiedEmailMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?ResponseFactoryInterface $responseFactory = null
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $request->getAttribute('user');

        if (!$user || empty($user['emailVerified'])) {
            $response = $this->responseFactory?->createResponse(403)
                ?? new \Slim\Psr7\Response(403);

            return ApiResponse::error(
                $response,
                'EMAIL_NOT_VERIFIED',
                'Your email address has not been verified. Please check your inbox or request a new verification link.',
                403
            );
        }

        return $handler->handle($request);
    }
}
