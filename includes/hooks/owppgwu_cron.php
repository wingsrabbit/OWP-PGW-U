<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../modules/gateways/owppgwu/lib.php';

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        $result = owppgwu_cron_poll_tronscan();

        if (function_exists('logModuleCall')) {
            logModuleCall(
                'owppgwu',
                'tronscan_cron',
                isset($result['start_timestamp']) ? $result['start_timestamp'] : '',
                $result,
                isset($result['status']) ? $result['status'] : 'unknown'
            );
        }
    } catch (Exception $exception) {
        if (function_exists('logModuleCall')) {
            logModuleCall('owppgwu', 'tronscan_cron', '', '', $exception->getMessage());
        }
    }
});
