<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Repositories\PaymentRepository;

class PaymentController
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository
    ) {}

    /**
     * GET /payments (§8.6)
     */
    public function list(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $params = $request->getQueryParams();

        $errors = [];

        // Validate 'from' date
        if (!empty($params['from'])) {
            $fromStr = (string) $params['from'];
            $fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $fromStr);
            if (!$fromDt || $fromDt->format('Y-m-d') !== $fromStr) {
                $errors['from'] = 'Invalid from date format (YYYY-MM-DD).';
            }
        }

        // Validate 'to' date
        if (!empty($params['to'])) {
            $toStr = (string) $params['to'];
            $toDt = DateTimeImmutable::createFromFormat('Y-m-d', $toStr);
            if (!$toDt || $toDt->format('Y-m-d') !== $toStr) {
                $errors['to'] = 'Invalid to date format (YYYY-MM-DD).';
            }
        }

        // Check from <= to if both are valid
        if (empty($errors['from']) && empty($errors['to']) && !empty($params['from']) && !empty($params['to'])) {
            if ($params['from'] > $params['to']) {
                $errors['from'] = 'From date cannot be after to date.';
            }
        }

        // Validate subscriptionId
        if (isset($params['subscriptionId']) && $params['subscriptionId'] !== '') {
            if (!ctype_digit((string) $params['subscriptionId']) || (int) $params['subscriptionId'] <= 0) {
                $errors['subscriptionId'] = 'Subscription ID must be a positive integer.';
            }
        }

        // Validate page
        if (isset($params['page']) && $params['page'] !== '') {
            if (!ctype_digit((string) $params['page']) || (int) $params['page'] <= 0) {
                $errors['page'] = 'Page must be a positive integer.';
            }
        }

        // Validate perPage
        if (isset($params['perPage']) && $params['perPage'] !== '') {
            if (!ctype_digit((string) $params['perPage']) || (int) $params['perPage'] <= 0) {
                $errors['perPage'] = 'PerPage must be a positive integer.';
            }
        }

        if (!empty($errors)) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Some fields are invalid.',
                    'fields' => $errors,
                ]
            ], JSON_UNESCAPED_SLASHES));

            return $response
                ->withStatus(422)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $result = $this->paymentRepository->listByUser($userId, $params);

        $response->getBody()->write((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
