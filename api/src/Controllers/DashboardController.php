<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Services\AlertService;
use SubTracker\Services\BudgetService;
use SubTracker\Support\ApiResponse;

class DashboardController
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly AlertService $alertService
    ) {}

    /**
     * GET /dashboard/summary
     */
    public function summary(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $summary = $this->budgetService->getDashboardSummary($userId);

        return ApiResponse::success($response, $summary);
    }

    /**
     * GET /alerts
     */
    public function alerts(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $alerts = $this->alertService->getAlertsForUser($userId);

        return ApiResponse::success($response, $alerts);
    }
}
