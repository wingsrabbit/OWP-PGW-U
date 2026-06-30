<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../owppgwu/lib.php';

owppgwu_load_whmcs_payment_functions();

$gatewayModuleName = 'owppgwu';
$gatewayParams = getGatewayVariables($gatewayModuleName);

header('Content-Type: application/json; charset=utf-8');

function owppgwu_callback_response($statusCode, array $body)
{
    http_response_code($statusCode);
    echo owppgwu_json($body);
    exit;
}

if (empty($gatewayParams['type'])) {
    owppgwu_callback_response(503, [
        'ok' => false,
        'error' => 'gateway_not_active',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    owppgwu_callback_response(405, [
        'ok' => false,
        'error' => 'method_not_allowed',
    ]);
}

owppgwu_ensure_schema();

$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    logTransaction($gatewayParams['name'], ['raw' => $rawBody], 'Invalid JSON');
    owppgwu_callback_response(400, [
        'ok' => false,
        'error' => 'invalid_json',
    ]);
}

$secret = owppgwu_gateway_setting($gatewayParams, 'webhookSecret');
if ($secret === '') {
    logTransaction($gatewayParams['name'], $payload, 'Webhook secret missing');
    owppgwu_callback_response(503, [
        'ok' => false,
        'error' => 'webhook_secret_missing',
    ]);
}

$signature = trim((string) ($_SERVER['HTTP_X_OWP_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$signature = preg_replace('/^sha256=/i', '', $signature);
$expectedSignature = hash_hmac('sha256', $rawBody, $secret);

if (!is_string($signature) || !preg_match('/^[a-f0-9]{64}$/i', $signature) || !hash_equals($expectedSignature, strtolower($signature))) {
    logTransaction($gatewayParams['name'], $payload, 'Signature Failed');
    owppgwu_callback_response(403, [
        'ok' => false,
        'error' => 'signature_failed',
    ]);
}

try {
    $txid = strtolower(trim((string) ($payload['txid'] ?? $payload['transaction_id'] ?? '')));
    $amountMicro = owppgwu_decimal_to_micro(trim((string) ($payload['amount'] ?? '')), false);
    $confirmations = (int) ($payload['confirmations'] ?? 0);
    $blockNumber = isset($payload['block']) ? (int) $payload['block'] : 1;
    $latestBlock = $blockNumber + max(0, $confirmations);
    $transfer = [
        'txid' => $txid,
        'from_address' => trim((string) ($payload['from'] ?? $payload['from_address'] ?? '')),
        'to_address' => trim((string) ($payload['to'] ?? $payload['to_address'] ?? '')),
        'contract_address' => trim((string) ($payload['contract'] ?? $payload['contract_address'] ?? '')),
        'event_type' => 'Transfer',
        'contract_ret' => 'SUCCESS',
        'revert' => 0,
        'raw_amount' => (string) $amountMicro,
        'amount_micro' => $amountMicro,
        'amount_usdt' => owppgwu_micro_to_decimal($amountMicro, 6),
        'block_number' => $blockNumber,
        'block_timestamp' => isset($payload['block_timestamp']) ? (int) $payload['block_timestamp'] : null,
        'raw_json' => $payload,
    ];

    $status = owppgwu_process_observed_transfer($transfer, $gatewayParams, $latestBlock, $gatewayModuleName);
    logTransaction($gatewayParams['name'], $payload, $status);

    $ok = in_array($status, ['paid', 'duplicate_processed'], true);
    $httpStatus = $ok ? 200 : 202;
    if ($status === 'invalid_txid') {
        $httpStatus = 400;
    }

    owppgwu_callback_response($httpStatus, [
        'ok' => $ok,
        'status' => $status,
    ]);
} catch (Exception $exception) {
    logTransaction($gatewayParams['name'], $payload, 'Callback Failed: ' . $exception->getMessage());
    owppgwu_callback_response(400, [
        'ok' => false,
        'error' => $exception->getMessage(),
    ]);
}
