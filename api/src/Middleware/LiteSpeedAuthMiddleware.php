<?php
declare(strict_types=1);

namespace SubTracker\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware ensuring the Authorization header is available on the request.
 * On LiteSpeed and cPanel Apache FastCGI, the standard Authorization header
 * is often passed via REDIRECT_HTTP_AUTHORIZATION or HTTP_AUTHORIZATION.
 */
class LiteSpeedAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (empty($request->getHeaderLine('Authorization'))) {
            $serverParams = $request->getServerParams();
            $authHeader = $serverParams['REDIRECT_HTTP_AUTHORIZATION']
                ?? $serverParams['HTTP_AUTHORIZATION']
                ?? $serverParams['AUTHORIZATION']
                ?? null;

            if ($authHeader === null && function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
            }

            if ($authHeader !== null && is_string($authHeader) && trim($authHeader) !== '') {
                $request = $request->withHeader('Authorization', trim($authHeader));
            }
        }

        return $handler->handle($request);
    }
}
