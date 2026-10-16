<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Repositories\ProfileRepository;
use SubTracker\Support\ApiResponse;
use SubTracker\Support\Validator;

class ProfileController
{
    public function __construct(
        private readonly ProfileRepository $profileRepository
    ) {}

    /**
     * GET /profile
     */
    public function get(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $profile = $this->profileRepository->findByUserId($userId);

        if ($profile === null) {
            $this->profileRepository->createDefault($userId);
            $profile = $this->profileRepository->findByUserId($userId);
        }

        return ApiResponse::success($response, $profile);
    }

    /**
     * PATCH /profile
     */
    public function update(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);

        $errors = Validator::validateProfileUpdate($body);
        if (!empty($errors)) {
            return ApiResponse::error(
                $response,
                'VALIDATION_FAILED',
                'Some fields are invalid.',
                422,
                $errors
            );
        }

        $updatedProfile = $this->profileRepository->update($userId, $body);

        return ApiResponse::success($response, $updatedProfile);
    }
}
