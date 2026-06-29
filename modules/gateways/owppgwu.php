<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/owppgwu/lib.php';

function owppgwu_MetaData()
{
    return [
        'DisplayName' => 'OWP PGW U - USDT TRC20',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function owppgwu_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'OWP PGW U - USDT TRC20',
        ],
        'trc20Address' => [
            'FriendlyName' => 'TRC20 USDT 收款地址',
            'Type' => 'text',
            'Size' => '64',
            'Description' => '唯一收款地址。客户付款必须使用 TRC20 网络。',
        ],
        'usdtContract' => [
            'FriendlyName' => 'USDT TRC20 合约地址',
            'Type' => 'text',
            'Size' => '64',
            'Default' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'Description' => '默认是 Tether USDt on TRON 合约地址。',
        ],
        'webhookSecret' => [
            'FriendlyName' => '回调 HMAC 密钥',
            'Type' => 'password',
            'Size' => '64',
            'Description' => '监听服务回调时使用 X-OWP-Signature: sha256=&lt;hmac&gt; 签名。',
        ],
        'requiredConfirmations' => [
            'FriendlyName' => '最低确认数',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '12',
            'Description' => '回调 payload 的 confirmations 必须大于或等于此值。',
        ],
        'invoiceWindowMinutes' => [
            'FriendlyName' => '账单有效分钟',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '30',
            'Description' => '默认 30 分钟。过期后客户刷新账单页重新分配尾数金额。',
        ],
        'calibrationSlots' => [
            'FriendlyName' => '0.01 校准槽位',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '9',
            'Description' => '1-9。默认给同一基础金额分配 +0.01 到 +0.09。',
        ],
        'supportUrl' => [
            'FriendlyName' => '客服工单链接',
            'Type' => 'text',
            'Size' => '80',
            'Description' => '可选。客户未精确付款时引导打开工单。',
        ],
    ];
}

function owppgwu_link($params)
{
    $language = owppgwu_language($params);

    try {
        owppgwu_ensure_schema();
        $payment = owppgwu_get_or_create_payment_request((int) $params['invoiceid'], $params);
    } catch (Exception $exception) {
        return '<div class="alert alert-danger">'
            . owppgwu_html_escape(owppgwu_t($language, 'payment_unavailable', [
                'reason' => owppgwu_error_message($language, $exception->getMessage()),
            ]))
            . '</div>';
    }

    if (!$payment) {
        return '<div class="alert alert-warning">'
            . owppgwu_html_escape(owppgwu_t($language, 'slots_full'))
            . '</div>';
    }

    $sourceCurrency = owppgwu_currency_by_code($payment->invoice_currency);
    $invoiceAmount = owppgwu_currency_display(
        $payment->invoice_amount,
        $payment->invoice_currency,
        $sourceCurrency && isset($sourceCurrency->prefix) ? $sourceCurrency->prefix : '',
        $sourceCurrency && isset($sourceCurrency->suffix) ? $sourceCurrency->suffix : ''
    );
    $computedAmount = owppgwu_micro_to_decimal($payment->computed_usdt_micro, 6);
    $baseAmount = owppgwu_micro_to_decimal($payment->base_amount_micro, 2);
    $amount = owppgwu_micro_to_decimal($payment->display_amount_micro, 2);
    $slotAmount = owppgwu_micro_to_decimal(((int) $payment->slot) * OWPPGWU_CENT_MICRO, 2);
    $address = owppgwu_gateway_setting($params, 'trc20Address');
    $expiresAt = (string) $payment->expires_at;
    $supportUrl = owppgwu_gateway_setting($params, 'supportUrl');
    $remainingSeconds = max(0, strtotime($expiresAt) - time());
    $id = 'owppgwu-' . (int) $params['invoiceid'];

    $supportHtml = $supportUrl === ''
        ? owppgwu_html_escape(owppgwu_t($language, 'support_plain'))
        : '<a href="' . owppgwu_html_escape($supportUrl) . '" target="_blank" rel="noopener">'
            . owppgwu_html_escape(owppgwu_t($language, 'support_link'))
            . '</a>';

    $labels = [
        'title' => owppgwu_html_escape(owppgwu_t($language, 'title')),
        'invoice_amount' => owppgwu_html_escape(owppgwu_t($language, 'invoice_amount')),
        'rate_snapshot' => owppgwu_html_escape(owppgwu_t($language, 'rate_snapshot')),
        'converted_amount' => owppgwu_html_escape(owppgwu_t($language, 'converted_amount')),
        'base_amount' => owppgwu_html_escape(owppgwu_t($language, 'base_amount')),
        'pay_amount' => owppgwu_html_escape(owppgwu_t($language, 'pay_amount')),
        'address' => owppgwu_html_escape(owppgwu_t($language, 'address')),
        'copy_amount' => owppgwu_html_escape(owppgwu_t($language, 'copy_amount')),
        'copy_address' => owppgwu_html_escape(owppgwu_t($language, 'copy_address')),
        'copied' => owppgwu_html_escape(owppgwu_t($language, 'copied')),
    ];
    $warning = owppgwu_t($language, 'warning', [
        'amount' => owppgwu_html_escape($amount),
        'support' => $supportHtml,
    ]);
    $note = owppgwu_html_escape(owppgwu_t($language, 'note'));
    $slotNote = owppgwu_html_escape(owppgwu_t($language, 'slot_note', ['slot' => $slotAmount]));
    $rateNote = owppgwu_html_escape(owppgwu_t($language, 'rate_note', [
        'source' => $payment->invoice_currency,
        'source_rate' => $payment->source_currency_rate,
        'usd_rate' => $payment->usd_currency_rate,
    ]));

    $invoiceAmountHtml = owppgwu_html_escape($invoiceAmount);
    $computedAmountHtml = owppgwu_html_escape($computedAmount);
    $baseAmountHtml = owppgwu_html_escape($baseAmount);
    $amountHtml = owppgwu_html_escape($amount);
    $addressHtml = owppgwu_html_escape($address);

    return <<<HTML
<div id="{$id}" class="owppgwu-box" data-copied-label="{$labels['copied']}" data-copy-amount-label="{$labels['copy_amount']}" data-copy-address-label="{$labels['copy_address']}">
    <style>
        .owppgwu-box {
            border: 1px solid #d7dde8;
            border-radius: 8px;
            padding: 18px;
            margin: 16px 0;
            background: #fff;
            color: #172033;
            max-width: 760px;
        }
        .owppgwu-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .owppgwu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .owppgwu-row {
            margin: 10px 0;
        }
        .owppgwu-label {
            font-size: 13px;
            color: #667085;
            margin-bottom: 4px;
        }
        .owppgwu-value {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.4;
            word-break: break-all;
        }
        .owppgwu-pay {
            font-size: 24px;
            color: #0f766e;
        }
        .owppgwu-address {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 15px;
            background: #f6f8fb;
            border: 1px solid #e3e8f0;
            border-radius: 6px;
            padding: 10px;
            word-break: break-all;
        }
        .owppgwu-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .owppgwu-button {
            border: 1px solid #b8c4d6;
            background: #f9fbff;
            color: #172033;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
        }
        .owppgwu-note {
            color: #667085;
            font-size: 13px;
            line-height: 1.6;
            margin-top: 12px;
        }
        .owppgwu-warning {
            color: #9a3412;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 6px;
            padding: 10px;
            margin-top: 12px;
        }
    </style>
    <div class="owppgwu-title">{$labels['title']}</div>
    <div class="owppgwu-grid">
        <div class="owppgwu-row">
            <div class="owppgwu-label">{$labels['invoice_amount']}</div>
            <div class="owppgwu-value">{$invoiceAmountHtml}</div>
        </div>
        <div class="owppgwu-row">
            <div class="owppgwu-label">{$labels['converted_amount']}</div>
            <div class="owppgwu-value">{$computedAmountHtml} USDT</div>
        </div>
        <div class="owppgwu-row">
            <div class="owppgwu-label">{$labels['base_amount']}</div>
            <div class="owppgwu-value">{$baseAmountHtml} USDT</div>
        </div>
        <div class="owppgwu-row">
            <div class="owppgwu-label">{$labels['pay_amount']}</div>
            <div class="owppgwu-value owppgwu-pay" data-owppgwu-amount>{$amountHtml} USDT</div>
        </div>
    </div>
    <div class="owppgwu-row">
        <div class="owppgwu-label">{$labels['rate_snapshot']}</div>
        <div class="owppgwu-note">{$rateNote}</div>
    </div>
    <div class="owppgwu-row">
        <div class="owppgwu-label">{$labels['address']}</div>
        <div class="owppgwu-address" data-owppgwu-address>{$addressHtml}</div>
    </div>
    <div class="owppgwu-actions">
        <button type="button" class="owppgwu-button" data-owppgwu-copy="amount">{$labels['copy_amount']}</button>
        <button type="button" class="owppgwu-button" data-owppgwu-copy="address">{$labels['copy_address']}</button>
    </div>
    <div class="owppgwu-warning">{$warning}</div>
    <div class="owppgwu-note">
        {$note}<span data-owppgwu-countdown data-seconds="{$remainingSeconds}"></span>
        <br>{$slotNote}
    </div>
    <script>
    (function () {
        var root = document.getElementById('{$id}');
        if (!root) return;

        function copy(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
                return;
            }

            var input = document.createElement('textarea');
            input.value = text;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.focus();
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }

        root.querySelectorAll('[data-owppgwu-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var type = button.getAttribute('data-owppgwu-copy');
                var source = root.querySelector(type === 'amount' ? '[data-owppgwu-amount]' : '[data-owppgwu-address]');
                copy(source ? source.textContent.replace(' USDT', '').trim() : '');
                button.textContent = root.getAttribute('data-copied-label');
                setTimeout(function () {
                    button.textContent = type === 'amount' ? root.getAttribute('data-copy-amount-label') : root.getAttribute('data-copy-address-label');
                }, 1200);
            });
        });

        var countdown = root.querySelector('[data-owppgwu-countdown]');
        var seconds = countdown ? parseInt(countdown.getAttribute('data-seconds'), 10) : 0;

        function renderCountdown() {
            if (!countdown) return;
            var value = Math.max(0, seconds);
            var minutes = Math.floor(value / 60);
            var rest = value % 60;
            countdown.textContent = minutes + 'm ' + String(rest).padStart(2, '0') + 's';
            seconds -= 1;
        }

        renderCountdown();
        setInterval(renderCountdown, 1000);
    })();
    </script>
</div>
HTML;
}
