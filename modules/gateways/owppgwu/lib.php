<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

defined('OWPPGWU_USDT_MICRO') || define('OWPPGWU_USDT_MICRO', 1000000);
defined('OWPPGWU_TENTH_MICRO') || define('OWPPGWU_TENTH_MICRO', 100000);
defined('OWPPGWU_CENT_MICRO') || define('OWPPGWU_CENT_MICRO', 10000);
defined('OWPPGWU_TRONSCAN_API_BASE') || define('OWPPGWU_TRONSCAN_API_BASE', 'https://apilist.tronscanapi.com/api');

function owppgwu_intents_table()
{
    return 'mod_owppgwu_payment_intents';
}

function owppgwu_processed_transactions_table()
{
    return 'mod_owppgwu_processed_transactions';
}

function owppgwu_scan_state_table()
{
    return 'mod_owppgwu_scan_state';
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
    $intentsTable = owppgwu_intents_table();
    $transactionsTable = owppgwu_processed_transactions_table();
    $scanStateTable = owppgwu_scan_state_table();

    if (!$schema->hasTable($intentsTable)) {
        $schema->create($intentsTable, function ($table) {
            $table->increments('id');
            $table->integer('invoice_id')->index();
            $table->decimal('invoice_amount_snapshot', 16, 6);
            $table->string('invoice_currency', 8);
            $table->integer('invoice_currency_id')->nullable();
            $table->decimal('invoice_balance_snapshot', 16, 6);
            $table->decimal('source_currency_rate', 20, 8);
            $table->decimal('usd_currency_rate', 20, 8);
            $table->bigInteger('computed_usdt_micro_amount');
            $table->bigInteger('base_usdt_micro_amount')->index();
            $table->decimal('expected_usdt_amount', 18, 6);
            $table->bigInteger('expected_usdt_micro_amount')->index();
            $table->tinyInteger('slot');
            $table->string('trc20_address', 128);
            $table->string('usdt_contract', 128);
            $table->string('active_amount_key', 64)->nullable()->unique('uniq_owppgwu_active_amount');
            $table->string('txid', 128)->nullable()->index();
            $table->string('status', 16)->default('pending')->index();
            $table->dateTime('expires_at')->index();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    } else {
        owppgwu_add_column_if_missing($intentsTable, 'invoice_amount_snapshot', function ($table) {
            $table->decimal('invoice_amount_snapshot', 16, 6)->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'invoice_currency', function ($table) {
            $table->string('invoice_currency', 8)->default('');
        });
        owppgwu_add_column_if_missing($intentsTable, 'invoice_currency_id', function ($table) {
            $table->integer('invoice_currency_id')->nullable();
        });
        owppgwu_add_column_if_missing($intentsTable, 'invoice_balance_snapshot', function ($table) {
            $table->decimal('invoice_balance_snapshot', 16, 6)->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'source_currency_rate', function ($table) {
            $table->decimal('source_currency_rate', 20, 8)->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'usd_currency_rate', function ($table) {
            $table->decimal('usd_currency_rate', 20, 8)->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'computed_usdt_micro_amount', function ($table) {
            $table->bigInteger('computed_usdt_micro_amount')->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'base_usdt_micro_amount', function ($table) {
            $table->bigInteger('base_usdt_micro_amount')->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'expected_usdt_amount', function ($table) {
            $table->decimal('expected_usdt_amount', 18, 6)->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'expected_usdt_micro_amount', function ($table) {
            $table->bigInteger('expected_usdt_micro_amount')->default(0)->index();
        });
        owppgwu_add_column_if_missing($intentsTable, 'slot', function ($table) {
            $table->tinyInteger('slot')->default(0);
        });
        owppgwu_add_column_if_missing($intentsTable, 'trc20_address', function ($table) {
            $table->string('trc20_address', 128)->default('');
        });
        owppgwu_add_column_if_missing($intentsTable, 'usdt_contract', function ($table) {
            $table->string('usdt_contract', 128)->default('');
        });
        owppgwu_add_column_if_missing($intentsTable, 'active_amount_key', function ($table) {
            $table->string('active_amount_key', 64)->nullable()->unique('uniq_owppgwu_active_amount');
        });
        owppgwu_add_column_if_missing($intentsTable, 'txid', function ($table) {
            $table->string('txid', 128)->nullable()->index();
        });
    }

    if (!$schema->hasTable($transactionsTable)) {
        $schema->create($transactionsTable, function ($table) {
            $table->increments('id');
            $table->string('txid', 128)->unique();
            $table->integer('invoice_id')->nullable()->index();
            $table->integer('intent_id')->nullable()->index();
            $table->string('from_address', 128)->nullable();
            $table->string('to_address', 128);
            $table->string('contract_address', 128);
            $table->string('raw_amount', 64);
            $table->decimal('amount_usdt', 18, 6);
            $table->bigInteger('amount_micro')->index();
            $table->bigInteger('block_number')->nullable()->index();
            $table->bigInteger('block_timestamp')->nullable();
            $table->integer('confirmations')->default(0);
            $table->string('status', 32)->default('received')->index();
            $table->longText('raw_json')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    } else {
        owppgwu_add_column_if_missing($transactionsTable, 'intent_id', function ($table) {
            $table->integer('intent_id')->nullable()->index();
        });
        owppgwu_add_column_if_missing($transactionsTable, 'contract_address', function ($table) {
            $table->string('contract_address', 128)->default('');
        });
        owppgwu_add_column_if_missing($transactionsTable, 'raw_amount', function ($table) {
            $table->string('raw_amount', 64)->default('0');
        });
        owppgwu_add_column_if_missing($transactionsTable, 'amount_usdt', function ($table) {
            $table->decimal('amount_usdt', 18, 6)->default(0);
        });
        owppgwu_add_column_if_missing($transactionsTable, 'amount_micro', function ($table) {
            $table->bigInteger('amount_micro')->default(0)->index();
        });
        owppgwu_add_column_if_missing($transactionsTable, 'block_number', function ($table) {
            $table->bigInteger('block_number')->nullable()->index();
        });
        owppgwu_add_column_if_missing($transactionsTable, 'block_timestamp', function ($table) {
            $table->bigInteger('block_timestamp')->nullable();
        });
        owppgwu_add_column_if_missing($transactionsTable, 'confirmations', function ($table) {
            $table->integer('confirmations')->default(0);
        });
        owppgwu_add_column_if_missing($transactionsTable, 'raw_json', function ($table) {
            $table->longText('raw_json')->nullable();
        });
        owppgwu_add_column_if_missing($transactionsTable, 'processed_at', function ($table) {
            $table->dateTime('processed_at')->nullable();
        });
    }

    if (!$schema->hasTable($scanStateTable)) {
        $schema->create($scanStateTable, function ($table) {
            $table->increments('id');
            $table->string('name', 64)->unique();
            $table->bigInteger('last_scan_at_ms')->default(0);
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('locked_until')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
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
    if (!Capsule::schema()->hasTable('tblaccounts')) {
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

    $address = owppgwu_gateway_setting($params, 'trc20Address');
    if ($address === '') {
        throw new RuntimeException('trc20_address_missing');
    }

    $apiKey = owppgwu_gateway_setting($params, 'tronscanApiKey');
    if ($apiKey === '') {
        throw new RuntimeException('tronscan_api_key_missing');
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

function owppgwu_expire_stale_intents()
{
    owppgwu_ensure_schema();

    return Capsule::table(owppgwu_intents_table())
        ->where('status', 'pending')
        ->whereNull('txid')
        ->where('expires_at', '<', owppgwu_now())
        ->update([
            'status' => 'expired',
            'active_amount_key' => null,
            'updated_at' => owppgwu_now(),
        ]);
}

function owppgwu_active_intent_for_invoice($invoiceId)
{
    owppgwu_expire_stale_intents();

    return Capsule::table(owppgwu_intents_table())
        ->where('invoice_id', (int) $invoiceId)
        ->where('status', 'pending')
        ->where('expires_at', '>=', owppgwu_now())
        ->orderBy('id', 'desc')
        ->first();
}

function owppgwu_create_payment_intent($invoiceId, array $params, $quote = null)
{
    owppgwu_expire_stale_intents();

    $address = owppgwu_gateway_setting($params, 'trc20Address');
    if ($address === '') {
        throw new RuntimeException('trc20_address_missing');
    }

    $contract = owppgwu_gateway_setting($params, 'usdtContract', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
    if ($contract === '') {
        throw new RuntimeException('usdt_contract_missing');
    }

    if (!$quote) {
        $quote = owppgwu_build_payment_quote($invoiceId, $params);
    }

    $windowMinutes = owppgwu_gateway_int($params, 'invoiceWindowMinutes', 30, 1, 1440);
    $slots = owppgwu_gateway_int($params, 'calibrationSlots', 9, 1, 9);
    $expiresAt = date('Y-m-d H:i:s', time() + ($windowMinutes * 60));
    $now = owppgwu_now();

    for ($slot = 1; $slot <= $slots; $slot++) {
        $expectedMicro = $quote->base_amount_micro + ($slot * OWPPGWU_CENT_MICRO);
        $activeKey = (string) $expectedMicro;
        $exists = Capsule::table(owppgwu_intents_table())
            ->where('active_amount_key', $activeKey)
            ->exists();

        if ($exists) {
            continue;
        }

        try {
            $intentId = Capsule::table(owppgwu_intents_table())->insertGetId([
                'invoice_id' => (int) $invoiceId,
                'invoice_amount_snapshot' => $quote->snapshot->balance,
                'invoice_currency' => $quote->snapshot->currency_code,
                'invoice_currency_id' => $quote->snapshot->currency_id,
                'invoice_balance_snapshot' => $quote->snapshot->balance,
                'source_currency_rate' => $quote->source_currency_rate,
                'usd_currency_rate' => $quote->usd_currency_rate,
                'computed_usdt_micro_amount' => $quote->computed_usdt_micro,
                'base_usdt_micro_amount' => $quote->base_amount_micro,
                'expected_usdt_amount' => owppgwu_micro_to_decimal($expectedMicro, 6),
                'expected_usdt_micro_amount' => $expectedMicro,
                'slot' => $slot,
                'trc20_address' => $address,
                'usdt_contract' => $contract,
                'active_amount_key' => $activeKey,
                'txid' => null,
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return Capsule::table(owppgwu_intents_table())->where('id', $intentId)->first();
        } catch (Exception $exception) {
            continue;
        }
    }

    return null;
}

function owppgwu_get_or_create_payment_intent($invoiceId, array $params)
{
    $quote = owppgwu_build_payment_quote($invoiceId, $params);
    $existing = owppgwu_active_intent_for_invoice($invoiceId);

    if ($existing) {
        $currentInvoiceMicro = owppgwu_decimal_to_micro($quote->snapshot->balance, true);
        $existingInvoiceMicro = owppgwu_decimal_to_micro($existing->invoice_balance_snapshot, true);
        $existingCurrency = strtoupper((string) $existing->invoice_currency);
        $sameAddress = owppgwu_same_address($existing->trc20_address, owppgwu_gateway_setting($params, 'trc20Address'));
        $sameContract = owppgwu_same_address($existing->usdt_contract, owppgwu_gateway_setting($params, 'usdtContract', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));

        if ($currentInvoiceMicro === $existingInvoiceMicro && $existingCurrency === $quote->snapshot->currency_code && $sameAddress && $sameContract) {
            return $existing;
        }

        if ($existing->txid === null) {
            Capsule::table(owppgwu_intents_table())
                ->where('id', (int) $existing->id)
                ->update([
                    'status' => 'expired',
                    'active_amount_key' => null,
                    'updated_at' => owppgwu_now(),
                ]);
        }
    }

    return owppgwu_create_payment_intent($invoiceId, $params, $quote);
}

function owppgwu_active_intents_by_amount($amountMicro)
{
    owppgwu_expire_stale_intents();

    $rows = Capsule::table(owppgwu_intents_table())
        ->where('status', 'pending')
        ->where('expected_usdt_micro_amount', (int) $amountMicro)
        ->where('expires_at', '>=', owppgwu_now())
        ->whereNull('txid')
        ->orderBy('id', 'asc')
        ->get();

    if ($rows instanceof \Illuminate\Support\Collection) {
        return $rows->all();
    }

    return is_array($rows) ? $rows : iterator_to_array($rows);
}

function owppgwu_find_processed_transaction($txid)
{
    owppgwu_ensure_schema();

    return Capsule::table(owppgwu_processed_transactions_table())
        ->where('txid', (string) $txid)
        ->first();
}

function owppgwu_processed_transaction_row(array $data, $status)
{
    return [
        'invoice_id' => isset($data['invoice_id']) ? $data['invoice_id'] : null,
        'intent_id' => isset($data['intent_id']) ? $data['intent_id'] : null,
        'from_address' => isset($data['from_address']) ? $data['from_address'] : null,
        'to_address' => isset($data['to_address']) ? (string) $data['to_address'] : '',
        'contract_address' => isset($data['contract_address']) ? (string) $data['contract_address'] : '',
        'raw_amount' => isset($data['raw_amount']) ? (string) $data['raw_amount'] : '0',
        'amount_usdt' => isset($data['amount_usdt']) ? (string) $data['amount_usdt'] : '0.000000',
        'amount_micro' => isset($data['amount_micro']) ? (int) $data['amount_micro'] : 0,
        'block_number' => isset($data['block_number']) ? $data['block_number'] : null,
        'block_timestamp' => isset($data['block_timestamp']) ? $data['block_timestamp'] : null,
        'confirmations' => isset($data['confirmations']) ? (int) $data['confirmations'] : 0,
        'status' => (string) $status,
        'raw_json' => isset($data['raw_json']) ? owppgwu_json($data['raw_json']) : null,
        'processed_at' => owppgwu_now(),
        'updated_at' => owppgwu_now(),
    ];
}

function owppgwu_store_processed_transaction(array $data)
{
    owppgwu_ensure_schema();

    $txid = (string) $data['txid'];
    $status = (string) $data['status'];
    $existing = owppgwu_find_processed_transaction($txid);
    $row = owppgwu_processed_transaction_row($data, $status);

    if ($existing) {
        $mutableStatuses = ['received', 'waiting_confirmations', 'confirmations_unavailable'];
        if (!in_array($existing->status, $mutableStatuses, true) && $status !== 'processed') {
            return $existing;
        }

        Capsule::table(owppgwu_processed_transactions_table())
            ->where('txid', $txid)
            ->update($row);

        return owppgwu_find_processed_transaction($txid);
    }

    $row['txid'] = $txid;
    $row['created_at'] = owppgwu_now();
    try {
        Capsule::table(owppgwu_processed_transactions_table())->insert($row);
    } catch (Exception $exception) {
        $existing = owppgwu_find_processed_transaction($txid);
        if ($existing) {
            return $existing;
        }

        throw $exception;
    }

    return owppgwu_find_processed_transaction($txid);
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

function owppgwu_invoice_payability_problem($intent, $lockForUpdate = false)
{
    try {
        $snapshot = owppgwu_fetch_invoice_snapshot((int) $intent->invoice_id, $lockForUpdate);
    } catch (Exception $exception) {
        return $exception->getMessage() === 'invoice_not_found' ? 'invoice_not_payable' : $exception->getMessage();
    }

    if ($snapshot->status !== 'Unpaid') {
        return 'invoice_not_payable';
    }

    if (strtoupper((string) $intent->invoice_currency) !== $snapshot->currency_code) {
        return 'invoice_amount_changed';
    }

    $intentBalanceMicro = owppgwu_decimal_to_micro($intent->invoice_balance_snapshot, true);
    if ((int) $snapshot->balance_micro !== $intentBalanceMicro) {
        return 'invoice_amount_changed';
    }

    if ($intentBalanceMicro <= 0) {
        return 'invoice_not_payable';
    }

    return null;
}

function owppgwu_claim_intent_for_transaction(array $transactionData, $intent)
{
    owppgwu_ensure_schema();
    owppgwu_store_processed_transaction(array_merge($transactionData, ['status' => 'received']));

    return Capsule::connection()->transaction(function () use ($transactionData, $intent) {
        $txid = (string) $transactionData['txid'];
        $now = owppgwu_now();
        $transaction = Capsule::table(owppgwu_processed_transactions_table())
            ->where('txid', $txid)
            ->lockForUpdate()
            ->first();

        if (!$transaction) {
            return ['claimed' => false, 'status' => 'transaction_missing'];
        }

        if ($transaction->status === 'processed') {
            return ['claimed' => false, 'status' => 'duplicate_processed'];
        }

        if ($transaction->status === 'processing') {
            return ['claimed' => false, 'status' => 'duplicate_processing'];
        }

        if (!in_array($transaction->status, ['received', 'waiting_confirmations', 'confirmations_unavailable'], true)) {
            return ['claimed' => false, 'status' => $transaction->status];
        }

        $intentRow = Capsule::table(owppgwu_intents_table())
            ->where('id', (int) $intent->id)
            ->lockForUpdate()
            ->first();

        if (!$intentRow || $intentRow->status !== 'pending' || $intentRow->txid !== null || strtotime($intentRow->expires_at) < time()) {
            Capsule::table(owppgwu_processed_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_processed_transaction_row($transactionData, 'request_already_claimed'), [
                    'intent_id' => $intentRow ? (int) $intentRow->id : null,
                    'invoice_id' => $intentRow ? (int) $intentRow->invoice_id : null,
                ]));

            return ['claimed' => false, 'status' => 'request_already_claimed'];
        }

        $payabilityProblem = owppgwu_invoice_payability_problem($intentRow, true);
        if ($payabilityProblem) {
            Capsule::table(owppgwu_processed_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_processed_transaction_row($transactionData, $payabilityProblem), [
                    'intent_id' => (int) $intentRow->id,
                    'invoice_id' => (int) $intentRow->invoice_id,
                ]));

            return ['claimed' => false, 'status' => $payabilityProblem];
        }

        if (owppgwu_whmcs_transaction_exists($txid)) {
            Capsule::table(owppgwu_processed_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_processed_transaction_row($transactionData, 'duplicate_whmcs_transaction'), [
                    'intent_id' => (int) $intentRow->id,
                    'invoice_id' => (int) $intentRow->invoice_id,
                ]));

            return ['claimed' => false, 'status' => 'duplicate_whmcs_transaction'];
        }

        $updated = Capsule::table(owppgwu_intents_table())
            ->where('id', (int) $intentRow->id)
            ->where('status', 'pending')
            ->whereNull('txid')
            ->where('expires_at', '>=', $now)
            ->update([
                'txid' => $txid,
                'active_amount_key' => null,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            Capsule::table(owppgwu_processed_transactions_table())
                ->where('txid', $txid)
                ->update(array_merge(owppgwu_processed_transaction_row($transactionData, 'request_already_claimed'), [
                    'intent_id' => (int) $intentRow->id,
                    'invoice_id' => (int) $intentRow->invoice_id,
                ]));

            return ['claimed' => false, 'status' => 'request_already_claimed'];
        }

        Capsule::table(owppgwu_processed_transactions_table())
            ->where('txid', $txid)
            ->update(array_merge(owppgwu_processed_transaction_row($transactionData, 'processing'), [
                'intent_id' => (int) $intentRow->id,
                'invoice_id' => (int) $intentRow->invoice_id,
            ]));

        $intentRow->txid = $txid;

        return [
            'claimed' => true,
            'status' => 'processing',
            'intent' => $intentRow,
        ];
    });
}

function owppgwu_finalize_payment_success($intentId, $txid)
{
    $now = owppgwu_now();

    Capsule::table(owppgwu_intents_table())
        ->where('id', (int) $intentId)
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'paid',
            'active_amount_key' => null,
            'paid_at' => $now,
            'updated_at' => $now,
        ]);

    Capsule::table(owppgwu_processed_transactions_table())
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'processed',
            'processed_at' => $now,
            'updated_at' => $now,
        ]);
}

function owppgwu_finalize_payment_failure($intentId, $txid, $error)
{
    $now = owppgwu_now();

    Capsule::table(owppgwu_intents_table())
        ->where('id', (int) $intentId)
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'failed',
            'active_amount_key' => null,
            'updated_at' => $now,
        ]);

    Capsule::table(owppgwu_processed_transactions_table())
        ->where('txid', (string) $txid)
        ->update([
            'status' => 'payment_failed',
            'raw_json' => owppgwu_json(['error' => (string) $error]),
            'processed_at' => $now,
            'updated_at' => $now,
        ]);
}

function owppgwu_get_nested_value(array $row, array $paths)
{
    foreach ($paths as $path) {
        $value = $row;
        $found = true;

        foreach (explode('.', $path) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                $found = false;
                break;
            }
        }

        if ($found && $value !== null && $value !== '') {
            return $value;
        }
    }

    return null;
}

function owppgwu_extract_transfer_rows(array $response)
{
    foreach (['token_transfers', 'data', 'transfers', 'items'] as $key) {
        if (isset($response[$key]) && is_array($response[$key])) {
            return $response[$key];
        }
    }

    return [];
}

function owppgwu_transfer_to_micro(array $row)
{
    $raw = owppgwu_get_nested_value($row, ['raw_amount', 'quant', 'value']);
    if ($raw !== null && preg_match('/^[0-9]+$/', (string) $raw)) {
        return [(int) $raw, (string) $raw];
    }

    $amount = owppgwu_get_nested_value($row, ['amount', 'amount_usdt']);
    if ($amount !== null) {
        $micro = owppgwu_decimal_to_micro($amount, false);
        return [$micro, (string) $micro];
    }

    throw new RuntimeException('transfer_amount_missing');
}

function owppgwu_normalize_tronscan_transfer(array $row)
{
    list($amountMicro, $rawAmount) = owppgwu_transfer_to_micro($row);
    $blockNumber = owppgwu_get_nested_value($row, ['block', 'block_number', 'blockNumber']);
    $blockTimestamp = owppgwu_get_nested_value($row, ['block_ts', 'block_timestamp', 'timestamp', 'time']);

    return [
        'txid' => strtolower((string) owppgwu_get_nested_value($row, ['transaction_id', 'hash', 'txid', 'transactionHash'])),
        'from_address' => (string) owppgwu_get_nested_value($row, ['from_address', 'from', 'transferFromAddress']),
        'to_address' => (string) owppgwu_get_nested_value($row, ['to_address', 'to', 'transferToAddress']),
        'contract_address' => (string) owppgwu_get_nested_value($row, ['trc20Id', 'contract_address', 'contract', 'tokenInfo.tokenId', 'tokenInfo.address']),
        'event_type' => (string) owppgwu_get_nested_value($row, ['event_type', 'eventType', 'event_name']),
        'contract_ret' => (string) owppgwu_get_nested_value($row, ['contract_ret', 'contractRet', 'contract_result']),
        'revert' => owppgwu_get_nested_value($row, ['revert', 'reverted']),
        'raw_amount' => $rawAmount,
        'amount_micro' => $amountMicro,
        'amount_usdt' => owppgwu_micro_to_decimal($amountMicro, 6),
        'block_number' => $blockNumber === null ? null : (int) $blockNumber,
        'block_timestamp' => $blockTimestamp === null ? null : (int) $blockTimestamp,
        'raw_json' => $row,
    ];
}

function owppgwu_transfer_revert_is_zero($value)
{
    if ($value === 0 || $value === '0' || $value === false || $value === 'false') {
        return true;
    }

    return false;
}

function owppgwu_transfer_base_data(array $transfer, $status)
{
    return [
        'txid' => $transfer['txid'],
        'invoice_id' => isset($transfer['invoice_id']) ? $transfer['invoice_id'] : null,
        'intent_id' => isset($transfer['intent_id']) ? $transfer['intent_id'] : null,
        'from_address' => $transfer['from_address'],
        'to_address' => $transfer['to_address'],
        'contract_address' => $transfer['contract_address'],
        'raw_amount' => $transfer['raw_amount'],
        'amount_usdt' => $transfer['amount_usdt'],
        'amount_micro' => $transfer['amount_micro'],
        'block_number' => $transfer['block_number'],
        'block_timestamp' => $transfer['block_timestamp'],
        'confirmations' => isset($transfer['confirmations']) ? $transfer['confirmations'] : 0,
        'status' => $status,
        'raw_json' => $transfer['raw_json'],
    ];
}

function owppgwu_process_observed_transfer(array $transfer, array $params, $latestBlockNumber, $gatewayModuleName)
{
    if (!preg_match('/^[a-f0-9]{64}$/', $transfer['txid'])) {
        return 'invalid_txid';
    }

    $existingTx = owppgwu_find_processed_transaction($transfer['txid']);
    if ($existingTx && $existingTx->status === 'processed') {
        return 'duplicate_processed';
    }

    if ($existingTx && $existingTx->status === 'processing') {
        return 'duplicate_processing';
    }

    $terminalStatuses = [
        'wrong_address',
        'wrong_contract',
        'invalid_transfer_event',
        'failed_contract_result',
        'reverted_transfer',
        'unmatched',
        'conflict',
        'invoice_not_payable',
        'invoice_amount_changed',
        'request_already_claimed',
        'duplicate_whmcs_transaction',
        'payment_failed',
    ];

    if ($existingTx && in_array($existingTx->status, $terminalStatuses, true)) {
        return $existingTx->status;
    }

    $expectedAddress = owppgwu_gateway_setting($params, 'trc20Address');
    $expectedContract = owppgwu_gateway_setting($params, 'usdtContract', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');

    if (!owppgwu_same_address($transfer['to_address'], $expectedAddress)) {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'wrong_address'));
        return 'wrong_address';
    }

    if (!owppgwu_same_address($transfer['contract_address'], $expectedContract)) {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'wrong_contract'));
        return 'wrong_contract';
    }

    if ($transfer['event_type'] !== 'Transfer') {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'invalid_transfer_event'));
        return 'invalid_transfer_event';
    }

    if ($transfer['contract_ret'] !== 'SUCCESS') {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'failed_contract_result'));
        return 'failed_contract_result';
    }

    if (!owppgwu_transfer_revert_is_zero($transfer['revert'])) {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'reverted_transfer'));
        return 'reverted_transfer';
    }

    if (!$latestBlockNumber || !$transfer['block_number']) {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'confirmations_unavailable'));
        return 'confirmations_unavailable';
    }

    $confirmations = (int) $latestBlockNumber - (int) $transfer['block_number'];
    if ($confirmations < 0) {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'confirmations_unavailable'));
        return 'confirmations_unavailable';
    }

    $transfer['confirmations'] = $confirmations;
    $requiredConfirmations = owppgwu_gateway_int($params, 'requiredConfirmations', 12, 0, 1000);

    if ($confirmations < $requiredConfirmations) {
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, 'waiting_confirmations'));
        return 'waiting_confirmations';
    }

    $matches = owppgwu_active_intents_by_amount($transfer['amount_micro']);
    if (count($matches) !== 1) {
        $status = count($matches) === 0 ? 'unmatched' : 'conflict';
        owppgwu_store_processed_transaction(owppgwu_transfer_base_data($transfer, $status));
        return $status;
    }

    $intent = $matches[0];
    $transfer['invoice_id'] = (int) $intent->invoice_id;
    $transfer['intent_id'] = (int) $intent->id;
    $claim = owppgwu_claim_intent_for_transaction(owppgwu_transfer_base_data($transfer, 'received'), $intent);

    if (empty($claim['claimed'])) {
        return $claim['status'];
    }

    $claimedIntent = $claim['intent'];

    try {
        addInvoicePayment(
            (int) $claimedIntent->invoice_id,
            $transfer['txid'],
            (string) $claimedIntent->invoice_balance_snapshot,
            0,
            $gatewayModuleName
        );

        owppgwu_finalize_payment_success((int) $claimedIntent->id, $transfer['txid']);
        return 'paid';
    } catch (Exception $exception) {
        owppgwu_finalize_payment_failure((int) $claimedIntent->id, $transfer['txid'], $exception->getMessage());
        return 'payment_failed';
    }
}

function owppgwu_http_get_json($url, array $query, array $headers = [])
{
    $fullUrl = $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($fullUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headerLines);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('tronscan_http_error:' . ($error ?: $status));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headerLines),
                'timeout' => 20,
            ],
        ]);
        $body = file_get_contents($fullUrl, false, $context);
        if ($body === false) {
            throw new RuntimeException('tronscan_http_error');
        }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('tronscan_invalid_json');
    }

    return $decoded;
}

function owppgwu_extract_latest_block_number(array $response)
{
    $rows = [];
    if (isset($response['data']) && is_array($response['data'])) {
        $rows = isset($response['data'][0]) ? $response['data'] : [$response['data']];
    }

    if (isset($response[0]) && is_array($response[0])) {
        $rows = $response;
    }

    if (!$rows) {
        return null;
    }

    $row = $rows[0];
    $number = owppgwu_get_nested_value($row, ['number', 'block', 'block_number', 'blockNumber', 'block_header.raw_data.number']);

    return $number === null ? null : (int) $number;
}

function owppgwu_tronscan_headers(array $params)
{
    return [
        'TRON-PRO-API-KEY' => owppgwu_gateway_setting($params, 'tronscanApiKey'),
    ];
}

function owppgwu_fetch_latest_tron_block(array $params)
{
    $response = owppgwu_http_get_json(OWPPGWU_TRONSCAN_API_BASE . '/block', [
        'sort' => '-number',
        'limit' => 1,
        'start' => 0,
    ], owppgwu_tronscan_headers($params));

    return owppgwu_extract_latest_block_number($response);
}

function owppgwu_fetch_trc20_transfers(array $params, $startTimestamp, $endTimestamp)
{
    return owppgwu_http_get_json(OWPPGWU_TRONSCAN_API_BASE . '/transfer/trc20', [
        'address' => owppgwu_gateway_setting($params, 'trc20Address'),
        'trc20Id' => owppgwu_gateway_setting($params, 'usdtContract', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
        'direction' => 1,
        'reverse' => 'true',
        'db_version' => 1,
        'start' => 0,
        'limit' => 50,
        'start_timestamp' => (int) $startTimestamp,
        'end_timestamp' => (int) $endTimestamp,
    ], owppgwu_tronscan_headers($params));
}

function owppgwu_load_whmcs_payment_functions()
{
    if (class_exists('App')) {
        if (!function_exists('getGatewayVariables')) {
            App::load_function('gateway');
        }

        if (!function_exists('addInvoicePayment')) {
            App::load_function('invoice');
        }
    }
}

function owppgwu_gateway_params()
{
    owppgwu_load_whmcs_payment_functions();

    if (!function_exists('getGatewayVariables')) {
        throw new RuntimeException('whmcs_gateway_functions_missing');
    }

    return getGatewayVariables('owppgwu');
}

function owppgwu_scan_state()
{
    owppgwu_ensure_schema();
    $row = Capsule::table(owppgwu_scan_state_table())
        ->where('name', 'tronscan')
        ->first();

    if ($row) {
        return $row;
    }

    $now = owppgwu_now();
    Capsule::table(owppgwu_scan_state_table())->insert([
        'name' => 'tronscan',
        'last_scan_at_ms' => 0,
        'last_run_at' => null,
        'locked_until' => null,
        'last_error' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Capsule::table(owppgwu_scan_state_table())
        ->where('name', 'tronscan')
        ->first();
}

function owppgwu_claim_scan_lock($intervalMinutes)
{
    return Capsule::connection()->transaction(function () use ($intervalMinutes) {
        $state = Capsule::table(owppgwu_scan_state_table())
            ->where('name', 'tronscan')
            ->lockForUpdate()
            ->first();

        if (!$state) {
            $state = owppgwu_scan_state();
        }

        $nowTs = time();
        if ($state->locked_until && strtotime($state->locked_until) > $nowTs) {
            return false;
        }

        if ($state->last_run_at && strtotime($state->last_run_at) > ($nowTs - ($intervalMinutes * 60))) {
            return false;
        }

        Capsule::table(owppgwu_scan_state_table())
            ->where('name', 'tronscan')
            ->update([
                'locked_until' => date('Y-m-d H:i:s', $nowTs + 240),
                'updated_at' => owppgwu_now(),
            ]);

        return true;
    });
}

function owppgwu_release_scan_lock($lastScanAtMs = null, $error = null)
{
    $row = [
        'last_run_at' => owppgwu_now(),
        'locked_until' => null,
        'last_error' => $error,
        'updated_at' => owppgwu_now(),
    ];

    if ($lastScanAtMs !== null) {
        $row['last_scan_at_ms'] = (int) $lastScanAtMs;
    }

    Capsule::table(owppgwu_scan_state_table())
        ->where('name', 'tronscan')
        ->update($row);
}

function owppgwu_cron_poll_tronscan()
{
    owppgwu_ensure_schema();
    owppgwu_expire_stale_intents();
    owppgwu_load_whmcs_payment_functions();

    $params = owppgwu_gateway_params();
    if (empty($params['type'])) {
        return ['status' => 'gateway_not_active'];
    }

    if (owppgwu_gateway_setting($params, 'trc20Address') === '' || owppgwu_gateway_setting($params, 'tronscanApiKey') === '') {
        return ['status' => 'scan_not_configured'];
    }

    $intervalMinutes = owppgwu_gateway_int($params, 'scanIntervalMinutes', 5, 1, 1440);
    $overlapMinutes = owppgwu_gateway_int($params, 'scanOverlapMinutes', 30, 1, 1440);
    owppgwu_scan_state();

    if (!owppgwu_claim_scan_lock($intervalMinutes)) {
        return ['status' => 'scan_skipped_interval_or_locked'];
    }

    $endTimestamp = (int) floor(microtime(true) * 1000);
    $state = Capsule::table(owppgwu_scan_state_table())->where('name', 'tronscan')->first();
    $lastScanAt = $state ? (int) $state->last_scan_at_ms : 0;
    $startTimestamp = $lastScanAt > 0
        ? max(0, $lastScanAt - ($overlapMinutes * 60 * 1000))
        : max(0, $endTimestamp - ($overlapMinutes * 60 * 1000));

    try {
        $latestBlock = owppgwu_fetch_latest_tron_block($params);
        if (!$latestBlock) {
            throw new RuntimeException('latest_block_unavailable');
        }

        $response = owppgwu_fetch_trc20_transfers($params, $startTimestamp, $endTimestamp);
        $rows = owppgwu_extract_transfer_rows($response);
        $results = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            try {
                $transfer = owppgwu_normalize_tronscan_transfer($row);
                $status = owppgwu_process_observed_transfer($transfer, $params, $latestBlock, 'owppgwu');
                $results[$transfer['txid']] = $status;
            } catch (Exception $exception) {
                $results['invalid:' . count($results)] = $exception->getMessage();
            }
        }

        owppgwu_release_scan_lock($endTimestamp, null);
        return [
            'status' => 'scanned',
            'start_timestamp' => $startTimestamp,
            'end_timestamp' => $endTimestamp,
            'latest_block' => $latestBlock,
            'results' => $results,
        ];
    } catch (Exception $exception) {
        owppgwu_release_scan_lock(null, $exception->getMessage());
        return [
            'status' => 'scan_failed',
            'error' => $exception->getMessage(),
        ];
    }
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
            'note' => 'USD is treated as 1:1 USDT. WHMCS cron scans TronScan API for payment. The current amount is valid for 30 minutes. Time left:',
            'slot_note' => 'Calibration slot: +{slot} USDT.',
            'rate_note' => '{source} -> USD from WHMCS currency table. Source rate: {source_rate}; USD rate: {usd_rate}.',
            'payment_unavailable' => 'USDT TRC20 payment is temporarily unavailable: {reason}',
            'slots_full' => 'All 0.01 calibration slots for this amount are currently in use. Refresh the invoice page later.',
            'trc20_address_missing' => 'TRC20 receiving address is not configured.',
            'tronscan_api_key_missing' => 'TronScan API key is not configured.',
            'usdt_contract_missing' => 'USDT TRC20 contract address is not configured.',
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
            'note' => 'USD 按 1:1 USDT 处理。WHMCS cron 会轮询 TronScan API 确认到账。当前金额 30 分钟内有效，剩余：',
            'slot_note' => '校准尾数：+{slot} USDT。',
            'rate_note' => '{source} -> USD，来源为 WHMCS currency table。源币种 rate：{source_rate}；USD rate：{usd_rate}。',
            'payment_unavailable' => 'USDT TRC20 支付暂不可用：{reason}',
            'slots_full' => '当前 30 分钟内相同基础金额的 0.01 校准槽位已满，请稍后刷新账单页面。',
            'trc20_address_missing' => '尚未配置 TRC20 收款地址。',
            'tronscan_api_key_missing' => '尚未配置 TronScan API Key。',
            'usdt_contract_missing' => '尚未配置 USDT TRC20 合约地址。',
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
