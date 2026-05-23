<?php
/**
 * Backend E2E test for ERP/CRM API endpoints.
 *
 * Usage:
 *   export BASE_URL="http://localhost:8080/ERP"
 *   php tests/backend/api-test.php
 *
 * Returns non-zero exit code on failure.
 */

$baseUrl = getenv('BASE_URL') ?: 'http://localhost:8080/ERP';
$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL  $name: " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assertTrue(bool $cond, string $msg = ''): void {
    if (!$cond) throw new RuntimeException($msg ?: 'Expected true, got false');
}

function assertStrContains(string $haystack, string $needle): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("Expected string to contain '$needle'");
    }
}

function httpGet(string $url, ?string $cookieJar = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function httpPost(string $url, array $data, ?string $cookieJar = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
    ]);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

echo "Backend E2E tests for ERP/CRM\n";
echo "Base URL: $baseUrl\n\n";

$jar = tempnam(sys_get_temp_dir(), 'erp_test_');

// 1. Unauthenticated access redirects to login
test('Unauthenticated access redirects to login', function () use ($baseUrl) {
    $res = httpGet($baseUrl . '/index.php');
    assertTrue(in_array($res['code'], [302, 303]), "Expected redirect, got {$res['code']}");
    assertStrContains($res['headers'], 'Location:');
});

// 2. Sync-status returns JSON with error when not authenticated
test('Sync-status requires authentication', function () use ($baseUrl) {
    $res = httpGet($baseUrl . '/api/sync-status.php');
    $json = json_decode($res['body'], true);
    assertTrue($json !== null, 'Response is not JSON');
    assertTrue(isset($json['error']), 'Expected error key');
});

// 3. Successful login
test('Login with admin/admin succeeds', function () use ($baseUrl, &$jar) {
    $res = httpPost($baseUrl . '/login.php', ['username' => 'admin', 'password' => 'admin'], $jar);
    assertTrue(in_array($res['code'], [302, 303]), "Expected redirect, got {$res['code']}");
    assertStrContains($res['headers'], 'Location:');
    assertStrContains($res['headers'], 'index.php');
});

// 4. Dashboard loads after login
test('Dashboard loads after login', function () use ($baseUrl, $jar) {
    $res = httpGet($baseUrl . '/index.php', $jar);
    assertTrue($res['code'] === 200, "Expected 200, got {$res['code']}");
    assertStrContains($res['body'], 'Дашборд');
});

// 5. Stock page loads
test('Stock page loads after login', function () use ($baseUrl, $jar) {
    $res = httpGet($baseUrl . '/modules/stock.php', $jar);
    assertTrue($res['code'] === 200, "Expected 200, got {$res['code']}");
    assertStrContains($res['body'], 'Склад');
});

// 6. Sync-status returns valid JSON after login
test('Sync-status returns valid data after login', function () use ($baseUrl, $jar) {
    $res = httpGet($baseUrl . '/api/sync-status.php', $jar);
    $json = json_decode($res['body'], true);
    assertTrue($json !== null, 'Response is not JSON');
    assertTrue(isset($json['has_sync']), 'Expected has_sync key');
});

// 7. Logout
test('Logout destroys session', function () use ($baseUrl, $jar) {
    $res = httpGet($baseUrl . '/login.php?action=logout', $jar);
    assertStrContains($res['body'], 'вийшли');
});

echo "\n---\n";
echo "Passed: $passed, Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
