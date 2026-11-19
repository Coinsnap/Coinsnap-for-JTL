<?php
// Checks the create-invoice request the checkout sends (orderId and metadata).
// Run: php tests/invoice-metadata-test.php   (no JTL-Shop, no network, no database)
// preparePaymentProcess() ends with header() + exit, so each checkout runs in a child process (--child)
// that prints the captured request body.
declare(strict_types=1);

namespace Coinsnap\Http {
    if (in_array('--child', $argv, true)) {
        foreach (['ResponseInterface', 'ClientInterface', 'Response'] as $file) {
            require_once dirname(__DIR__) . '/library/Http/' . $file . '.php';
        }

        // Replaces the library's cURL client: answers every call and keeps the create-invoice body.
        class CurlClient implements ClientInterface
        {
            public static ?string $invoiceBody = null;
            public function setCurlOptions(array $options) {}
            public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface
            {
                if ($method === 'POST' && preg_match('#/invoices$#', $url)) {
                    self::$invoiceBody = $body;
                    return new Response(200, '{"id":"INV-1","checkoutLink":"https://pay.example/i/INV-1"}', []);
                }
                return new Response(404, '{}', []);
            }
        }
    }
}

namespace JTL\Checkout {
    class Bestellung extends \stdClass {}
}

namespace JTL\Plugin {
    interface PluginInterface {}
    class Helper {}
}

namespace JTL\Plugin\Data {
    class PaymentMethod {}
}

namespace JTL\Plugin\Payment {
    class Method
    {
        public $duringCheckout = false;
        public function init(int $n = 0) { return $this; }
        public function getSetting(string $key): mixed { return null; }
        public function isValidIntern(array $a = []): bool { return true; }
        public function generateHash($order): string { return 'HASH1'; }
        public function getReturnURL($order): string { return 'https://shop.example/bestellabschluss.php?i=HASH1'; }
        public function getNotificationURL(string $hash): string { return 'https://shop.example/notify.php?ph=' . $hash; }
    }
}

namespace JTL {
    class Shop
    {
        public static function Container(): object
        {
            return new class {
                public function getLogService(): object
                {
                    return new class {
                        public function __call($n, $a) { fwrite(STDERR, "LOG $n: " . $a[0] . "\n"); }
                    };
                }
            };
        }
    }
}

namespace {
    use JTL\Checkout\Bestellung;
    use Plugin\jtl_coinsnappayment\paymentmethod\CoinsnapPayment;
    use Plugin\jtl_coinsnappayment\Webhook\Store;

    error_reporting(E_ALL);
    set_error_handler(function (int $no, string $str, string $file, int $line) {
        throw new ErrorException($str, 0, $no, $file, $line);
    });

    if (in_array('--child', $argv, true)) {
        require dirname(__DIR__) . '/paymentmethod/CoinsnapPayment.php';

        class FakeStore extends Store
        {
            public function __construct() {}
            public function rememberInvoice(string $invoiceId, string $orderNo, string $amount, string $currency): void {}
        }
        class TestPayment extends CoinsnapPayment
        {
            protected function store(): Store { return new FakeStore(); }
            public function ensureWebhook(): bool { return true; }
            public function getStoreId() { return 'CS-STORE-1'; }
            public function getApiKey(): string { return 'k'; }
        }

        function position(array $p): object { return (object) $p; }
        $order = new Bestellung();
        $order->kBestellung = 0;
        $order->cBestellNr = 'B-10042';
        $order->fGesamtsumme = 110.4;
        $order->fWaehrungsFaktor = 1.0;
        $order->Waehrung = (object) ['cISO' => 'eur', 'fFaktor' => 1.0];
        $order->cKommentar = 'Bitte nicht klingeln';
        $order->cVersandartName = 'DHL';
        $order->oRechnungsadresse = (object) [
            'cVorname' => 'Erika', 'cNachname' => 'Mustermann', 'cFirma' => 'Muster &amp; Co', 'cMail' => 'erika@example.com',
            'cTel' => '', 'cMobil' => '+49 151 1234', 'cStrasse' => 'Hauptstr.', 'cHausnummer' => '5', 'cAdressZusatz' => 'Hinterhaus',
            'cPLZ' => '10115', 'cOrt' => 'Berlin', 'cBundesland' => '', 'cLand' => 'DE',
        ];
        $order->Positionen = [
            position(['nPosTyp' => 1, 'cName' => ['ger' => 'Tasse', 'eng' => 'Mug'], 'cArtNr' => 'MUG-1', 'kArtikel' => 7, 'nAnzahl' => 2, 'fPreis' => 16.386554, 'fMwSt' => 19]),
            position(['nPosTyp' => 1, 'cName' => 'Buch', 'cArtNr' => '', 'kArtikel' => 9, 'nAnzahl' => 1, 'fPreis' => 65.420561, 'fMwSt' => 7]),
            position(['nPosTyp' => 2, 'cName' => 'Versand', 'nAnzahl' => 1, 'fPreis' => 4.117647, 'fMwSt' => 19]),
            position(['nPosTyp' => 3, 'cName' => 'Kupon', 'nAnzahl' => 1, 'fPreis' => -4.201681, 'fMwSt' => 19]),
        ];
        if (in_array('--sparse', $argv, true)) {
            $order->Positionen = [];
            $order->cKommentar = null;
            $order->oRechnungsadresse = (object) ['cVorname' => 'A', 'cNachname' => 'B', 'cMail' => 'a@example.com'];
        }
        $_SESSION = ['cISOSprache' => 'ger'];

        $payment = new TestPayment();
        $p = new ReflectionProperty(CoinsnapPayment::class, 'payAgain');
        $p->setValue($payment, false);
        register_shutdown_function(function () { echo \Coinsnap\Http\CurlClient::$invoiceBody ?? 'null'; });
        $payment->preparePaymentProcess($order);
        exit;
    }

    $passed = 0;
    $failed = 0;
    function check(string $name, bool $ok, $detail = null): void
    {
        global $passed, $failed;
        $ok ? $passed++ : $failed++;
        echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === null ? '' : ' -- got ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    }
    function run(string ...$flags): array
    {
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . implode(' ', array_map('escapeshellarg', $flags)) . ' 2>&1');
        $body = json_decode((string) $out, true);
        if (!is_array($body)) {
            echo "child output: $out\n";
        }
        return is_array($body) ? $body : [];
    }

    $b = run();
    $m = $b['metadata'] ?? [];
    check('top-level orderId is cBestellNr (unchanged)', ($b['orderId'] ?? null) === 'B-10042', $b['orderId'] ?? null);
    check('top-level buyerEmail', ($b['buyerEmail'] ?? null) === 'erika@example.com');
    check('metadata orderId / orderNumber / paymentHash', ($m['orderId'] ?? null) === 'B-10042' && ($m['orderNumber'] ?? null) === 'B-10042' && ($m['paymentHash'] ?? null) === 'HASH1', $m);
    check('customerName as before', ($m['customerName'] ?? null) === 'Erika Mustermann');
    check('buyer contact', ($m['buyerName'] ?? null) === 'Erika Mustermann' && ($m['buyerEmail'] ?? null) === 'erika@example.com' && ($m['buyerPhone'] ?? null) === '+49 151 1234', $m);
    check('billing address', ($m['buyerCompany'] ?? null) === 'Muster & Co' && ($m['buyerAddress1'] ?? null) === 'Hauptstr. 5' && ($m['buyerAddress2'] ?? null) === 'Hinterhaus'
        && ($m['buyerZip'] ?? null) === '10115' && ($m['buyerCity'] ?? null) === 'Berlin' && ($m['buyerCountry'] ?? null) === 'DE' && !isset($m['buyerState']), $m);
    check('customer note', ($m['buyerMessage'] ?? null) === 'Bitte nicht klingeln');
    // JSON numbers: 2.0 comes back as 2, so numbers are compared by value.
    check('line items (articles only, gross prices)', ($m['lineItems'] ?? null) == [
        ['name' => 'Tasse', 'sku' => 'MUG-1', 'productId' => 7, 'quantity' => 2, 'price' => 19.5, 'total' => 39.0],
        ['name' => 'Buch', 'productId' => 9, 'quantity' => 1, 'price' => 70.0, 'total' => 70.0],
    ], $m['lineItems'] ?? null);
    check('itemDesc / itemCode', ($m['itemDesc'] ?? null) === '2 x Tasse, Buch' && ($m['itemCode'] ?? null) === 'MUG-1, 9', [$m['itemDesc'] ?? null, $m['itemCode'] ?? null]);
    check('totals', ($m['shippingTotal'] ?? null) == 4.9 && ($m['discountTotal'] ?? null) == 5.0 && ($m['orderTotal'] ?? null) == 110.4
        && ($m['taxTotal'] ?? null) == 10.79 && ($m['currency'] ?? null) === 'EUR', $m);
    check('platform / shipping / language', ($m['platform'] ?? null) === 'jtl' && ($m['shippingMethod'] ?? null) === 'DHL' && ($m['language'] ?? null) === 'ger', $m);
    check('no empty values', $m !== [] && !in_array('', $m, true) && !in_array(null, $m, true));

    $b = run('--sparse');
    check('sparse order: invoice still sent with orderId and name', ($b['orderId'] ?? null) === 'B-10042' && ($b['metadata']['buyerName'] ?? null) === 'A B'
        && !isset($b['metadata']['lineItems']) && !isset($b['metadata']['buyerMessage']), $b['metadata'] ?? null);

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
