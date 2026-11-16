<?php

declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment\Webhook;

use JTL\DB\DbInterface;
use JTL\DB\ReturnType;

// The webhook this plugin registered (with its secret) and the invoices it created, in plugin tables made on first use.
class Store
{
    private const WEBHOOK_TABLE = 'xplugin_jtl_coinsnappayment_webhook';
    private const INVOICE_TABLE = 'xplugin_jtl_coinsnappayment_invoice';

    public function __construct(private DbInterface $db)
    {
        $this->db->queryPrepared(
            'CREATE TABLE IF NOT EXISTS `' . self::WEBHOOK_TABLE . '` (
                `id` TINYINT UNSIGNED NOT NULL,
                `webhook_id` VARCHAR(64) NOT NULL,
                `secret` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`id`)
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
    }

    public function webhook(): ?array
    {
        $row = $this->db->queryPrepared('SELECT `webhook_id`, `secret` FROM `' . self::WEBHOOK_TABLE . '` WHERE `id` = 1', [], ReturnType::SINGLE_ASSOC_ARRAY);
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function saveWebhook(string $webhookId, string $secret): void
    {
        $this->db->queryPrepared(
            'REPLACE INTO `' . self::WEBHOOK_TABLE . '` (`id`, `webhook_id`, `secret`) VALUES (1, :wid, :secret)',
            ['wid' => $webhookId, 'secret' => $secret],
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
}
