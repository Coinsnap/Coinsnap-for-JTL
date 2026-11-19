<?php

declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment\paymentmethod;

use JTL\Checkout\Bestellung;
use JTL\Plugin\Data\PaymentMethod;
use JTL\Plugin\Helper as PluginHelper;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\PluginInterface;
use JTL\Shop;
use Plugin\jtl_coinsnappayment\Webhook\InvoiceDecision;
use Plugin\jtl_coinsnappayment\Webhook\Signature;
use Plugin\jtl_coinsnappayment\Webhook\Store;

/**
 * Class CoinsnapPayment
 * @package Plugin\jtl_coinsnappayment\paymentmethod
 */
require_once(__DIR__ . '/../library/autoload.php');
require_once(__DIR__ . '/../Webhook/Signature.php');
require_once(__DIR__ . '/../Webhook/InvoiceDecision.php');
require_once(__DIR__ . '/../Webhook/Store.php');
require_once(__DIR__ . '/InvoiceMetadata.php');

class CoinsnapPayment extends Method
{
    /** @var PluginInterface */
    private PluginInterface $plugin;

    /** @var PaymentMethod|null */
    private ?PaymentMethod $method;

    /** @var bool */
    private bool $payAgain;

    private ?Store $store = null;

    public const WEBHOOK_EVENTS = ['Expired', 'Settled', 'Processing'];
    public const REFERRAL_CODE = 'D18284';
    private const SECURITY_LINES_PER_MINUTE = 10;


    /**
     * @inheritDoc
     */
    public function init(int $nAgainCheckout = 0): self
    {
        parent::init($nAgainCheckout);

        $pluginID = PluginHelper::getIDByModuleID($this->moduleID);
        $this->plugin = PluginHelper::getLoaderByPluginID($pluginID)->init($pluginID);
        $this->method = $this->plugin->getPaymentMethods()->getMethodByID($this->moduleID);
        $this->payAgain = $nAgainCheckout > 0;

        return $this;
    }

    /**
     * @return PaymentMethod
     */
    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    /**
     * @inheritDoc
     */
    public function getSetting(string $key): mixed
    {
        $setting = parent::getSetting($key);

        if ($setting === null) {
            $setting = $this->plugin->getConfig()->getValue($this->getMethod()->getModuleID() . '_' . $key);
        }

        return $setting;
    }

    protected function store(): Store
    {
        return $this->store ??= new Store(Shop::Container()->getDB());
    }

    /**
     * @inheritDoc
     */
    public function isValidIntern(array $args_arr = []): bool
    {
        if ($this->method === null) {
            return false;
        }

        $store_id = $this->getSetting('store_id') ?? '';
        $api_key = $this->getApiKey();

        return parent::isValidIntern($args_arr) && $api_key !== '' && $store_id !== '';
    }

    // "Payment before order completion": JTL places the order only when this returns true.
    // True only when the API reports the invoice paid or being paid, and it is the invoice created for this cart.
    public function finalizeOrder(Bestellung $order, string $hash, array $args): bool
    {
        $invoiceId = (string) ($_SESSION['coinsnap']['response']['id'] ?? '');
        $tempOrderNo = (string) ($_SESSION['coinsnap']['order_no'] ?? '');
        $recorded = ($invoiceId !== '' && $tempOrderNo !== '') ? $this->store()->findInvoice($invoiceId, $tempOrderNo) : null;
        if ($recorded === null) {
            return false;
        }
        // The cart must not have changed since the invoice was created.
        [$amount, $currency] = $this->orderAmount($order);
        if (!InvoiceDecision::sameAmount($amount, $currency, (string) $recorded['amount'], (string) $recorded['currency'])) {
            $this->logInfo('cart changed after the invoice was created', $invoiceId);
            return false;
        }

        try {
            $invoice = (new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey()))->getInvoice($this->getStoreId(), $invoiceId)->getData();
        } catch (\Throwable $e) {
            return false;
        }
        $status = (string) ($invoice['status'] ?? '');
        if (!in_array($status, ['Processing', 'Settled'], true) || !InvoiceDecision::invoiceIsFor($invoice, false, (string) $recorded['amount'], (string) $recorded['currency'])) {
            return false;
        }
        $_SESSION['coinsnap']['invoice_status'] = $status;
        return true;
    }

    /**
     * @inheritDoc
     */
    public function redirectOnPaymentSuccess(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function redirectOnCancel(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function handleNotification(Bestellung $order, string $hash, array $args): void
    {
        $allowedStatuses = ['Processing', 'Settled'];
        if (isset($_SESSION['coinsnap']['invoice_status']) && in_array($_SESSION['coinsnap']['invoice_status'], $allowedStatuses)) {
            $invoiceId = (string) ($_SESSION['coinsnap']['response']['id'] ?? '');
            // The invoice was created for the temporary number of the order JTL has just placed. Only that call
            // ("_" session hash, right after finalizeOrder()) may link it, and only to an order of the same total.
            $tempOrderNo = (string) ($_SESSION['coinsnap']['order_no'] ?? '');
            $recorded = ($invoiceId !== '' && $tempOrderNo !== '') ? $this->store()->findInvoice($invoiceId, $tempOrderNo) : null;
            [$amount, $currency] = $this->orderAmount($order);
            if (str_starts_with($hash, '_') && $recorded !== null && (string) $order->cBestellNr !== ''
                && !$this->store()->orderHasInvoices((string) $order->cBestellNr)
                && InvoiceDecision::sameAmount($amount, $currency, (string) $recorded['amount'], (string) $recorded['currency'])) {
                $this->store()->moveInvoice($invoiceId, $tempOrderNo, (string) $order->cBestellNr);
            }
            // The same checks as the webhook; whichever arrives first marks the order paid.
            $this->settleFromApi($order, $invoiceId);
            unset($_SESSION['coinsnap']);
            $orderHash = $this->generateHash($order);
            $redirectUrl = Shop::Container()->getLinkService()->getStaticRoute('bestellabschluss.php') . '?i=' . $orderHash;
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
            exit;
        }
    }

    /**
     * @inheritDoc
     */
    public function canPayAgain(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function preparePaymentProcess(Bestellung $order): void
    {
        if (!$this->ensureWebhook()) {
            echo 'The payment could not be started. Please try again later.';
            exit;
        }

        if ($this->payAgain) {
            $paymentHash = $this->getOrderHash($order);
            if ($paymentHash === null) {
                $this->getDB()->insert('tbestellid', (object)[
                    'kBestellung' => $order->kBestellung,
                    'cId' => \uniqid('', true)
                ]);
                $paymentHash = $this->generateHash($order);
            }
        } else {
            $paymentHash = $this->generateHash($order);
        }
        $return_url = $this->getReturnURL($order);
        if ($this->duringCheckout) {
            $return_url = $this->getNotificationURL($paymentHash);
        }

        [$amount, $currency] = $this->orderAmount($order);
        $buyerEmail = $order->oRechnungsadresse->cMail;
        $buyerName = $order->oRechnungsadresse->cVorname . ' ' . $order->oRechnungsadresse->cNachname;
        $invoice_no = $order->cBestellNr;

        $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL($return_url);
        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $camount = \Coinsnap\Util\PreciseNumber::parseString($amount);

        // orderId / orderNumber stay cBestellNr: findOrder() and decide() match orders placed before 1.1.0 by the invoice's orderId.
        $metadata = $this->invoiceMetadata($order, (string) $invoice_no);
        $metadata['orderNumber'] = $invoice_no;
        $metadata['customerName'] = $buyerName;
        $metadata['paymentHash'] = $paymentHash;

        try {
            $invoiceData = $client->createInvoice(
                $this->getStoreId(),
                strtoupper($currency),
                $camount,
                $invoice_no,
                $buyerEmail,
                $buyerName,
                $return_url,
                self::REFERRAL_CODE,
                $metadata,
                $checkoutOptions
            )->getData();
        } catch (\Throwable $e) {
            Shop::Container()->getLogService()->error('Coinsnap: invoice creation failed for order ' . $invoice_no . ' (status ' . (int) $e->getCode() . ')');
            $invoiceData = [];
        }

        if (empty($invoiceData['checkoutLink']) || empty($invoiceData['id'])) {
            echo 'The payment could not be started. Please try again later.';
            exit;
        }

        // Only this invoice, for this amount and currency, can pay the order.
        $this->store()->rememberInvoice((string) $invoiceData['id'], (string) $invoice_no, $camount->__toString(), strtoupper($currency));
        // Only for "payment before order completion", where the order does not exist yet; the records are in the database.
        $_SESSION['coinsnap'] = ['response' => ['id' => (string) $invoiceData['id']], 'order_no' => (string) $invoice_no];
        \header('Location: ' . $invoiceData['checkoutLink']);
        exit;
    }

    // Order, customer and line-item data for the invoice; a missing field never stops the checkout.
    protected function invoiceMetadata(Bestellung $order, string $orderNo): array
    {
        $factor = (float) ($order->fWaehrungsFaktor ?? 0) > 0 ? (float) $order->fWaehrungsFaktor : (float) ($order->Waehrung->fFaktor ?? 1);
        try {
            $version = (string) $this->plugin->getMeta()->getVersion();
        } catch (\Throwable $e) {
            $version = '';
        }
        try {
            return InvoiceMetadata::build($order, $orderNo, $factor, (string) ($_SESSION['cISOSprache'] ?? ''), $version);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // The order total in the order's own currency, with that order's currency factor: the session currency
    // can differ (e.g. "pay again" after switching currency). Returns [amount with 2 decimals, ISO code].
    public function orderAmount(Bestellung $order): array
    {
        $factor = (float) ($order->fWaehrungsFaktor ?? 0) > 0 ? (float) $order->fWaehrungsFaktor : (float) ($order->Waehrung->fFaktor ?? 1);
        return [
            number_format((float) $order->fGesamtsumme * $factor, 2, '.', ''),
            strtoupper((string) ($order->Waehrung->cISO ?? '')),
        ];
    }

    // Handles a webhook; returns the HTTP status. Only the invoice id is taken from the request.
    public function processWebhook(string $rawBody, string $signature): int
    {
        $secret = (string) ($this->store()->webhook((string) $this->getStoreId())['secret'] ?? '');
        $problem = Signature::coinsnapProblem($signature, $rawBody, $secret, time());
        if ($problem !== null) {
            $this->logSecurity($problem);
            return 401;
        }

        $body = json_decode($rawBody, true);
        $invoiceId = (is_array($body) && is_string($body['invoiceId'] ?? null)) ? $body['invoiceId'] : '';
        if ($invoiceId === '') {
            return 200;
        }
        $claimsPaid = ($body['type'] ?? '') === 'Settled';

        try {
            $invoice = (new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey()))->getInvoice($this->getStoreId(), $invoiceId)->getData();
        } catch (\Coinsnap\Exception\RequestException $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                // The dashboard's "Send test webhook" button lands here.
                $this->logInfo('unknown invoice', $invoiceId);
                return 200;
            }
            $this->logInfo('retry: API unavailable', $invoiceId);
            return 503;
        } catch (\Throwable $e) {
            $this->logInfo('retry: API unavailable', $invoiceId);
            return 503;
        }

        $order = $this->findOrder($invoiceId, $invoice);
        if ($order === null) {
            $this->logInfo('not ours', $invoiceId);
            return 200;
        }

        $decision = $this->decide($order, $invoice, $invoiceId);
        if ($decision === null) {
            return 200;
        }
        if ($decision !== InvoiceDecision::PAID && $claimsPaid) {
            $this->logSecurity('forged status', $invoiceId);
            return 200;
        }
        if ($decision === InvoiceDecision::PAID) {
            $this->markPaidOnce($order, $invoiceId, $invoice);
        } else {
            $this->logInfo('no change', $invoiceId);
        }
        return 200;
    }

    // The order this plugin created the invoice for, or null when the invoice belongs to something else.
    // The plugin's own record decides; the invoice's orderId is used only for orders placed before 1.1.0 (see decide()).
    protected function findOrder(string $invoiceId, array $invoice): ?Bestellung
    {
        $orderNo = $this->store()->orderNoForInvoice($invoiceId) ?? ($invoice['orderId'] ?? '');
        if (!is_string($orderNo) || $orderNo === '') {
            return null;
        }
        $row = $this->getDB()->select('tbestellung', 'cBestellNr', $orderNo, null, null, null, null, false, 'kBestellung');
        $orderId = (int) ($row->kBestellung ?? 0);
        if ($orderId === 0) {
            return null;
        }
        $order = new Bestellung($orderId);
        $order->fuelleBestellung(false, 0, false);
        if ((int) $order->kBestellung === 0 || (int) $order->kZahlungsart !== (int) $this->getMethod()->getMethodID()) {
            return null;
        }
        return $order;
    }

    // The API's verdict for this order, or null when the invoice must be ignored (already logged).
    private function decide(Bestellung $order, array $invoice, string $invoiceId): ?string
    {
        $orderNo = (string) $order->cBestellNr;
        $expected = $this->store()->findInvoice($invoiceId, $orderNo);
        if ($expected === null) {
            // Orders placed before 1.1.0 have no record. They are accepted for a day after the upgrade, when every
            // invoice opened before it has expired, and only for the invoice that names them; then never again.
            if ($this->store()->orderHasInvoices($orderNo)
                || ($invoice['orderId'] ?? null) !== $orderNo
                || !$this->store()->legacyOrderAccepted((int) $order->kBestellung)) {
                $this->logInfo('not ours', $invoiceId);
                return null;
            }
            [$amount, $currency] = $this->orderAmount($order);
            $expected = ['amount' => $amount, 'currency' => $currency];
        }

        $decision = InvoiceDecision::decide($invoice, false, (string) $expected['amount'], (string) $expected['currency']);
        if ($decision === InvoiceDecision::MISMATCH) {
            $this->logSecurity('amount mismatch', $invoiceId);
            return null;
        }
        return $decision;
    }

    // Marks an open order paid at most once per invoice; paid, shipped or cancelled orders never change.
    protected function markPaidOnce(Bestellung $order, string $invoiceId, array $invoice): void
    {
        if (!\in_array((int) $order->cStatus, [\BESTELLUNG_STATUS_OFFEN, \BESTELLUNG_STATUS_IN_BEARBEITUNG], true)) {
            $this->logInfo('no change from status ' . (int) $order->cStatus, $invoiceId);
            return;
        }
        $orderNo = (string) $order->cBestellNr;
        $expected = $this->store()->findInvoice($invoiceId, $orderNo);
        $amount = (string) ($expected['amount'] ?? ($invoice['invoiceAmount'] ?? ''));
        $currency = (string) ($expected['currency'] ?? ($invoice['currency'] ?? ''));
        // Whether the order is already paid comes from this plugin's records: Wawi moves paid orders back to "in progress".
        $alreadyPaid = $this->store()->orderPaid($orderNo);
        if (!$this->store()->claimPaid($invoiceId, $orderNo, $amount, $currency)) {
            $this->logInfo('already processed', $invoiceId);
            return;
        }
        if ($alreadyPaid) {
            // A second invoice of the same order was paid too: recorded above, the order is left as it is.
            $this->logInfo('order already paid by another invoice, refund manually', $invoiceId);
            return;
        }

        $this->addIncomingPayment($order, (object)['cHinweis' => $invoiceId]);
        $this->setOrderStatusToPaid($order);
        $this->sendConfirmationMail($order);
        $this->logInfo('paid', $invoiceId);
    }

    // Return page: settles the order only when the API confirms the invoice for it.
    private function settleFromApi(Bestellung $order, string $invoiceId): void
    {
        if ($invoiceId === '') {
            return;
        }
        try {
            $invoice = (new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey()))->getInvoice($this->getStoreId(), $invoiceId)->getData();
        } catch (\Throwable $e) {
            return;
        }
        if ($this->decide($order, $invoice, $invoiceId) === InvoiceDecision::PAID) {
            $this->markPaidOnce($order, $invoiceId, $invoice);
        }
    }

    private function logInfo(string $reason, string $invoiceId = ''): void
    {
        Shop::Container()->getLogService()->info($this->webhookLine('INFO', $reason, $invoiceId));
    }

    // At most 10 security lines a minute, so a flood of fake requests can't fill the log.
    private function logSecurity(string $reason, string $invoiceId = ''): void
    {
        $cache = Shop::Container()->getCache();
        $key = 'coinsnap_webhook_seclog_' . gmdate('YmdHi');
        $count = (int) $cache->get($key) + 1;
        $cache->set($key, $count, [], 120);
        if ($count === self::SECURITY_LINES_PER_MINUTE + 1) {
            Shop::Container()->getLogService()->warning('Coinsnap SECURITY webhook: more rejections this minute, not logged');
        }
        if ($count <= self::SECURITY_LINES_PER_MINUTE) {
            Shop::Container()->getLogService()->warning($this->webhookLine('SECURITY', $reason, $invoiceId));
        }
    }

    private function webhookLine(string $level, string $reason, string $invoiceId): string
    {
        // The invoice id comes from the request.
        $id = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId), 0, 64);
        return sprintf('Coinsnap %s webhook reason=%s invoice=%s', $level, $reason, $id !== '' ? $id : '-');
    }

    public function get_webhook_url()
    {
        return Shop::getURL() . '/coinsnap-notify';
    }

    public function getStoreId()
    {

        return $this->getSetting('store_id');
    }

    // Kept in the plugin's own settings as a JTL "encrypted" setting since 1.1.0.
    public function getApiKey(): string
    {
        return (string) ($this->plugin->getConfig()->getDecryptedValue('coinsnap_api_key') ?? '');
    }

    public function getApiUrl()
    {
        return 'https://app.coinsnap.io';
    }

    // True when a webhook with a stored secret is in place, registering one if it is missing.
    public function ensureWebhook(): bool
    {
        try {
            if ($this->webhookExists()) {
                return true;
            }
        } catch (\Throwable $e) {
            // An API error is not "no webhook": registering now would replace a working secret.
            return $this->store()->webhook((string) $this->getStoreId()) !== null;
        }
        return $this->registerWebhook();
    }

    // Throws when the API can't be asked.
    public function webhookExists(): bool
    {
        $stored = $this->store()->webhook((string) $this->getStoreId());
        if ($stored === null) {
            return false;
        }
        try {
            $existing = (new \Coinsnap\Client\Webhook($this->getApiUrl(), $this->getApiKey()))->getWebhook($this->getStoreId(), (string) $stored['webhook_id']);
        } catch (\Coinsnap\Exception\RequestException $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                return false;
            }
            throw $e;
        }
        return ($existing->getData()['url'] ?? '') === $this->get_webhook_url();
    }

    public function registerWebhook(): bool
    {
        $client = new \Coinsnap\Client\Webhook($this->getApiUrl(), $this->getApiKey());
        try {
            $webhook = $client->createWebhook(
                $this->getStoreId(),
                $this->get_webhook_url(),
                self::WEBHOOK_EVENTS,
                null
            )->getData();
        } catch (\Throwable $e) {
            Shop::Container()->getLogService()->error('Coinsnap: webhook could not be registered (status ' . (int) $e->getCode() . ')');
            return false;
        }
        if (empty($webhook['id']) || empty($webhook['secret'])) {
            return false;
        }
        $this->store()->saveWebhook((string) $this->getStoreId(), (string) $webhook['id'], (string) $webhook['secret']);

        // Older versions never stored their webhook's secret; remove those webhooks so only this one delivers.
        try {
            foreach ($client->getWebhooks($this->getStoreId()) as $other) {
                if (($other->getData()['url'] ?? '') === $this->get_webhook_url() && ($other->getData()['id'] ?? '') !== $webhook['id']) {
                    $client->deleteWebhook($this->getStoreId(), (string) $other->getData()['id']);
                }
            }
        } catch (\Throwable $e) {
            Shop::Container()->getLogService()->warning('Coinsnap: older webhooks could not be removed');
        }
        return true;
    }
}
