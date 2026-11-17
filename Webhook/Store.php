<?php

declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment\Webhook;

use JTL\DB\DbInterface;
use JTL\DB\ReturnType;

// The webhook this plugin registered per Coinsnap store (with its secret), the invoices it created,
// and the upgrade window for older orders, in plugin tables made on first use.
class Store
{
    public const WEBHOOK_TABLE = 'xplugin_jtl_coinsnappayment_store_webhook';
    public const INVOICE_TABLE = 'xplugin_jtl_coinsnappayment_invoice';
    public const META_TABLE = 'xplugin_jtl_coinsnappayment_meta';
    // Tables of earlier development builds, removed on uninstall too.
    public const OLD_TABLES = ['xplugin_jtl_coinsnappayment_webhook'];

    // Orders placed before the upgrade, which have no invoice record, are accepted this long after it.
    private const LEGACY_WINDOW = 'INTERVAL 1 DAY';

    public function __construct(private DbInterface $db)
    {
        $this->db->queryPrepared(
            'CREATE TABLE IF NOT EXISTS `' . self::WEBHOOK_TABLE . '` (
                `store_id` VARCHAR(64) NOT NULL,
                `webhook_id` VARCHAR(64) NOT NULL,
                `secret` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`store_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            [],
            ReturnType::DEFAULT
        );
        $this->db->queryPrepared(
            'CREATE TABLE IF NOT EXISTS `' . self::INVOICE_TABLE . '` (
                `invoice_id` VARCHAR(64) NOT NULL,
                `order_no` VARCHAR(64) NOT NULL,
                `amount` VARCHAR(32) NOT NULL,
                `currency` VARCHAR(8) NOT NULL,
                `paid_at` DATETIME NULL,
                PRIMARY KEY (`invoice_id`),
                KEY `order_no` (`order_no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            [],
            ReturnType::DEFAULT
        );
        $this->db->queryPrepared(
            'CREATE TABLE IF NOT EXISTS `' . self::META_TABLE . '` (
                `name` VARCHAR(32) NOT NULL,
                `value` VARCHAR(64) NOT NULL,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            [],
            ReturnType::DEFAULT
        );
    }

    // The webhook registered for this Coinsnap store, or null.
    public function webhook(string $storeId): ?array
    {
        if ($storeId === '') {
            return null;
        }
        $row = $this->db->queryPrepared(
            'SELECT `webhook_id`, `secret` FROM `' . self::WEBHOOK_TABLE . '` WHERE `store_id` = :sid',
            ['sid' => $storeId],
            ReturnType::SINGLE_ASSOC_ARRAY
        );
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function saveWebhook(string $storeId, string $webhookId, string $secret): void
    {
        $this->db->queryPrepared(
            'REPLACE INTO `' . self::WEBHOOK_TABLE . '` (`store_id`, `webhook_id`, `secret`) VALUES (:sid, :wid, :secret)',
            ['sid' => $storeId, 'wid' => $webhookId, 'secret' => $secret],
            ReturnType::DEFAULT
        );
    }

    public function rememberInvoice(string $invoiceId, string $orderNo, string $amount, string $currency): void
    {
        $this->db->queryPrepared(
            'INSERT IGNORE INTO `' . self::INVOICE_TABLE . '` (`invoice_id`, `order_no`, `amount`, `currency`) VALUES (:iid, :ono, :amount, :cur)',
            ['iid' => $invoiceId, 'ono' => $orderNo, 'amount' => $amount, 'cur' => $currency],
            ReturnType::DEFAULT
        );
    }

    // The recorded invoice, or null when it was not created for this order.
    public function findInvoice(string $invoiceId, string $orderNo): ?array
    {
        $row = $this->db->queryPrepared(
            'SELECT `amount`, `currency`, `paid_at` FROM `' . self::INVOICE_TABLE . '` WHERE `invoice_id` = :iid AND `order_no` = :ono',
            ['iid' => $invoiceId, 'ono' => $orderNo],
            ReturnType::SINGLE_ASSOC_ARRAY
        );
        return is_array($row) && $row !== [] ? $row : null;
    }

    // The order number this plugin created the invoice for, or null.
    public function orderNoForInvoice(string $invoiceId): ?string
    {
        $row = $this->db->queryPrepared(
            'SELECT `order_no` FROM `' . self::INVOICE_TABLE . '` WHERE `invoice_id` = :iid',
            ['iid' => $invoiceId],
            ReturnType::SINGLE_ASSOC_ARRAY
        );
        return is_array($row) && isset($row['order_no']) ? (string) $row['order_no'] : null;
    }

    // "Payment before order completion": the invoice was created for the shop's temporary order number.
    public function moveInvoice(string $invoiceId, string $fromOrderNo, string $toOrderNo): void
    {
        $this->db->queryPrepared(
            'UPDATE `' . self::INVOICE_TABLE . '` SET `order_no` = :tono WHERE `invoice_id` = :iid AND `order_no` = :fromno',
            ['tono' => $toOrderNo, 'iid' => $invoiceId, 'fromno' => $fromOrderNo],
            ReturnType::DEFAULT
        );
    }

    // Orders placed before 1.1.0 have no rows.
    public function orderHasInvoices(string $orderNo): bool
    {
        $row = $this->db->queryPrepared(
            'SELECT 1 AS `found` FROM `' . self::INVOICE_TABLE . '` WHERE `order_no` = :ono LIMIT 1',
            ['ono' => $orderNo],
            ReturnType::SINGLE_ASSOC_ARRAY
        );
        return is_array($row) && $row !== [];
    }

    // "Already paid" comes from this plugin's records, not from the order status.
    public function orderPaid(string $orderNo): bool
    {
        $row = $this->db->queryPrepared(
            'SELECT 1 AS `found` FROM `' . self::INVOICE_TABLE . '` WHERE `order_no` = :ono AND `paid_at` IS NOT NULL LIMIT 1',
            ['ono' => $orderNo],
            ReturnType::SINGLE_ASSOC_ARRAY
        );
        return is_array($row) && $row !== [];
    }

    // True for the first caller only, so the webhook and the return page can't both mark the order paid.
    public function claimPaid(string $invoiceId, string $orderNo, string $amount, string $currency): bool
    {
        $inserted = $this->db->queryPrepared(
            'INSERT IGNORE INTO `' . self::INVOICE_TABLE . '` (`invoice_id`, `order_no`, `amount`, `currency`, `paid_at`) VALUES (:iid, :ono, :amount, :cur, NOW())',
            ['iid' => $invoiceId, 'ono' => $orderNo, 'amount' => $amount, 'cur' => $currency],
            ReturnType::AFFECTED_ROWS
        );
        if ((int) $inserted === 1) {
            return true;
        }
        $updated = $this->db->queryPrepared(
            'UPDATE `' . self::INVOICE_TABLE . '` SET `paid_at` = NOW() WHERE `invoice_id` = :iid AND `order_no` = :ono AND `paid_at` IS NULL',
            ['iid' => $invoiceId, 'ono' => $orderNo],
            ReturnType::AFFECTED_ROWS
        );
        return (int) $updated === 1;
    }

    // Called once when updating from a version without invoice records.
    public function startLegacyWindow(): void
    {
        $this->db->queryPrepared(
            'REPLACE INTO `' . self::META_TABLE . '` (`name`, `value`) VALUES
                (\'legacy_before\', CAST(NOW() AS CHAR)),
                (\'legacy_until\', CAST(NOW() + ' . self::LEGACY_WINDOW . ' AS CHAR))',
            [],
            ReturnType::DEFAULT
        );
    }

    // An order without invoice records is accepted only if it was placed before the upgrade, and only during the window.
    public function legacyOrderAccepted(int $orderId): bool
    {
        $row = $this->db->queryPrepared(
            'SELECT 1 AS `found` FROM `tbestellung` b
                JOIN `' . self::META_TABLE . '` mb ON mb.`name` = \'legacy_before\'
                JOIN `' . self::META_TABLE . '` mu ON mu.`name` = \'legacy_until\'
                WHERE b.`kBestellung` = :oid AND b.`dErstellt` < CAST(mb.`value` AS DATETIME) AND NOW() < CAST(mu.`value` AS DATETIME)',
            ['oid' => $orderId],
            ReturnType::SINGLE_ASSOC_ARRAY
        );
        return is_array($row) && $row !== [];
    }

    // On uninstall: the webhook secrets and invoice records go with the plugin.
    public function dropTables(): void
    {
        foreach (array_merge([self::WEBHOOK_TABLE, self::INVOICE_TABLE, self::META_TABLE], self::OLD_TABLES) as $table) {
            $this->db->queryPrepared('DROP TABLE IF EXISTS `' . $table . '`', [], ReturnType::DEFAULT);
        }
    }
}
