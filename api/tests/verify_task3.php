<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;
use SubTracker\Exceptions\ApiException;
use SubTracker\Middleware\RateLimitMiddleware;
use SubTracker\Services\RateLimiter;

echo "========================================================\n";
echo " SubTracker Backend — Task 3 Automated Verification\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $name\n";
        if ($details) {
            echo "        $details\n";
        }
        $passed++;
    } else {
        echo " [FAIL] $name\n";
        if ($details) {
            echo "        Error: $details\n";
        }
        $failed++;
    }
}

$app = App::create();
$container = $app->getContainer();
$basePath = $app->getBasePath();
$reqFactory = new ServerRequestFactory();
$streamFactory = new StreamFactory();

// ---------------------------------------------------------
// 1. Test RateLimiter Service (Direct DB operations)
// ---------------------------------------------------------
echo "--- 1. RateLimiter Service & rate_limits Table ---\n";
try {
    /** @var RateLimiter $rateLimiter */
    $rateLimiter = $container->get(RateLimiter::class);
    assertTest("RateLimiter resolved from DI container", $rateLimiter instanceof RateLimiter);

    $testBucket = 'test:verify:' . bin2hex(random_bytes(4));

    // Initial check should be 0 hits
    $check0 = $rateLimiter->check($testBucket, 3, 60);
    assertTest("Initial check allowed=true, hits=0", $check0['allowed'] === true && $check0['hits'] === 0);

    // Hit 1
    $hit1 = $rateLimiter->attempt($testBucket, 3, 60);
    assertTest("Attempt 1 allowed=true, hits=1, remaining=2", $hit1['allowed'] === true && $hit1['hits'] === 1 && $hit1['remaining'] === 2);

    // Hit 2
    $hit2 = $rateLimiter->attempt($testBucket, 3, 60);
    assertTest("Attempt 2 allowed=true, hits=2, remaining=1", $hit2['allowed'] === true && $hit2['hits'] === 2 && $hit2['remaining'] === 1);

    // Hit 3 (reaches limit)
    $hit3 = $rateLimiter->attempt($testBucket, 3, 60);
    assertTest("Attempt 3 allowed=true, hits=3, remaining=0", $hit3['allowed'] === true && $hit3['hits'] === 3 && $hit3['remaining'] === 0);

    // Hit 4 (exceeds limit)
    $hit4 = $rateLimiter->attempt($testBucket, 3, 60);
    assertTest("Attempt 4 allowed=false, hits=4, retryAfter > 0", $hit4['allowed'] === false && $hit4['hits'] === 4 && $hit4['retryAfter'] > 0, "RetryAfter: {$hit4['retryAfter']}s");

    // Verify record in MySQL rate_limits table via PDO
    /** @var PDO $pdo */
    $pdo = $container->get(PDO::class);
    $stmt = $pdo->prepare("SELECT * FROM rate_limits WHERE bucket = :bucket");
    $stmt->execute([':bucket' => $testBucket]);
    $dbRow = $stmt->fetch();
    assertTest("Rate limit record exists in rate_limits MySQL table", $dbRow !== false && (int)$dbRow['hits'] === 4, "Hits in DB: " . ($dbRow['hits'] ?? 'none'));

    // Test Reset
    $rateLimiter->reset($testBucket);
    $stmt->execute([':bucket' => $testBucket]);
    assertTest("RateLimiter::reset() clears bucket row", $stmt->fetch() === false);

    // Test pruneExpired method runs without error
    $pruned = $rateLimiter->pruneExpired();
    assertTest("RateLimiter::pruneExpired() executed successfully", is_int($pruned), "Pruned count: $pruned");

} catch (Throwable $e) {
    assertTest("RateLimiter tests", false, $e->getMessage());
}

// ---------------------------------------------------------
// 2. Test JSON Body Parser Middleware
// ---------------------------------------------------------
echo "\n--- 2. JSON Body Parser Middleware ---\n";
try {
    // A. Valid JSON payload
    $bodyValid = $streamFactory->createStream(json_encode(['name' => 'Netflix', 'amount' => 199.00]));
    $requestValid = $reqFactory->createServerRequest('POST', $basePath . '/v1/test/echo')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($bodyValid);

    $responseValid = $app->handle($requestValid);
    assertTest("Valid JSON request returns HTTP 200", $responseValid->getStatusCode() === 200, "Status: " . $responseValid->getStatusCode());
    $jsonValid = json_decode((string) $responseValid->getBody(), true);
    assertTest("Valid JSON payload parsed into request body", ($jsonValid['data']['parsedBody']['name'] ?? null) === 'Netflix', "Parsed: " . json_encode($jsonValid));

    // B. Malformed JSON payload
    $bodyInvalid = $streamFactory->createStream('{ "name": "Broken JSON, invalid syntax');
    $requestInvalid = $reqFactory->createServerRequest('POST', $basePath . '/v1/test/echo')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($bodyInvalid);

    $responseInvalid = $app->handle($requestInvalid);
    assertTest("Malformed JSON returns HTTP 400", $responseInvalid->getStatusCode() === 400, "Status: " . $responseInvalid->getStatusCode());
    $jsonInvalid = json_decode((string) $responseInvalid->getBody(), true);
    assertTest(
        "Malformed JSON error envelope has code MALFORMED_JSON",
        ($jsonInvalid['error']['code'] ?? null) === 'MALFORMED_JSON',
        "Response: " . json_encode($jsonInvalid)
    );

} catch (Throwable $e) {
    assertTest("JsonBodyParser tests", false, $e->getMessage());
}

// ---------------------------------------------------------
// 3. Test Error Handler Middleware (ApiErrorHandler)
// ---------------------------------------------------------
echo "\n--- 3. Error Handler Middleware (ApiErrorHandler) ---\n";
try {
    // A. 404 Not Found
    $req404 = $reqFactory->createServerRequest('GET', $basePath . '/v1/non-existent-endpoint');
    $res404 = $app->handle($req404);
    assertTest("Unknown route returns HTTP 404", $res404->getStatusCode() === 404, "Status: " . $res404->getStatusCode());
    $json404 = json_decode((string) $res404->getBody(), true);
    assertTest("404 envelope has code NOT_FOUND", ($json404['error']['code'] ?? null) === 'NOT_FOUND', "Response: " . json_encode($json404));

    // B. 405 Method Not Allowed
    $req405 = $reqFactory->createServerRequest('POST', $basePath . '/v1/health');
    $res405 = $app->handle($req405);
    assertTest("Wrong method returns HTTP 405", $res405->getStatusCode() === 405, "Status: " . $res405->getStatusCode());
    $json405 = json_decode((string) $res405->getBody(), true);
    assertTest("405 envelope has code METHOD_NOT_ALLOWED", ($json405['error']['code'] ?? null) === 'METHOD_NOT_ALLOWED');
    assertTest("405 response includes Allow header", $res405->hasHeader('Allow'), "Allow: " . $res405->getHeaderLine('Allow'));

} catch (Throwable $e) {
    assertTest("ApiErrorHandler tests", false, $e->getMessage());
}

// ---------------------------------------------------------
// 4. Test RateLimitMiddleware (Route Level HTTP Test)
// ---------------------------------------------------------
echo "\n--- 4. RateLimitMiddleware (HTTP Pipeline) ---\n";
try {
    // Create dedicated sub-app to test route-level rate limit middleware
    $rateApp = App::create();
    $rateLimiter = $rateApp->getContainer()->get(RateLimiter::class);
    $testRouteAction = 'route-test-' . bin2hex(random_bytes(3));

    $rateApp->get('/v1/test/ratelimit', function ($req, $res) {
        return \SubTracker\Support\ApiResponse::success($res, ['message' => 'ok']);
    })->add(new RateLimitMiddleware($rateLimiter, $testRouteAction, maxAttempts: 2, windowSeconds: 60));

    // Request 1 -> 200 OK
    $reqR1 = $reqFactory->createServerRequest('GET', $basePath . '/v1/test/ratelimit', ['REMOTE_ADDR' => '192.168.1.100']);
    $resR1 = $rateApp->handle($reqR1);
    assertTest("Rate limited route req 1 returns HTTP 200", $resR1->getStatusCode() === 200);
    assertTest("Req 1 includes X-RateLimit headers", $resR1->getHeaderLine('X-RateLimit-Limit') === '2' && $resR1->getHeaderLine('X-RateLimit-Remaining') === '1');

    // Request 2 -> 200 OK
    $resR2 = $rateApp->handle($reqR1);
    assertTest("Rate limited route req 2 returns HTTP 200", $resR2->getStatusCode() === 200);
    assertTest("Req 2 has remaining = 0", $resR2->getHeaderLine('X-RateLimit-Remaining') === '0');

    // Request 3 -> 429 Too Many Requests
    $resR3 = $rateApp->handle($reqR1);
    assertTest("Rate limited route req 3 returns HTTP 429", $resR3->getStatusCode() === 429, "Status: " . $resR3->getStatusCode());
    assertTest("429 response contains Retry-After header", $resR3->hasHeader('Retry-After'), "Retry-After: " . $resR3->getHeaderLine('Retry-After'));
    $jsonR3 = json_decode((string) $resR3->getBody(), true);
    assertTest("429 response body code is RATE_LIMITED", ($jsonR3['error']['code'] ?? null) === 'RATE_LIMITED', "Body: " . json_encode($jsonR3));

} catch (Throwable $e) {
    assertTest("RateLimitMiddleware pipeline test", false, $e->getMessage());
}

// ---------------------------------------------------------
// 5. Test LiteSpeedAuthMiddleware (Header Normalization)
// ---------------------------------------------------------
echo "\n--- 5. LiteSpeedAuthMiddleware (Header Normalization) ---\n";
try {
    // Case A: Standard Authorization header
    $reqAuthStd = $reqFactory->createServerRequest('POST', $basePath . '/v1/test/echo')
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Authorization', 'Bearer token_std_123');
    $resAuthStd = $app->handle($reqAuthStd);
    $jsonAuthStd = json_decode((string) $resAuthStd->getBody(), true);
    assertTest("Standard Authorization header preserved", ($jsonAuthStd['data']['authHeader'] ?? null) === 'Bearer token_std_123');

    // Case B: Header passed via REDIRECT_HTTP_AUTHORIZATION (LiteSpeed / mod_rewrite)
    $reqAuthRedirect = $reqFactory->createServerRequest(
        'POST',
        $basePath . '/v1/test/echo',
        ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer token_redirect_456']
    )->withHeader('Content-Type', 'application/json');
    $resAuthRedirect = $app->handle($reqAuthRedirect);
    $jsonAuthRedirect = json_decode((string) $resAuthRedirect->getBody(), true);
    assertTest(
        "REDIRECT_HTTP_AUTHORIZATION normalized to Authorization header",
        ($jsonAuthRedirect['data']['authHeader'] ?? null) === 'Bearer token_redirect_456',
        "Got: " . ($jsonAuthRedirect['data']['authHeader'] ?? 'none')
    );

    // Case C: Header passed via HTTP_AUTHORIZATION
    $reqAuthHttp = $reqFactory->createServerRequest(
        'POST',
        $basePath . '/v1/test/echo',
        ['HTTP_AUTHORIZATION' => 'Bearer token_http_789']
    )->withHeader('Content-Type', 'application/json');
    $resAuthHttp = $app->handle($reqAuthHttp);
    $jsonAuthHttp = json_decode((string) $resAuthHttp->getBody(), true);
    assertTest(
        "HTTP_AUTHORIZATION normalized to Authorization header",
        ($jsonAuthHttp['data']['authHeader'] ?? null) === 'Bearer token_http_789',
        "Got: " . ($jsonAuthHttp['data']['authHeader'] ?? 'none')
    );

} catch (Throwable $e) {
    assertTest("LiteSpeedAuthMiddleware test", false, $e->getMessage());
}

echo "\n--------------------------------------------------------\n";
echo " Task 3 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
