<?php
declare(strict_types=1);

namespace SubTracker\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Interfaces\ErrorHandlerInterface;
use SubTracker\Exceptions\ApiException;
use SubTracker\Support\ApiResponse;
use Throwable;

class ApiErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory
    ) {}

    public function __invoke(
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): Response {
        $statusCode = 500;
        $errorCode = 'SERVER_ERROR';
        $message = 'An internal server error occurred.';
        $fields = null;
        $headers = [];

        if ($exception instanceof ApiException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $exception->getErrorCode();
            $message = $exception->getMessage();
            $fields = $exception->getFields();
        } elseif ($exception instanceof HttpNotFoundException) {
            $statusCode = 404;
            $errorCode = 'NOT_FOUND';
            $message = 'Resource not found.';
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $statusCode = 405;
            $errorCode = 'METHOD_NOT_ALLOWED';
            $message = 'Method not allowed.';
            $headers['Allow'] = implode(', ', $exception->getAllowedMethods());
        } elseif ($exception instanceof HttpBadRequestException) {
            $statusCode = 400;
            $errorCode = 'BAD_REQUEST';
            $message = $exception->getMessage();
        } elseif ($exception instanceof HttpUnauthorizedException) {
            $statusCode = 401;
            $errorCode = 'UNAUTHENTICATED';
            $message = $exception->getMessage();
        } elseif ($exception instanceof HttpForbiddenException) {
            $statusCode = 403;
            $errorCode = 'FORBIDDEN';
            $message = $exception->getMessage();
        } elseif ($exception instanceof HttpException) {
            $statusCode = $exception->getCode();
            $errorCode = 'HTTP_ERROR';
            $message = $exception->getMessage();
        } else {
            if ($displayErrorDetails) {
                $message = $exception->getMessage();
            }
        }

        $response = $this->responseFactory->createResponse($statusCode);
        foreach ($headers as $headerName => $headerValue) {
            $response = $response->withHeader($headerName, $headerValue);
        }

        return ApiResponse::error(
            $response,
            $errorCode,
            $message,
            $statusCode,
            $fields
        );
    }
}
