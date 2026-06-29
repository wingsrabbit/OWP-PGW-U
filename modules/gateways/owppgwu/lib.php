<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

defined('OWPPGWU_USDT_MICRO') || define('OWPPGWU_USDT_MICRO', 1000000);
defined('OWPPGWU_TENTH_MICRO') || define('OWPPGWU_TENTH_MICRO', 100000);
defined('OWPPGWU_CENT_MICRO') || define('OWPPGWU_CENT_MICRO', 10000);

function owppgwu_requests_table()
{
    return 'mod_owppgwu_payment_requests';
}

function owppgwu_transactions_table()
{
    return 'mod_owppgwu_transactions';
}

function owppgwu_now()
{
    return date('Y-m-d H:i:s');
}

function owppgwu_json($value)
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function owppgwu_add_column_if_missing($tableName, $columnName, $callback)
{
    $schema = Capsule::schema();

    if (!$schema->hasColumn($tableName, $columnName)) {
        $schema->table($tableName, function ($table) use ($callback) {
            $callback($table);
        });
    }
}

function owppgwu_ensure_schema()
{
    $schema = Capsule::schema();
    $requestsTable = owppgwu_requests_table();
    $transactionsTable = owppgwu_transactions_table();

    if (!$schema->hasTable($requestsTable)) {
        $schema->create($requestsTable, function ($table) {
            $table->increments('id');
            $table->integer('invoice_id')->index();
            $table->string('invoice_currency', 8);
            $table->integer('invoice_currency_id')->nullable();
            $table->decimal('invoice_amount', 16, 6);
            $table->decimal('invoice_balance', 16, 6);
            $table->decimal('source_currency_rate', 20, 8);
            $table->decimal('usd_currency_rate', 20, 8);
            $table->bigInteger('computed_usdt_micro');
            $table->bigInteger('base_amount_micro')->index();
            $table->bigInteger('display_amount_micro')->index();
            $table->tinyInteger('slot');
            $table->string('address', 128);
            $table->string('active_amount_key', 64)->nullable()->unique('uniq_owppgwu_active_amount');
            $table->string('status', 32)->default('pending')->index();
            $table->string('txid', 128)->nullable()->index();
            $table->dateTime('expires_at')->index();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    } else {
        owppgwu_add_column_if_missing($requestsTable, 'invoice_currency', function ($table) {
            $table->string('invoice_currency', 8)->default('');
        });
        owppgwu_add_column_if_missing($requestsTable, 'invoice_currency_id', function ($table) {
            $table->integer('invoice_currency_id')->nullable();
        });
        owppgwu_add_column_if_missing($requestsTable, 'invoice_balance', function ($table) {
            $table->decimal('invoice_balance', 16, 6)->default(0);
        });
        owppgwu_add_column_if_missing($requestsTable, 'source_currency_rate', function ($table) {
            $table->decimal('source_currency_rate', 20, 8)->default(0);
        });
        owppgwu_add_column_if_missing($requestsTable, 'usd_currency_rate', function ($table) {
            $table->decimal('usd_currency_rate', 20, 8)->default(0);
        });
        owppgwu_add_column_if_missing($requestsTable, 'computed_usdt_micro', function ($table) {
            $table->bigInteger('computed_usdt_micro')->default(0);
        });
    }

    if (!$schema->hasTable($transactionsTable)) {
        $schema->create($transactionsTable, function ($table) {
            $table->increments('id');
            $table->string('txid', 128)->unique();
            $table->integer('request_id')->nullable()->index();
            $table->integer('invoice_id')->nullable()->index();
            $table->string('from_address', 128)->nullable();
            $table->string('to_address', 128);
            $table->string('contract_address', 128);
            $table->bigInteger('amount_micro')->index();
            $table->integer('confirmations')->default(0);
            $table->string('status', 32)->default('received')->index();
            $table->text('payload')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    } else {
        owppgwu_add_column_if_missing($transactionsTable, 'request_id', function ($table) {
            $table->integer('request_id')->nullable()->index();
        });
    }
}

function owppgwu_gateway_setting(array $params, $key, $default = '')
{
    if (!isset($params[$key])) {
        return $default;
    }

    $value = trim((string) $params[$key]);
    return $value === '' ? $default : $value;
}

function owppgwu_gateway_int(array $params, $key, $default, $min, $max)
{
    $value = (int) owppgwu_gateway_setting($params, $key, (string) $default);

    if ($value < $min) {
        return $min;
    }

    if ($value > $max) {
        return $max;
    }

    return $value;
}

function owppgwu_decimal_to_micro($amount, $ceilExcessPrecision = false)
{
    $amount = str_replace(',', '', trim((string) $amount));

    if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $amount, $matches)) {
        throw new InvalidArgumentException('invalid_decimal_amount');
    }

    $whole = (int) $matches[1];
    $fraction = isset($matches[2]) ? $matches[2] : '';
    $firstSix = substr($fraction, 0, 6);
    $extra = substr($fraction, 6);

    if (!$ceilExcessPrecision && preg_match('/[1-9]/', $extra)) {
        throw new InvalidArgumentException('amount_precision_too_high');
    }

    $micro = ($whole * OWPPGWU_USDT_MICRO) + (int) str_pad($firstSix, 6, '0');

    if ($ceilExcessPrecision && preg_match('/[1-9]/', $extra)) {
        $micro++;
    }

    return $micro;
}

function owppgwu_micro_to_decimal($micro, $scale = 2)
{
    $micro = (int) $micro;
    $negative = $micro < 0;
    $micro = abs($micro);
    $whole = intdiv($micro, OWPPGWU_USDT_MICRO);
    $fraction = str_pad((string) ($micro % OWPPGWU_USDT_MICRO), 6, '0');

    if ($scale < 6) {
        $fraction = substr($fraction, 0, $scale);
    }

    return ($negative ? '-' : '') . $whole . ($scale > 0 ? '.' . $fraction : '');
}

function owppgwu_ceil_div($numerator, $denominator)
{
    $numerator = (int) $numerator;
    $denominator = (int) $denominator;

    if ($denominator <= 0) {
        throw new InvalidArgumentException('invalid_denominator');
    }

    if ($numerator <= 0) {
        return 0;
    }

    return intdiv($numerator + $denominator - 1, $denominator);
}

function owppgwu_ceil_to_step_micro($micro, $step)
{
    return owppgwu_ceil_div((int) $micro, (int) $step) * (int) $step;
}

function owppgwu_convert_currency_to_usd_micro($amount, $sourceRate, $usdRate)
{
    $amountMicro = owppgwu_decimal_to_micro($amount, true);
    $sourceRateMicro = owppgwu_decimal_to_micro($sourceRate, true);
    $usdRateMicro = owppgwu_decimal_to_micro($usdRate, true);

    if ($amountMicro <= 0) {
        throw new RuntimeException('invoice_amount_invalid');
    }

    if ($sourceRateMicro <= 0) {
        throw new RuntimeException('source_currency_rate_invalid');
    }

    if ($usdRateMicro <= 0) {
        throw new RuntimeException('usd_currency_rate_invalid');
    }

    return owppgwu_ceil_div($amountMicro * $usdRateMicro, $sourceRateMicro);
}

function owppgwu_currency_by_code($code)
{
    $code = strtoupper(trim((string) $code));

    if ($code === '') {
        return null;
    }

    return Capsule::table('tblcurrencies')
        ->where('code', $code)
        ->first();
}

function owppgwu_currency_by_id($currencyId)
{
    return Capsule::table('tblcurrencies')
        ->where('id', (int) $currencyId)
        ->first();
}

function owppgwu_currency_rate($currency, $errorCode)
{
    if (!$currency || !isset($currency->rate)) {
        throw new RuntimeException($errorCode);
    }

    $rateMicro = owppgwu_decimal_to_micro($currency->rate, true);
    if ($rateMicro <= 0) {
        throw new RuntimeException($errorCode);
    }

    return (string) $currency->rate;
}

function owppgwu_invoice_transactions_micro($invoiceId)
{
    $schema = Capsule::schema();

    if (!$schema->hasTable('tblaccounts')) {
        return 0;
    }

    $rows = Capsule::table('tblaccounts')
        ->where('invoiceid', (int) $invoiceId)
        ->get();

    if ($rows instanceof \Illuminate\Support\Collection) {
        $rows = $rows->all();
    } elseif (!is_array($rows)) {
        $rows = iterator_to_array($rows);
    }

    $paidMicro = 0;
    foreach ($rows as $row) {
        $amountIn = isset($row->amountin) ? $row->amountin : '0';
        $amountOut = isset($row->amountout) ? $row->amountout : '0';
        $paidMicro += owppgwu_decimal_to_micro($amountIn, true);
        $paidMicro -= owppgwu_decimal_to_micro($amountOut, true);
    }

    return $paidMicro;
}

function owppgwu_fetch_invoice_snapshot($invoiceId, $lockForUpdate = false)
{
    $invoiceQuery = Capsule::table('tblinvoices')->where('id', (int) $invoiceId);
    if ($lockForUpdate) {
        $invoiceQuery->lockForUpdate();
    }

    $invoice = $invoiceQuery->first();
    if (!$invoice) {
        throw new RuntimeException('invoice_not_found');
    }

    $client = Capsule::table('tblclients')->where('id', (int) $invoice->userid)->first();
    if (!$client || empty($client->currency)) {
        throw new RuntimeException('invoice_currency_missing');
    }

    $currency = owppgwu_currency_by_id($client->currency);
    if (!$currency || empty($currency->code)) {
        throw new RuntimeException('invoice_currency_missing');
    }

    $totalMicro = owppgwu_decimal_to_micro(isset($invoice->total) ? $invoice->total : '0', true);
    $creditMicro = owppgwu_decimal_to_micro(isset($invoice->credit) ? $invoice->credit : '0', true);
    $paidMicro = owppgwu_invoice_transactions_micro($invoiceId);
    $balanceMicro = max(0, $totalMicro - $creditMicro - $paidMicro);

    return (object) [
        'id' => (int) $invoice->id,
        'userid' => (int) $invoice->userid,
        'status' => (string) $invoice->status,
        'total_micro' => $totalMicro,
        'credit_micro' => $creditMicro,
        'paid_micro' => $paidMicro,
        'balance_micro' => $balanceMicro,
        'balance' => owppgwu_micro_to_decimal($balanceMicro, 6),
        'currency_id' => (int) $currency->id,
        'currency_code' => strtoupper((string) $currency->code),
        'currency_prefix' => isset($currency->prefix) ? (string) $currency->prefix : '',
        'currency_suffix' => isset($currency->suffix) ? (string) $currency->suffix : '',
        'currency_rate' => isset($currency->rate) ? (string) $currency->rate : '',
        'currency' => $currency,
    ];
}

function owppgwu_build_payment_quote($invoiceId, array $params)
{
    $snapshot = owppgwu_fetch_invoice_snapshot($invoiceId, false);

    if (isset($params['currency']) && strtoupper((string) $params['currency']) !== $snapshot->currency_code) {
        throw new RuntimeException('gateway_convert_to_detected');
    }

    if (isset($params['amount'])) {
        $paramAmountMicro = owppgwu_decimal_to_micro($params['amount'], true);
        if ($paramAmountMicro !== (int) $snapshot->balance_micro) {
            throw new RuntimeException('invoice_amount_mismatch');
        }
    }

    if ($snapshot->status !== 'Unpaid') {
        throw new RuntimeException('invoice_not_payable');
    }

    if ((int) $snapshot->balance_micro <= 0) {
        throw new RuntimeException('invoice_amount_invalid');
    }

    $usdCurrency = owppgwu_currency_by_code('USD');
    if (!$usdCurrency) {
        throw new RuntimeException('usd_currency_missing');
    }

    $sourceRate = owppgwu_currency_rate($snapshot->currency, 'source_currency_rate_invalid');
    $usdRate = owppgwu_currency_rate($usdCurrency, 'usd_currency_rate_invalid');
    $computedUsdtMicro = owppgwu_convert_currency_to_usd_micro($snapshot->balance, $sourceRate, $usdRate);
    $baseMicro = owppgwu_ceil_to_step_micro($computedUsdtMicro, OWPPGWU_TENTH_MICRO);

    return (object) [
        'snapshot' => $snapshot,
        'usd_currency' => $usdCurrency,
        'source_currency_rate' => $sourceRate,
        'usd_currency_rate' => $usdRate,
        'computed_usdt_micro' => $computedUsdtMicro,
        'base_amount_micro' => $baseMicro,
    ];
}

function owppgwu_expire_stale_requests()
{
    owppgwu_ensure_schema();

    return Capsule::table(owppgwu_requests_table())
        ->where('status', 'pending')
        ->where('expires_at', '<', owppgwu_now())
        ->update([
            'status' => 'expired',
            'active_amount_key' => null,
            'updated_at' => owppgwu_now(),
        ]);
}

function owppgwu_active_request_for_invoice($invoiceId)
{
    owppgwu_expire_stale_requests();

    return Capsule::table(owppgwu_requests_table())
        ->where('invoice_id', (int) $invoiceId)
        ->where('status', 'pending')
        ->where('expires_at', '>=', owppgwu_now())
        ->orderBy('id', 'desc')
        ->first();
}

function owppgwu_create_payment_request($invoiceId, array $params, $quote = null)
{
    owppgwu_expire_stale_requests();

    $address = owppgwu_gateway_setting($params, 'trc20Address');
    if ($address === '') {
        throw new RuntimeException('trc20_address_missing');
    }

    if (!$quote) {
        $quote = owppgwu_build_payment_quote($invoiceId, $params);
    }

    $windowMinutes = owppgwu_gateway_int($params, 'invoiceWindowMinutes', 30, 1, 1440);
    $slots = owppgwu_gateway_int($params, 'calibrationSlots', 9, 1, 9);
    $expiresAt = date('Y-m-d H:i:s', time() + ($windowMinutes * 60));
    $now = owppgwu_now();

    for ($slot = 1; $slot <= $slots; $slot++) {
        $displayMicro = $quote->base_amount_micro + ($slot * OWPPGWU_CENT_MICRO);
        $activeKey = (string) $displayMicro;
        $exists = Capsule::table(owppgwu_requests_table())
            ->where('active_amount_key', $activeKey)
            ->exists();

        if ($exists) {
            continue;
        }

        try {
            $requestId = Capsule::table(owppgwu_requests_table())->insertGetId([
                'invoice_id' => (int) $invoiceId,
                'invoice_currency' => $quote->snapshot->currency_code,
                'invoice_currency_id' => $quote->snapshot->currency_id,
                'invoice_amount' => $quote->snapshot->balance,
                'invoice_balance' => $quote->snapshot->balance,
                'source_currency_rate' => $quote->source_currency_rate,
                'usd_currency_rate' => $quote->usd_currency_rate,
                'computed_usdt_micro' => $quote->computed_usdt_micro,
                'base_amount_micro' => $quote->base_amount_micro,
                'display_amount_micro' => $displayMicro,
                'slot' => $slot,
                'address' => $address,
                'active_amount_key' => $activeKey,
                'status' => 'pending',
                'txid' => null,
                'expires_at' => $expiresAt,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return Capsule::table(owppgwu_requests_table())->where('id', $requestId)->first();
        } catch (Exception $exception) {
            continue;
        }
    }

    return null;
}

function owppgwu_get_or_create_payment_request($invoiceId, array $params)
{
    $quote = owppgwu_build_payment_quote($invoiceId, $params);
    $existing = owppgwu_active_request_for_invoice($invoiceId);

    if ($existing) {
        $currentInvoiceMicro = owppgwu_decimal_to_micro($quote->snapshot->balance, true);
        $existingInvoiceMicro = owppgwu_decimal_to_micro($existing->invoice_amount, true);
        $existingCurrency = strtoupper((string) $existing->invoice_currency);

        if ($currentInvoiceMicro === $existingInvoiceMicro && $existingCurrency === $quote->snapshot->currency_code) {
            return $existing;
        }

        Capsule::table(owppgwu_requests_table())
            ->where('id', (int) $existing->id)
            ->update([
                'status' => 'expired',
                'active_amount_key' => null,
                'updated_at' => owppgwu_now(),
            ]);
    }

    return owppgwu_create_payment_request($invoiceId, $params, $quote);
}

function owppgwu_active_requests_by_amount($amountMicro)
{
    owppgwu_expire_stale_requests();

    $rows = Capsule::table(owppgwu_requests_table())
        ->where('status', 'pending')
        ->where('display_amount_micro', (int) $amountMicro)
        ->where('expires_at', '>=', owppgwu_now())
        ->orderBy('id', 'asc')
        ->get();

    if ($rows instanceof \Illuminate\Support\Collection) {
        return $rows->all();
    }

    return is_array($rows) ? $rows : iterator_to_array($rows);
}

function owppgwu_find_transaction($txid)
{
    owppgwu_ensure_schema();

    return Capsule::table(owppgwu_transactions_table())
        ->where('txid', (string) $txid)
        ->first();
}

function owppgwu_transaction_row(array $data, $status)
{
    return [
        'request_id' => isset($data['request_id']) ? $data['request_id'] : null,
        'invoice_id' => isset($data['invoice_id']) ? $data['invoice_id'] : null,
        'from_address' => isset($data['from_address']) ? $data['from_address'] : null,
        'to_address' => (string) $data['to_address'],
        'contract_address' => (string) $data['contract_address'],
        'amount_micro' => (int) $data['amount_micro'],
        'confirmations' => (int) $data['confirmations'],
        'status' => (string) $status,
        'payload' => isset($data['payload']) ? owppgwu_json($data['payload']) : null,
        'updated_at' => owppgwu_now(),
    ];
}

function owppgwu_store_transaction(array $data)
{
    owppgwu_ensure_schema();

    $txid = (string) $data['txid'];
    $status = (string) $data['status'];
    $existing = owppgwu_find_transaction($txid);
    $row = owppgwu_transaction_row($data, $status);

    if ($existing) {
        $mutableStatuses = ['received', 'waiting_confirmations'];
        if (!in_array($existing->status, $mutableStatuses, true) && $status !== 'processed') {
            return $existing;
        }

        Capsule::table(owppgwu_transactions_table())
            ->where('txid', $txid)
            ->update($row);

        return owppgwu_find_transaction($txid);
    }

    $row['txid'] = $txid;
    $row['created_at'] = owppgwu_now();
    Capsule::table(owppgwu_transactions_table())->insert($row);

    return owppgwu_find_transaction($txid);
}

function owppgwu_whmcs_transaction_exists($txid)
{
    if (!Capsule::schema()->hasTable('tblaccounts')) {
        return false;
    }

    return Capsule::table('tblaccounts')
        ->where('transid', (string) $txid)
        ->exists();
}

function owppgwu_invoice_payability_problem($request, $lockForUpdate = false)
{
    try {
        $snapshot = owppgwu_fetch_invoice_snapshot((int) $request->invoice_id, $lockForUpdate);
    } catch (Exception $exception) {
        return $exception->getMessage() === 'invoice_not_found' ? 'invoice_not_payable' : $exception->getMessage();
    }

    if ($snapshot->status !== 'Unpaid') {
        return 'invoice_not_payable';
    }

    if (strtoupper((string) $request->invoice_currency) !== $snapshot->currency_code) {
        return 'invoice_amount_changed';
    }

    $requestAmountMicro = owppgwu_decimal_to_micro($request->invoice_amount, true);
    if ((int) $snapshot->balance_micro !== $requestAmountMicro) {
        return 'invoice_amount_changed';
    }

    if ($requestAmountMicro <= 0) {
        return 'invoice_not_payable';
    }

    return null;
}

function owppgwu_claim_payment(array $transactionData, $request)
{
    owppgwu_ensure_schema();
    owppgwu_store_transaction(array_merge($transactionData, ['status' => 'received']));

    return Capsule::connection()->transaction(function () use ($transactionData, $request) {
        $txid = (string) $transactionData['txid'];
        $now = owppgwu_now();
        $transaction = Capsule::table(owppgwu_transactions_table())
            ->where('txid', $txid)
            ->lockForUpdate()
            ->first();

        if (!$transaction) {
            return ['claimed' => false, 'status' => 'transaction_missing'];
        }

        if (in_array($transaction->status, ['processing', 'processed'], true)) {
            return ['claimed' => false, 'status' => 'duplicate_' . $transaction->status];
        }

        if (!in_array($transaction->status, ['received', 'waiting_confirmations'], true)) {
            return ['claimed' => false, 'status' => $transaction->status];
        }

        $requestRow = Capsule::table(owppgwu_requests_table())
            ->where('id', (int) $request->id)
            ->lockForUpdate()
            ->first();

        if (!$requestRow || $requestRow->status !== 'pending' || $requestRow->txid !== null || strtotime($requestRow->expires_at) < time()) {
            Capsule::table(owppgwu_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_transaction_row($transactionData, 'request_already_claimed'), [
                    'request_id' => $requestRow ? (int) $requestRow->id : null,
                    'invoice_id' => $requestRow ? (int) $requestRow->invoice_id : null,
                ]));

            return ['claimed' => false, 'status' => 'request_already_claimed'];
        }

        $payabilityProblem = owppgwu_invoice_payability_problem($requestRow, true);
        if ($payabilityProblem) {
            Capsule::table(owppgwu_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_transaction_row($transactionData, $payabilityProblem), [
                    'request_id' => (int) $requestRow->id,
                    'invoice_id' => (int) $requestRow->invoice_id,
                ]));

            return ['claimed' => false, 'status' => $payabilityProblem];
        }

        if (owppgwu_whmcs_transaction_exists($txid)) {
            Capsule::table(owppgwu_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_transaction_row($transactionData, 'duplicate_whmcs_transaction'), [
                    'request_id' => (int) $requestRow->id,
                    'invoice_id' => (int) $requestRow->invoice_id,
                ]));

            return ['claimed' => false, 'status' => 'duplicate_whmcs_transaction'];
        }

        $updated = Capsule::table(owppgwu_requests_table())
            ->where('id', (int) $requestRow->id)
            ->where('status', 'pending')
            ->whereNull('txid')
            ->where('expires_at', '>=', $now)
            ->update([
                'status' => 'processing',
                'txid' => $txid,
                'active_amount_key' => null,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            Capsule::table(owppgwu_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_transaction_row($transactionData, 'request_already_claimed'), [
                    'request_id' => (int) $requestRow->id,
                    'invoice_id' => (int) $requestRow->invoice_id,
                ]));

            return ['claimed' => false, 'status' => 'request_already_claimed'];
        }

        Capsule::table(owppgwu_transactions_table())
            ->where('txid', $txid)
            ->update(array_merge(owppgwu_transaction_row($transactionData, 'processing'), [
                'request_id' => (int) $requestRow->id,
                'invoice_id' => (int) $requestRow->invoice_id,
            ]));

        return [
            'claimed' => true,
            'status' => 'processing',
            'request' => $requestRow,
        ];
    });
}

function owppgwu_finalize_payment_success($requestId, $txid)
{
    $now = owppgwu_now();

    Capsule::table(owppgwu_requests_table())
        ->where('id', (int) $requestId)
        ->where('status', 'processing')
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'paid',
            'active_amount_key' => null,
            'paid_at' => $now,
            'updated_at' => $now,
        ]);

    Capsule::table(owppgwu_transactions_table())
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'processed',
            'updated_at' => $now,
        ]);
}

function owppgwu_finalize_payment_failure($requestId, $txid, $error)
{
    $now = owppgwu_now();

    Capsule::table(owppgwu_requests_table())
        ->where('id', (int) $requestId)
        ->where('status', 'processing')
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'payment_failed',
            'active_amount_key' => null,
            'updated_at' => $now,
        ]);

    Capsule::table(owppgwu_transactions_table())
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'payment_failed',
            'payload' => owppgwu_json(['error' => (string) $error]),
            'updated_at' => $now,
        ]);
}

function owppgwu_same_address($left, $right)
{
    return trim((string) $left) === trim((string) $right);
}

function owppgwu_currency_display($amount, $currencyCode, $prefix = '', $suffix = '')
{
    $amountText = number_format((float) owppgwu_micro_to_decimal(owppgwu_decimal_to_micro($amount, true), 2), 2, '.', ',');
    $prefix = (string) $prefix;
    $suffix = (string) $suffix;

    if ($prefix === '' && $suffix === '') {
        return strtoupper((string) $currencyCode) . ' ' . $amountText;
    }

    return $prefix . $amountText . $suffix;
}

function owppgwu_html_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function owppgwu_language(array $params)
{
    $candidates = [];

    if (isset($_SESSION['Language'])) {
        $candidates[] = $_SESSION['Language'];
    }

    if (isset($params['clientdetails']['language'])) {
        $candidates[] = $params['clientdetails']['language'];
    }

    if (isset($params['language'])) {
        $candidates[] = $params['language'];
    }

    foreach ($candidates as $candidate) {
        $language = strtolower((string) $candidate);
        if (preg_match('/^(chinese|zh|cn|tw|hk|mandarin|simplified|traditional)/', $language)) {
            return 'zh';
        }
    }

    return 'en';
}

function owppgwu_translations()
{
    return [
        'en' => [
            'title' => 'USDT TRC20 Payment',
            'invoice_amount' => 'WHMCS invoice amount',
            'rate_snapshot' => 'USD rate snapshot',
            'converted_amount' => 'Converted USD/USDT amount',
            'base_amount' => '0.1 USDT ceiling base amount',
            'pay_amount' => 'Exact TRC20 amount to pay',
            'address' => 'TRC20 receiving address',
            'copy_amount' => 'Copy amount',
            'copy_address' => 'Copy address',
            'copied' => 'Copied',
            'warning' => 'Use the TRC20 network and pay exactly {amount} USDT. Overpayments, underpayments, and non-exact payments are not credited automatically. {support}',
            'support_plain' => 'Open a support ticket for manual handling.',
            'support_link' => 'Open a support ticket',
            'note' => 'USD is treated as 1:1 USDT. The current amount is valid for 30 minutes. Time left:',
            'slot_note' => 'Calibration slot: +{slot} USDT.',
            'rate_note' => '{source} -> USD from WHMCS currency table. Source rate: {source_rate}; USD rate: {usd_rate}.',
            'payment_unavailable' => 'USDT TRC20 payment is temporarily unavailable: {reason}',
            'slots_full' => 'All 0.01 calibration slots for this amount are currently in use. Refresh the invoice page later.',
            'trc20_address_missing' => 'TRC20 receiving address is not configured.',
            'invoice_not_found' => 'Invoice was not found.',
            'invoice_currency_missing' => 'Invoice currency was not found in the WHMCS currency table.',
            'usd_currency_missing' => 'USD currency is missing from the WHMCS currency table.',
            'source_currency_rate_invalid' => 'Invoice currency rate is missing or invalid in the WHMCS currency table.',
            'usd_currency_rate_invalid' => 'USD currency rate is missing or invalid in the WHMCS currency table.',
            'invoice_amount_invalid' => 'Invoice balance is zero or invalid.',
            'invoice_not_payable' => 'Invoice is not unpaid and payable.',
            'invoice_amount_mismatch' => 'Invoice balance does not match WHMCS gateway parameters. Check invoice payments or credits.',
            'gateway_convert_to_detected' => 'WHMCS Convert To For Processing appears to be enabled for this gateway. Disable it so this module can use the original invoice currency.',
        ],
        'zh' => [
            'title' => 'USDT TRC20 支付',
            'invoice_amount' => 'WHMCS 发票金额',
            'rate_snapshot' => 'USD 汇率快照',
            'converted_amount' => '换算后的 USD/USDT 金额',
            'base_amount' => '向上取到 0.1 后的基础金额',
            'pay_amount' => '请精确支付 TRC20 金额',
            'address' => 'TRC20 收款地址',
            'copy_amount' => '复制金额',
            'copy_address' => '复制地址',
            'copied' => '已复制',
            'warning' => '必须使用 TRC20 网络，并精确支付 {amount} USDT。多付、少付或未按精确金额付款不会自动入账，{support}',
            'support_plain' => '请开工单联系客服处理。',
            'support_link' => '开工单联系客服',
            'note' => 'USD 按 1:1 USDT 处理。当前金额 30 分钟内有效，剩余：',
            'slot_note' => '校准尾数：+{slot} USDT。',
            'rate_note' => '{source} -> USD，来源为 WHMCS currency table。源币种 rate：{source_rate}；USD rate：{usd_rate}。',
            'payment_unavailable' => 'USDT TRC20 支付暂不可用：{reason}',
            'slots_full' => '当前 30 分钟内相同基础金额的 0.01 校准槽位已满，请稍后刷新账单页面。',
            'trc20_address_missing' => '尚未配置 TRC20 收款地址。',
            'invoice_not_found' => '找不到该发票。',
            'invoice_currency_missing' => 'WHMCS 货币表中找不到发票币种。',
            'usd_currency_missing' => 'WHMCS 货币表中缺少 USD 货币。',
            'source_currency_rate_invalid' => 'WHMCS 货币表中发票币种 rate 缺失或无效。',
            'usd_currency_rate_invalid' => 'WHMCS 货币表中 USD rate 缺失或无效。',
            'invoice_amount_invalid' => '发票余额为 0 或金额无效。',
            'invoice_not_payable' => '发票不是 Unpaid 状态，不能自动入账。',
            'invoice_amount_mismatch' => '发票余额与 WHMCS gateway 参数不一致，请检查发票付款或余额抵扣。',
            'gateway_convert_to_detected' => '检测到该网关可能启用了 WHMCS Convert To For Processing。请关闭该设置，让插件读取原始发票币种。',
        ],
    ];
}

function owppgwu_t($language, $key, array $vars = [])
{
    $translations = owppgwu_translations();
    $language = isset($translations[$language]) ? $language : 'en';
    $text = isset($translations[$language][$key]) ? $translations[$language][$key] : $key;

    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}

function owppgwu_error_message($language, $errorCode)
{
    return owppgwu_t($language, (string) $errorCode);
}
