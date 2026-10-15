<?php
declare(strict_types=1);

namespace SubTracker\Support;

use Psr\Http\Message\ResponseInterface as Response;

class ApiResponse
{
    /**
     * Return a standardized success response: { "data": ... }
     *
     * @param Response $response
     * @param mixed $data
     * @param int $status
     * @param array<string, mixed>|null $meta
     * @return Response
     */
    public static function success(Response $response, mixed $data, int $status = 200, ?array $meta = null): Response
    {
        $payload = ['data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return self::json($response, $payload, $status);
    }

    /**
     * Return a standardized error response: { "error": { "code": ..., "message": ..., "fields": ... } }
     *
     * @param Response $response
     * @param string $code
     * @param string $message
     * @param int $status
     * @param array<string, string>|null $fields
     * @return Response
     */
    public static function error(
        Response $response,
        string $code,
        string $message,
        int $status = 400,
        ?array $fields = null
    ): Response {
        $errorPayload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== null && !empty($fields)) {
            $errorPayload['fields'] = $fields;
        }

        return self::json($response, ['error' => $errorPayload], $status);
    }

    /**
     * Write JSON encoded data to the response stream.
     *
     * @param Response $response
     * @param mixed $data
     * @param int $status
     * @return Response
     */
    public static function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
