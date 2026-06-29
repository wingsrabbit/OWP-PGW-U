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
    try {
        owppgwu_ensure_schema();
        $payment = owppgwu_get_or_create_payment_request($params['invoiceid'], $params['amount'], $params);
    } catch (Exception $exception) {
        return '<div class="alert alert-danger">USDT TRC20 支付暂不可用：'
            . owppgwu_html_escape($exception->getMessage())
            . '</div>';
    }

    if (!$payment) {
        return '<div class="alert alert-warning">'
            . '当前 30 分钟内相同基础金额的付款槽位已满，请稍后刷新账单页面重新获取金额。'
            . '</div>';
    }

    $amount = owppgwu_micro_to_decimal($payment->display_amount_micro, 2);
    $baseAmount = owppgwu_micro_to_decimal($payment->base_amount_micro, 2);
    $slot = (int) $payment->slot;
    $address = owppgwu_gateway_setting($params, 'trc20Address');
    $expiresAt = (string) $payment->expires_at;
    $supportUrl = owppgwu_gateway_setting($params, 'supportUrl');
    $remainingSeconds = max(0, strtotime($expiresAt) - time());
    $id = 'owppgwu-' . (int) $params['invoiceid'];

    $supportHtml = $supportUrl === ''
        ? '未精确付款时，请开工单联系客服处理。'
        : '未精确付款时，请 <a href="' . owppgwu_html_escape($supportUrl) . '" target="_blank" rel="noopener">开工单联系客服</a> 处理。';
    $amountHtml = owppgwu_html_escape($amount);
    $baseAmountHtml = owppgwu_html_escape($baseAmount);
    $addressHtml = owppgwu_html_escape($address);
    $slotHtml = owppgwu_html_escape(sprintf('%02d', $slot));

    return <<<HTML
<div id="{$id}" class="owppgwu-box">
    <style>
        .owppgwu-box {
            border: 1px solid #d7dde8;
            border-radius: 8px;
            padding: 18px;
            margin: 16px 0;
            background: #fff;
            color: #172033;
            max-width: 720px;
        }
        .owppgwu-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .owppgwu-row {
            margin: 12px 0;
        }
        .owppgwu-label {
            font-size: 13px;
            color: #667085;
            margin-bottom: 4px;
        }
        .owppgwu-value {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.4;
            word-break: break-all;
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
    <div class="owppgwu-title">USDT TRC20 支付</div>
    <div class="owppgwu-row">
        <div class="owppgwu-label">请精确支付金额</div>
        <div class="owppgwu-value" data-owppgwu-amount>{$amountHtml} USDT</div>
    </div>
    <div class="owppgwu-row">
        <div class="owppgwu-label">TRC20 收款地址</div>
        <div class="owppgwu-address" data-owppgwu-address>{$addressHtml}</div>
    </div>
    <div class="owppgwu-actions">
        <button type="button" class="owppgwu-button" data-owppgwu-copy="amount">复制金额</button>
        <button type="button" class="owppgwu-button" data-owppgwu-copy="address">复制地址</button>
    </div>
    <div class="owppgwu-warning">
        必须使用 TRC20 网络，并精确支付 {$amountHtml} USDT。多付、少付或未按精确金额付款不会自动入账，{$supportHtml}
    </div>
    <div class="owppgwu-note">
        账单原始应付金额先向上取到 0.1 USDT（本次基础金额 {$baseAmountHtml}），再分配 +0.{$slotHtml} USDT 校准尾数。当前金额 30 分钟内有效，剩余 <span data-owppgwu-countdown data-seconds="{$remainingSeconds}"></span>。
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
                button.textContent = '已复制';
                setTimeout(function () {
                    button.textContent = type === 'amount' ? '复制金额' : '复制地址';
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
            countdown.textContent = minutes + ' 分 ' + String(rest).padStart(2, '0') + ' 秒';
            seconds -= 1;
        }

        renderCountdown();
        setInterval(renderCountdown, 1000);
    })();
    </script>
</div>
HTML;
}
