<?php declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment;

use JTL\Backend\Notification;
use JTL\Backend\NotificationEntry;
use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Plugin\Payment\Method;
use JTL\Shop;
use Plugin\jtl_coinsnappayment\paymentmethod\CoinsnapPayment;
use Plugin\jtl_coinsnappayment\Webhook\Store;

require_once __DIR__ . '/Webhook/Store.php';

/**
 * Class Bootstrap
 * @package Plugin\jtl_coinsnappayment
 */
class Bootstrap extends Bootstrapper
{
    /**
     * @inheritDoc
     */
    public function boot(Dispatcher $dispatcher): void
    {
        parent::boot($dispatcher);

        if (!Shop::isFrontend()) {
            $dispatcher->listen('backend.notification', [$this, 'checkPayments']);
        }
    }

    // After an update, register a webhook whose secret is stored; versions before 1.1.0 did not keep it.
    public function updated($oldVersion, $newVersion): void
    {
        parent::updated($oldVersion, $newVersion);
        $this->moveApiKeyToEncryptedSetting();
        if (\version_compare((string) $oldVersion, '1.1.0', '<')) {
            // Orders placed before this update have no invoice record: accept them for one day only.
            (new Store(Shop::Container()->getDB()))->startLegacyWindow();
        }
        foreach ($this->getPlugin()->getPaymentMethods()->getMethods() as $paymentMethod) {
            $method = Method::create($paymentMethod->getModuleID());
            if ($method instanceof CoinsnapPayment && $method->isValidIntern()) {
                $method->ensureWebhook();
            }
        }
    }

    // The webhook secrets and invoice records live in tables JTL does not know about.
    public function uninstalled(bool $deleteData = true)
    {
        parent::uninstalled($deleteData);
        if ($deleteData) {
            (new Store(Shop::Container()->getDB()))->dropTables();
        }
    }

    // Before 1.1.0 the key was a plain payment method setting. JTL carries old values over on update,
    // so it is still there: encrypt it into the plugin setting and remove the plain copy.
    private function moveApiKeyToEncryptedSetting(): void
    {
        $db = Shop::Container()->getDB();
        $pluginID = $this->getPlugin()->getID();
        $old = $db->getSingleObject(
            "SELECT cName, cWert FROM tplugineinstellungen
                WHERE kPlugin = :pid AND cName LIKE :name",
            ['pid' => $pluginID, 'name' => 'kPlugin\\_%\\_api\\_key']
        );
        if ($old === null) {
            return;
        }
        $current = $db->select('tplugineinstellungen', ['kPlugin', 'cName'], [$pluginID, 'coinsnap_api_key']);
        if (($current->cWert ?? '') === '' && (string) $old->cWert !== '') {
            $encrypted = \base64_encode(Shop::Container()->getCryptoService()->encryptXTEA((string) $old->cWert));
            $db->delete('tplugineinstellungen', ['kPlugin', 'cName'], [$pluginID, 'coinsnap_api_key']);
            $db->insert('tplugineinstellungen', (object) ['kPlugin' => $pluginID, 'cName' => 'coinsnap_api_key', 'cWert' => $encrypted]);
        }
        $db->delete('tplugineinstellungen', ['kPlugin', 'cName'], [$pluginID, $old->cName]);
    }

    /**
     * @return void
     */
    public function checkPayments(): void
    {
        foreach ($this->getPlugin()->getPaymentMethods()->getMethods() as $paymentMethod) {
            $method = Method::create($paymentMethod->getModuleID());
            if ($method instanceof CoinsnapPayment && !$method->isValidIntern()) {
                $note = new NotificationEntry(
                    NotificationEntry::TYPE_WARNING,
                    $paymentMethod->getName(),
                    __('Die Zahlungsart ist nicht konfiguriert'),
                    Shop::getAdminURL() . '/paymentmethods?kZahlungsart=' . $method->getMethod()->getMethodID()
                    . '&token=' . $_SESSION['jtl_token'],
                    'paymentMethodNotConfigured'
                );
                $note->setPluginId($this->getPlugin()->getPluginID());
                Notification::getInstance()->addNotify($note);
            }
        }
    }
}
