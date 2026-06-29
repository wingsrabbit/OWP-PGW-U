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

function owppgwu_ensure_schema()
{
    $schema = Capsule::schema();

    if (!$schema->hasTable(owppgwu_requests_table())) {
        $schema->create(owppgwu_requests_table(), function ($table) {
            $table->increments('id');
            $table->integer('invoice_id')->index();
            $table->decimal('invoice_amount', 16, 6);
            $table->bigInteger('base_amount_micro')->index();
            $table->bigInteger('display_amount_micro')->index();
            $table->tinyInteger('slot');
            $table->string('address', 128);
            $table->string('active_amount_key', 64)->nullable()->unique('uniq_owppgwu_active_amount');
            $table->string('status', 24)->default('pending')->index();
            $table->string('txid', 128)->nullable()->index();
            $table->dateTime('expires_at')->index();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    if (!$schema->hasTable(owppgwu_transactions_table())) {
        $schema->create(owppgwu_transactions_table(), function ($table) {
            $table->increments('id');
            $table->string('txid', 128)->unique();
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
    }
}

function owppgwu_decimal_to_micro($amount, $ceilExcessPrecision = false)
{
    $amount = str_replace(',', '', trim((string) $amount));

    if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $amount, $matches)) {
        throw new InvalidArgumentException('Invalid decimal amount');
    }

    $whole = (int) $matches[1];
    $fraction = isset($matches[2]) ? $matches[2] : '';
    $firstSix = substr($fraction, 0, 6);
    $extra = substr($fraction, 6);

    if (!$ceilExcessPrecision && preg_match('/[1-9]/', $extra)) {
        throw new InvalidArgumentException('USDT amount has more than 6 non-zero decimals');
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

function owppgwu_ceil_to_step_micro($micro, $step)
{
    $micro = (int) $micro;

    if ($micro <= 0) {
        return 0;
    }

    return intdiv($micro + $step - 1, $step) * $step;
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

function owppgwu_create_payment_request($invoiceId, $invoiceAmount, array $params)
{
    owppgwu_expire_stale_requests();

    $address = owppgwu_gateway_setting($params, 'trc20Address');
    if ($address === '') {
        throw new RuntimeException('TRC20 receiving address is not configured');
    }

    $invoiceMicro = owppgwu_decimal_to_micro($invoiceAmount, true);
    if ($invoiceMicro <= 0) {
        throw new RuntimeException('Invoice amount must be greater than zero');
    }

    $baseMicro = owppgwu_ceil_to_step_micro($invoiceMicro, OWPPGWU_TENTH_MICRO);
    $windowMinutes = owppgwu_gateway_int($params, 'invoiceWindowMinutes', 30, 1, 1440);
    $slots = owppgwu_gateway_int($params, 'calibrationSlots', 9, 1, 9);
    $expiresAt = date('Y-m-d H:i:s', time() + ($windowMinutes * 60));
    $now = owppgwu_now();

    for ($slot = 1; $slot <= $slots; $slot++) {
        $displayMicro = $baseMicro + ($slot * OWPPGWU_CENT_MICRO);
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
                'invoice_amount' => owppgwu_micro_to_decimal($invoiceMicro, 6),
                'base_amount_micro' => $baseMicro,
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

function owppgwu_get_or_create_payment_request($invoiceId, $invoiceAmount, array $params)
{
    $existing = owppgwu_active_request_for_invoice($invoiceId);

    if ($existing) {
        $currentInvoiceMicro = owppgwu_decimal_to_micro($invoiceAmount, true);
        $existingInvoiceMicro = owppgwu_decimal_to_micro($existing->invoice_amount, true);

        if ($currentInvoiceMicro !== $existingInvoiceMicro) {
            Capsule::table(owppgwu_requests_table())
                ->where('id', (int) $existing->id)
                ->update([
                    'status' => 'expired',
                    'active_amount_key' => null,
                    'updated_at' => owppgwu_now(),
                ]);

            return owppgwu_create_payment_request($invoiceId, $invoiceAmount, $params);
        }

        return $existing;
    }

    return owppgwu_create_payment_request($invoiceId, $invoiceAmount, $params);
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

    if ($rows instanceof Illuminate\Support\Collection) {
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

function owppgwu_store_transaction(array $data)
{
    owppgwu_ensure_schema();

    $txid = (string) $data['txid'];
    $now = owppgwu_now();
    $existing = owppgwu_find_transaction($txid);
    $row = [
        'invoice_id' => isset($data['invoice_id']) ? $data['invoice_id'] : null,
        'from_address' => isset($data['from_address']) ? $data['from_address'] : null,
        'to_address' => (string) $data['to_address'],
        'contract_address' => (string) $data['contract_address'],
        'amount_micro' => (int) $data['amount_micro'],
        'confirmations' => (int) $data['confirmations'],
        'status' => (string) $data['status'],
        'payload' => isset($data['payload']) ? owppgwu_json($data['payload']) : null,
        'updated_at' => $now,
    ];

    if ($existing) {
        Capsule::table(owppgwu_transactions_table())
            ->where('txid', $txid)
            ->update($row);

        return owppgwu_find_transaction($txid);
    }

    $row['txid'] = $txid;
    $row['created_at'] = $now;

    Capsule::table(owppgwu_transactions_table())->insert($row);

    return owppgwu_find_transaction($txid);
}

function owppgwu_mark_request_paid($requestId, $txid)
{
    return Capsule::table(owppgwu_requests_table())
        ->where('id', (int) $requestId)
        ->update([
            'status' => 'paid',
            'txid' => (string) $txid,
            'active_amount_key' => null,
            'paid_at' => owppgwu_now(),
            'updated_at' => owppgwu_now(),
        ]);
}

function owppgwu_same_address($left, $right)
{
    return trim((string) $left) === trim((string) $right);
}

function owppgwu_html_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
