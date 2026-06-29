<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../owppgwu/lib.php';

App::load_function('gateway');
App::load_function('invoice');

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

$txid = strtolower(trim((string) ($payload['txid'] ?? $payload['transaction_id'] ?? '')));
$fromAddress = trim((string) ($payload['from'] ?? $payload['from_address'] ?? ''));
$toAddress = trim((string) ($payload['to'] ?? $payload['to_address'] ?? ''));
$contractAddress = trim((string) ($payload['contract'] ?? $payload['contract_address'] ?? ''));
$amountText = trim((string) ($payload['amount'] ?? ''));
$confirmations = (int) ($payload['confirmations'] ?? 0);

if (!preg_match('/^[a-f0-9]{64}$/', $txid)) {
    logTransaction($gatewayParams['name'], $payload, 'Invalid TXID');
    owppgwu_callback_response(400, [
        'ok' => false,
        'error' => 'invalid_txid',
    ]);
}

try {
    $amountMicro = owppgwu_decimal_to_micro($amountText, false);
} catch (Exception $exception) {
    logTransaction($gatewayParams['name'], $payload, 'Invalid Amount');
    owppgwu_callback_response(400, [
        'ok' => false,
        'error' => 'invalid_amount',
    ]);
}

$expectedAddress = owppgwu_gateway_setting($gatewayParams, 'trc20Address');
$expectedContract = owppgwu_gateway_setting($gatewayParams, 'usdtContract', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');

if (!owppgwu_same_address($toAddress, $expectedAddress)) {
    owppgwu_store_transaction([
        'txid' => $txid,
        'invoice_id' => null,
        'from_address' => $fromAddress,
        'to_address' => $toAddress,
        'contract_address' => $contractAddress,
        'amount_micro' => $amountMicro,
        'confirmations' => $confirmations,
        'status' => 'wrong_address',
        'payload' => $payload,
    ]);
    logTransaction($gatewayParams['name'], $payload, 'Wrong Address');
    owppgwu_callback_response(202, [
        'ok' => false,
        'error' => 'wrong_address',
    ]);
}

if (!owppgwu_same_address($contractAddress, $expectedContract)) {
    owppgwu_store_transaction([
        'txid' => $txid,
        'invoice_id' => null,
        'from_address' => $fromAddress,
        'to_address' => $toAddress,
        'contract_address' => $contractAddress,
        'amount_micro' => $amountMicro,
        'confirmations' => $confirmations,
        'status' => 'wrong_contract',
        'payload' => $payload,
    ]);
    logTransaction($gatewayParams['name'], $payload, 'Wrong Contract');
    owppgwu_callback_response(202, [
        'ok' => false,
        'error' => 'wrong_contract',
    ]);
}

$existingTx = owppgwu_find_transaction($txid);
if ($existingTx && $existingTx->status === 'processed') {
    owppgwu_callback_response(200, [
        'ok' => true,
        'status' => 'duplicate_processed',
    ]);
}

$requiredConfirmations = owppgwu_gateway_int($gatewayParams, 'requiredConfirmations', 12, 0, 1000);
if ($confirmations < $requiredConfirmations) {
    owppgwu_store_transaction([
        'txid' => $txid,
        'invoice_id' => null,
        'from_address' => $fromAddress,
        'to_address' => $toAddress,
        'contract_address' => $contractAddress,
        'amount_micro' => $amountMicro,
        'confirmations' => $confirmations,
        'status' => 'waiting_confirmations',
        'payload' => $payload,
    ]);
    logTransaction($gatewayParams['name'], $payload, 'Waiting Confirmations');
    owppgwu_callback_response(202, [
        'ok' => false,
        'status' => 'waiting_confirmations',
    ]);
}

$matches = owppgwu_active_requests_by_amount($amountMicro);

if (count($matches) !== 1) {
    owppgwu_store_transaction([
        'txid' => $txid,
        'invoice_id' => null,
        'from_address' => $fromAddress,
        'to_address' => $toAddress,
        'contract_address' => $contractAddress,
        'amount_micro' => $amountMicro,
        'confirmations' => $confirmations,
        'status' => count($matches) === 0 ? 'unmatched' : 'conflict',
        'payload' => $payload,
    ]);
    logTransaction($gatewayParams['name'], $payload, count($matches) === 0 ? 'Unmatched Amount' : 'Amount Conflict');
    owppgwu_callback_response(202, [
        'ok' => false,
        'status' => count($matches) === 0 ? 'unmatched' : 'conflict',
    ]);
}

$request = $matches[0];

checkCbInvoiceID((int) $request->invoice_id, $gatewayParams['name']);
checkCbTransID($txid);

addInvoicePayment(
    (int) $request->invoice_id,
    $txid,
    (string) $request->invoice_amount,
    0,
    $gatewayModuleName
);

owppgwu_store_transaction([
    'txid' => $txid,
    'invoice_id' => (int) $request->invoice_id,
    'from_address' => $fromAddress,
    'to_address' => $toAddress,
    'contract_address' => $contractAddress,
    'amount_micro' => $amountMicro,
    'confirmations' => $confirmations,
    'status' => 'processed',
    'payload' => $payload,
]);

owppgwu_mark_request_paid((int) $request->id, $txid);
logTransaction($gatewayParams['name'], $payload, 'Successful');

owppgwu_callback_response(200, [
    'ok' => true,
    'status' => 'paid',
    'invoice_id' => (int) $request->invoice_id,
]);
