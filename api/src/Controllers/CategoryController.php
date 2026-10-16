<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Repositories\CategoryRepository;
use SubTracker\Support\ApiResponse;

class CategoryController
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository
    ) {}

    /**
     * GET /categories
     */
    public function list(Request $request, Response $response): Response
    {
        $categories = $this->categoryRepository->findAll();

        return ApiResponse::success($response, $categories)
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
