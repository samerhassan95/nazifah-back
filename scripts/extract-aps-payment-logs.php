<?php

/**
 * Extract ALL APS payment log content for support tickets.
 * Run: php scripts/extract-aps-payment-logs.php
 */

$root = dirname(__DIR__);
$logDir = $root . '/storage/logs';
$outFile = $logDir . '/APS-FULL-EXTRACT-FOR-SUPPORT.txt';

// Load .env for APS config
$envFile = $root . '/.env';
$env = [];
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v, " \t\"'");
        }
    }
}

$maskKeys = ['SHA_REQUEST_PHRASE', 'SHA_RESPONSE_PHRASE'];
$apsEnvLines = [];
foreach ($env as $k => $v) {
    if (! preg_match('/^(PAYMENT_|AMAZON_)/', $k)) {
        continue;
    }
    $display = $v;
    foreach ($maskKeys as $mk) {
        if (str_contains($k, $mk) && $v !== '') {
            $display = '******';
        }
    }
    $apsEnvLines[] = "{$k}={$display}";
}

$testAccessCode = $env['AMAZON_PAYMENT_TEST_ACCESS_CODE'] ?? '42NEcQkAPEn2CvuBlQe8';
$liveAccessCode = $env['AMAZON_PAYMENT_ACCESS_CODE'] ?? 'U1xDaJUuiyE8hA2OAE70';

function unmaskAccessCode(array $data, string $testCode, string $liveCode): array
{
    array_walk_recursive($data, function (&$v, $k) use ($testCode, $liveCode) {
        if ($k === 'access_code' && is_string($v) && str_contains($v, '*')) {
            $v = str_starts_with($v, '42') || str_starts_with($testCode, '42') ? $testCode : $liveCode;
        }
    });

    return $data;
}

function parseJsonContext(string $line): ?array
{
    $pos = strpos($line, '{');
    if ($pos === false) {
        return null;
    }
    $decoded = json_decode(substr($line, $pos), true);

    return is_array($decoded) ? $decoded : null;
}

function parseLogLine(string $line): ?array
{
    if (! preg_match('/^\[([^\]]+)\]\s+(\S+)\.(\S+):\s*(.*)$/', $line, $m)) {
        return null;
    }

    return [
        'timestamp' => $m[1],
        'level' => $m[3],
        'message' => trim($m[4]),
        'context' => parseJsonContext($line),
    ];
}

function hdr(string $t): string
{
    return str_repeat('=', 80) . "\n{$t}\n" . str_repeat('=', 80) . "\n";
}

$files = glob($logDir . '/payment-*.log');
sort($files);

$output = hdr('NATHEFAH — APS FULL PAYMENT LOG EXTRACT FOR SUPPORT');
$output .= 'Generated: ' . date('Y-m-d H:i:s T') . "\n\n";

$output .= hdr('.ENV APS CONFIGURATION (SHA phrases masked)');
$output .= implode("\n", $apsEnvLines) . "\n\n";

$output .= hdr('PART 1 — COMPLETE RAW LOG FILES (UNFILTERED)');
foreach ($files as $file) {
    $output .= hdr('RAW: ' . basename($file));
    $output .= file_get_contents($file) . "\n";
}

$output .= hdr('PART 2 — STRUCTURED TRANSACTION INDEX');
$txNum = 0;
$pendingCapture = null;
$pendingVoid = null;
$pendingSignature = null;

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $lineNum => $line) {
        $entry = parseLogLine($line);
        if (! $entry) {
            continue;
        }
        $msg = $entry['message'];
        $ctx = $entry['context'] ?? [];

        if (str_contains($msg, 'request SIGNATURE GENERATED') && isset($ctx['signature'])) {
            $pendingSignature = $ctx['signature'];
        }
        if (str_contains($msg, 'START CAPTURE')) {
            $pendingCapture = $ctx;
        }
        if (str_contains($msg, 'START VOID AUTHORIZATION')) {
            $pendingVoid = $ctx;
        }

        $type = null;
        $endpoint = null;
        $request = null;
        $response = null;
        $httpStatus = 'N/A (not logged by application)';

        if (str_contains($msg, 'Payment parameters prepared for PayFort')) {
            $type = 'HOSTED CHECKOUT — REQUEST TO APS PAYMENT PAGE';
            $endpoint = $ctx['config']['api_url'] ?? 'https://sbcheckout.payfort.com/FortAPI/paymentPage';
            $request = unmaskAccessCode($ctx['all_params'] ?? [], $testAccessCode, $liveAccessCode);
            $response = ['pending' => 'Customer completes card entry on hosted page'];
        } elseif (str_contains($msg, 'Capture response received')) {
            $type = 'CAPTURE — APS REST API RESPONSE';
            $endpoint = 'POST https://sbpaymentservices.payfort.com/FortAPI/paymentApi (sandbox)';
            $response = unmaskAccessCode($ctx['full_response'] ?? [], $testAccessCode, $liveAccessCode);
            $request = [
                'command' => 'CAPTURE',
                'access_code' => $response['access_code'] ?? $testAccessCode,
                'merchant_identifier' => $response['merchant_identifier'] ?? null,
                'merchant_reference' => $pendingCapture['merchant_reference'] ?? ($response['merchant_reference'] ?? null),
                'amount' => $response['amount'] ?? null,
                'currency' => $response['currency'] ?? 'SAR',
                'language' => $response['language'] ?? 'ar',
                'fort_id' => $pendingCapture['fort_id'] ?? ($response['fort_id'] ?? null),
                'signature' => $pendingSignature,
            ];
            $pendingCapture = $pendingSignature = null;
        } elseif (str_contains($msg, 'Void response received')) {
            $type = 'VOID_AUTHORIZATION — APS REST API RESPONSE';
            $endpoint = 'POST https://sbpaymentservices.payfort.com/FortAPI/paymentApi (sandbox)';
            $response = unmaskAccessCode($ctx['full_response'] ?? [], $testAccessCode, $liveAccessCode);
            $request = [
                'command' => 'VOID_AUTHORIZATION',
                'access_code' => $response['access_code'] ?? $testAccessCode,
                'merchant_identifier' => $response['merchant_identifier'] ?? null,
                'merchant_reference' => $pendingVoid['merchant_reference'] ?? ($response['merchant_reference'] ?? null),
                'language' => $response['language'] ?? 'ar',
                'fort_id' => $pendingVoid['fort_id'] ?? ($response['fort_id'] ?? null),
                'signature' => $pendingSignature,
            ];
            $pendingVoid = $pendingSignature = null;
        } elseif (str_contains($msg, 'PAYFORT RAW RESPONSE (COMPLETE)')) {
            $type = 'APS RETURN_URL CALLBACK — FULL RESPONSE FROM APS';
            $endpoint = 'GET/POST merchant return_url (APS redirect after hosted checkout)';
            $response = unmaskAccessCode($ctx['complete_response'] ?? [], $testAccessCode, $liveAccessCode);
            $request = ['see_matching' => 'Payment parameters prepared for PayFort with same merchant_reference'];
        } elseif (str_contains($msg, '==== CHECKOUT CALLBACK HIT ====') || str_contains($msg, '==== PAYMENT CALLBACK HIT ====')) {
            $type = 'MERCHANT CALLBACK ENDPOINT HIT';
            $endpoint = $ctx['url'] ?? 'N/A';
            $httpStatus = '200 (application received callback)';
            $request = $ctx;
        }

        if (! $type) {
            continue;
        }

        $txNum++;
        $resp = is_array($response) ? $response : [];
        $req = is_array($request) ? $request : [];

        $output .= "\n" . hdr("TX #{$txNum} — {$type}");
        $output .= "Timestamp: {$entry['timestamp']}\n";
        $output .= 'Source: ' . basename($file) . ':' . ($lineNum + 1) . "\n";
        $output .= "Endpoint URL: {$endpoint}\n";
        $output .= "HTTP status code: {$httpStatus}\n";
        $output .= 'merchant_reference: ' . ($resp['merchant_reference'] ?? $req['merchant_reference'] ?? $ctx['merchant_reference'] ?? 'null') . "\n";
        $output .= 'fort_id: ' . ($resp['fort_id'] ?? $req['fort_id'] ?? $ctx['fort_id'] ?? 'null') . "\n";
        $output .= 'command: ' . ($resp['command'] ?? $req['command'] ?? $ctx['command'] ?? 'null') . "\n";
        $output .= 'amount: ' . ($resp['amount'] ?? $req['amount'] ?? $ctx['amount'] ?? 'null') . "\n";
        $output .= 'currency: ' . ($resp['currency'] ?? $req['currency'] ?? $ctx['currency'] ?? 'null') . "\n";
        $output .= 'response_code: ' . ($resp['response_code'] ?? $ctx['response_code'] ?? 'null') . "\n";
        $output .= 'response_message: ' . ($resp['response_message'] ?? $ctx['response_message'] ?? 'null') . "\n";
        $output .= 'request_signature: ' . ($req['signature'] ?? $req['request_signature'] ?? 'null') . "\n";
        $output .= 'response_signature: ' . ($resp['signature'] ?? 'null') . "\n";
        $output .= "\nFULL REQUEST PAYLOAD:\n" . json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $output .= "\nFULL RESPONSE PAYLOAD:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

$output .= hdr('END — ' . count($files) . ' log files, ' . $txNum . ' structured transaction blocks');

file_put_contents($outFile, $output);
fwrite(STDOUT, "Written: {$outFile}\nBytes: " . number_format(strlen($output)) . "\nTX blocks: {$txNum}\n");
