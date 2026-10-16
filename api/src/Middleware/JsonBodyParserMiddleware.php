<?php
declare(strict_types=1);

namespace SubTracker\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use SubTracker\Support\ApiResponse;

class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?ResponseFactoryInterface $responseFactory = null
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string) $request->getBody();

            if (trim($rawBody) !== '') {
                $parsed = json_decode($rawBody, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $response = $this->responseFactory?->createResponse(400)
                        ?? new \Slim\Psr7\Response(400);

                    return ApiResponse::error(
                        $response,
                        'MALFORMED_JSON',
                        'Malformed JSON in request body.',
                        400
                    );
                }

                $request = $request->withParsedBody($parsed);
            }
        }

        return $handler->handle($request);
    }
}
