<?php
declare(strict_types=1);

namespace SubTracker\Middleware;

use PDO;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use SubTracker\Support\ApiResponse;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?ResponseFactoryInterface $responseFactory = null
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader) || !preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            $response = $this->createResponse(401);
            return ApiResponse::error(
                $response,
                'UNAUTHENTICATED',
                'Authorization bearer token is required.',
                401
            );
        }

        $plainToken = trim($matches[1]);
        $tokenHash = hash('sha256', $plainToken);

        $stmt = $this->pdo->prepare("
            SELECT
                t.id AS token_id,
                t.user_id,
                t.token_hash,
                t.expires_at,
                t.last_used_at,
                u.id AS user_id,
                u.email,
                u.display_name,
                u.email_verified_at,
                u.created_at AS user_created_at
            FROM auth_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = :hash
              AND t.revoked_at IS NULL
              AND t.expires_at > UTC_TIMESTAMP()
            LIMIT 1
        ");
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            $response = $this->createResponse(401);
            return ApiResponse::error(
                $response,
                'UNAUTHENTICATED',
                'Invalid or expired bearer token.',
                401
            );
        }

        // Throttle last_used_at update to at most once per 5 minutes
        $now = time();
        $lastUsed = $row['last_used_at'] ? strtotime($row['last_used_at']) : 0;
        if (($now - $lastUsed) > 300) {
            $updateStmt = $this->pdo->prepare("
                UPDATE auth_tokens
                SET last_used_at = UTC_TIMESTAMP()
                WHERE id = :token_id
            ");
            $updateStmt->execute([':token_id' => $row['token_id']]);
        }

        $user = [
            'id' => (int) $row['user_id'],
            'email' => $row['email'],
            'displayName' => $row['display_name'],
            'emailVerified' => $row['email_verified_at'] !== null,
            'email_verified_at' => $row['email_verified_at'],
            'createdAt' => $row['user_created_at'],
        ];

        $token = [
            'id' => (int) $row['token_id'],
            'userId' => (int) $row['user_id'],
            'tokenHash' => $row['token_hash'],
            'expiresAt' => $row['expires_at'],
        ];

        $request = $request
            ->withAttribute('user', $user)
            ->withAttribute('token', $token)
            ->withAttribute('userId', (int) $row['user_id']);

        $response = $handler->handle($request);

        // Security requirement (§10): Cache-Control: no-store on authenticated responses
        return $response->withHeader('Cache-Control', 'no-store, private');
    }

    private function createResponse(int $status): Response
    {
        return $this->responseFactory?->createResponse($status)
            ?? new \Slim\Psr7\Response($status);
    }
}
