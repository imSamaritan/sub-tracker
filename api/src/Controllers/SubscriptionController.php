<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Repositories\CategoryRepository;
use SubTracker\Repositories\SubscriptionRepository;

class SubscriptionController
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly CategoryRepository $categoryRepository
    ) {}

    /**
     * GET /subscriptions (§8.5)
     */
    public function list(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $params = $request->getQueryParams();

        $subscriptions = $this->subscriptionRepository->listByUser($userId, $params);

        return $this->jsonResponse($response, 200, ['data' => $subscriptions]);
    }

    /**
     * GET /subscriptions/{id} (§8.5)
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];

        $sub = $this->subscriptionRepository->findById($userId, $id);
        if (!$sub) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        return $this->jsonResponse($response, 200, ['data' => $sub]);
    }

    /**
     * POST /subscriptions (§8.5)
     */
    public function create(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);

        $errors = $this->validateInput($body, false);
        if (!empty($errors)) {
            return $this->validationErrorResponse($response, $errors);
        }

        $subscription = $this->subscriptionRepository->create($userId, $body);

        return $this->jsonResponse($response, 201, ['data' => $subscription]);
    }

    /**
     * PUT /subscriptions/{id} (§8.5)
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);

        $current = $this->subscriptionRepository->findById($userId, $id);
        if (!$current) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        $errors = $this->validateInput($body, false);
        if (!empty($errors)) {
            return $this->validationErrorResponse($response, $errors);
        }

        $subscription = $this->subscriptionRepository->update($userId, $id, $body, false);

        return $this->jsonResponse($response, 200, ['data' => $subscription]);
    }

    /**
     * PATCH /subscriptions/{id} (§8.5)
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);

        $current = $this->subscriptionRepository->findById($userId, $id);
        if (!$current) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        $errors = $this->validateInput($body, true);
        if (!empty($errors)) {
            return $this->validationErrorResponse($response, $errors);
        }

        $subscription = $this->subscriptionRepository->update($userId, $id, $body, true);

        return $this->jsonResponse($response, 200, ['data' => $subscription]);
    }

    /**
     * DELETE /subscriptions/{id} (§8.5)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];

        $deleted = $this->subscriptionRepository->softDelete($userId, $id);
        if (!$deleted) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        return $response->withStatus(204);
    }

    /**
     * POST /subscriptions/{id}/pause
     */
    public function pause(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];

        $sub = $this->subscriptionRepository->update($userId, $id, ['status' => 'paused'], true);
        if (!$sub) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        return $this->jsonResponse($response, 200, ['data' => $sub]);
    }

    /**
     * POST /subscriptions/{id}/resume
     */
    public function resume(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];

        $sub = $this->subscriptionRepository->update($userId, $id, ['status' => 'active'], true);
        if (!$sub) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        return $this->jsonResponse($response, 200, ['data' => $sub]);
    }

    /**
     * POST /subscriptions/{id}/payment (Mark Paid) (§8.5)
     */
    public function markPaid(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);

        $paidDate = null;
        if (!empty($body['paidDate'])) {
            $paidDate = (string) $body['paidDate'];
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $paidDate);
            if (!$parsed || $parsed->format('Y-m-d') !== $paidDate) {
                return $this->validationErrorResponse($response, ['paidDate' => 'Invalid paid date format (must be YYYY-MM-DD).']);
            }

            $userToday = $this->subscriptionRepository->getUserToday($userId);
            if ($paidDate > $userToday) {
                return $this->validationErrorResponse($response, ['paidDate' => 'Paid date cannot be in the future.']);
            }
        }

        $result = $this->subscriptionRepository->markPaid($userId, $id, $paidDate);
        if (!$result) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        $status = $result['created'] ? 201 : 200;
        return $this->jsonResponse($response, $status, [
            'data' => [
                'subscription' => $result['subscription'],
                'payment' => $result['payment'],
            ]
        ]);
    }

    /**
     * DELETE /subscriptions/{id}/payment (Un-mark Paid) (§8.5)
     */
    public function unmarkPaid(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];

        $result = $this->subscriptionRepository->unmarkPaid($userId, $id);
        if (!$result) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        return $this->jsonResponse($response, 200, [
            'data' => [
                'subscription' => $result['subscription'],
                'removedPaymentId' => $result['removedPaymentId'],
            ]
        ]);
    }

    /**
     * POST /subscriptions/{id}/renew (§6.4, §8.5)
     */
    public function renew(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $id = (int) $args['id'];

        $sub = $this->subscriptionRepository->renew($userId, $id);
        if (!$sub) {
            return $this->errorResponse($response, 404, 'NOT_FOUND', 'Subscription not found.');
        }

        return $this->jsonResponse($response, 200, ['data' => $sub]);
    }

    /**
     * POST /subscriptions/reset-paid (§8.5)
     */
    public function resetPaid(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $count = $this->subscriptionRepository->resetPaid($userId);

        return $this->jsonResponse($response, 200, ['data' => ['updated' => $count]]);
    }

    /**
     * Validate subscription input fields (§6.5).
     */
    private function validateInput(array $data, bool $isPartial): array
    {
        $errors = [];

        // 1. Name
        if (array_key_exists('name', $data) || !$isPartial) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $errors['name'] = 'Please enter a subscription name.';
            } elseif (mb_strlen($name) > 100) {
                $errors['name'] = 'Subscription name must not exceed 100 characters.';
            }
        }

        // 2. Amount
        if (array_key_exists('amount', $data) || !$isPartial) {
            $rawAmount = $data['amount'] ?? null;
            if ($rawAmount === null || !is_numeric($rawAmount) || (float) $rawAmount <= 0) {
                $errors['amount'] = 'Please enter a valid amount in Rands.';
            } elseif ((float) $rawAmount > 99999999.99) {
                $errors['amount'] = 'Amount exceeds maximum limit.';
            }
        }

        // 3. Billing Cycle
        if (array_key_exists('billingCycle', $data)) {
            $cycle = (string) $data['billingCycle'];
            if (!in_array($cycle, ['weekly', 'monthly', 'yearly'], true)) {
                $errors['billingCycle'] = 'Billing cycle must be weekly, monthly, or yearly.';
            }
        }

        // 4. Due Date
        if (array_key_exists('dueDate', $data) || !$isPartial) {
            $due = (string) ($data['dueDate'] ?? '');
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $due);
            if (!$parsed || $parsed->format('Y-m-d') !== $due) {
                $errors['dueDate'] = 'Please enter a valid due date (YYYY-MM-DD).';
            }
        }

        // 5. Start Date
        if (array_key_exists('startDate', $data) && $data['startDate'] !== null && $data['startDate'] !== '') {
            $start = (string) $data['startDate'];
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $start);
            if (!$parsed || $parsed->format('Y-m-d') !== $start) {
                $errors['startDate'] = 'Invalid start date format (YYYY-MM-DD).';
            }
        }

        // 6. Category
        if (array_key_exists('category', $data) && $data['category'] !== null && $data['category'] !== '') {
            $catCode = (string) $data['category'];
            $catId = $this->subscriptionRepository->findCategoryIdByCode($catCode);
            if ($catId === null) {
                $errors['category'] = 'Invalid category provided.';
            }
        }

        // 7. Icon
        if (array_key_exists('icon', $data) && $data['icon'] !== null && $data['icon'] !== '') {
            $icon = (string) $data['icon'];
            if (!preg_match('/^fa-[a-z0-9-]{1,37}$/', $icon)) {
                $errors['icon'] = 'Invalid icon identifier.';
            }
        }

        // 8. Color
        if (array_key_exists('color', $data) && $data['color'] !== null && $data['color'] !== '') {
            $color = (string) $data['color'];
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $errors['color'] = 'Invalid hex color code.';
            }
        }

        // 9. Notes
        if (array_key_exists('notes', $data) && $data['notes'] !== null) {
            $notes = (string) $data['notes'];
            if (mb_strlen($notes) > 255) {
                $errors['notes'] = 'Notes must not exceed 255 characters.';
            }
        }

        // 10. Status
        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            if (!in_array($status, ['active', 'paused'], true)) {
                $errors['status'] = 'Status must be active or paused.';
            }
        }

        return $errors;
    }

    private function jsonResponse(Response $response, int $status, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function validationErrorResponse(Response $response, array $fields): Response
    {
        return $this->jsonResponse($response, 422, [
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'Some fields are invalid.',
                'fields' => $fields,
            ]
        ]);
    }

    private function errorResponse(Response $response, int $status, string $code, string $message): Response
    {
        return $this->jsonResponse($response, $status, [
            'error' => [
                'code' => $code,
                'message' => $message,
            ]
        ]);
    }
}
