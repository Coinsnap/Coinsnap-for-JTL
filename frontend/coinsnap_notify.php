<?php

declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment;

use JTL\Plugin\Payment\LegacyMethod;
use JTL\Plugin\Helper;
use JTL\Shop;
use Plugin\jtl_coinsnappayment\paymentmethod\CoinsnapPayment;

require_once(__DIR__ . '/../library/autoload.php');

\ob_start();
\error_reporting(0);
\ini_set('display_errors', 0);

$bootstrapper = __DIR__ . '/../../../includes/globalinclude.php';

$reply = static function (int $status): void {
    \ob_end_clean();
    \http_response_code($status);
    echo $status === 200 ? 'OK' : ($status === 401 ? 'Unauthorized' : 'Retry');
    exit;
};

if (!\file_exists($bootstrapper)) {
    $reply(503);
}

require_once $bootstrapper;

$oPlugin = Helper::getPluginById('jtl_coinsnappayment');
if ($oPlugin === null) {
    Shop::Container()->getLogService()->error("Coinsnap Notify: Plugin 'jtl_coinsnappayment' not found");
    $reply(503);
}

$payment = LegacyMethod::create('kPlugin_' . $oPlugin->getID() . '_coinsnappayment');
if (!$payment instanceof CoinsnapPayment) {
    Shop::Container()->getLogService()->error('Coinsnap Notify: payment method not found');
    $reply(503);
}

// Only the invoice id is taken from the request; the invoice's state comes from the Coinsnap API.
$reply($payment->processWebhook(
    (string) \file_get_contents('php://input'),
    (string) ($_SERVER['HTTP_X_COINSNAP_SIGNATURE'] ?? '')
));
