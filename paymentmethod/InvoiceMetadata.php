<?php

declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment\paymentmethod;

// Order and customer data sent with an invoice, so the merchant can find the order in Coinsnap.
// Plain values only: the API stores them as JSON and escapes them where it shows them.
final class InvoiceMetadata
{
    public const PLATFORM = 'jtl';

    // JTL cart position types (C_WARENKORBPOS_TYP_*), defined here so the class works without the shop's constants.
    private const POS_ARTICLE = 1;
    private const POS_SHIPPING = 2;
    private const POS_COUPON = 3;
    private const POS_VOUCHER = 4;
    private const POS_NEW_CUSTOMER_COUPON = 7;
    private const POS_FREE_GIFT = 10;
    private const POS_SPECIAL_DISCOUNT = 13;

    /**
     * @param object $order    JTL\Checkout\Bestellung (or the order JTL builds before "payment before order completion").
     * @param float  $factor   Currency factor of the order, as used for the invoice amount.
     */
    public static function build(object $order, string $orderNo, float $factor = 1.0, string $language = '', string $pluginVersion = ''): array
    {
        $factor = $factor > 0 ? $factor : 1.0;
        $billing = is_object($order->oRechnungsadresse ?? null) ? $order->oRechnungsadresse : new \stdClass();
        $name = self::join([$billing->cVorname ?? null, $billing->cNachname ?? null]);

        $meta = [
            'orderId' => $orderNo,
            'orderNumber' => $orderNo,
            'platform' => self::PLATFORM,
            'pluginVersion' => $pluginVersion,
            // Personal data: the plugin has no "send customer data" setting, so it is always sent.
            'customerName' => $name,
            'buyerName' => $name,
            'buyerEmail' => $billing->cMail ?? null,
            'buyerPhone' => self::first([$billing->cTel ?? null, $billing->cMobil ?? null]),
            'buyerCompany' => $billing->cFirma ?? null,
            'buyerAddress1' => self::join([$billing->cStrasse ?? null, $billing->cHausnummer ?? null]),
            'buyerAddress2' => $billing->cAdressZusatz ?? null,
            'buyerCity' => $billing->cOrt ?? null,
            'buyerZip' => $billing->cPLZ ?? null,
            'buyerState' => $billing->cBundesland ?? null,
            'buyerCountry' => $billing->cLand ?? null,
            'buyerMessage' => $order->cKommentar ?? null,
            'shippingMethod' => self::text($order->cVersandartName ?? null, $language),
            'currency' => isset($order->Waehrung->cISO) ? strtoupper((string) $order->Waehrung->cISO) : null,
            'orderTotal' => isset($order->fGesamtsumme) && is_numeric($order->fGesamtsumme) ? round((float) $order->fGesamtsumme * $factor, 2) : null,
            'language' => $language,
        ];

        $lineItems = [];
        $names = [];
        $codes = [];
        $shipping = null;
        $discount = null;
        $tax = null;
        foreach ((array) ($order->Positionen ?? []) as $pos) {
            if (!is_object($pos)) {
                continue;
            }
            $type = (int) ($pos->nPosTyp ?? self::POS_ARTICLE);
            $qty = is_numeric($pos->nAnzahl ?? null) ? (float) $pos->nAnzahl : 1.0;
            $net = is_numeric($pos->fPreis ?? null) ? (float) $pos->fPreis : null;
            $vat = is_numeric($pos->fMwSt ?? null) ? (float) $pos->fMwSt : 0.0;
            // fPreis is the net unit price in the default currency.
            $gross = $net !== null ? round($net * (1 + $vat / 100) * $factor, 2) : null;
            $lineTotal = $gross !== null ? round($gross * $qty, 2) : null;
            if ($net !== null) {
                $tax = ($tax ?? 0) + $net * $qty * $vat / 100 * $factor;
            }

            if ($type === self::POS_SHIPPING) {
                $shipping = ($shipping ?? 0) + ($lineTotal ?? 0);
                continue;
            }
            if (in_array($type, [self::POS_COUPON, self::POS_VOUCHER, self::POS_NEW_CUSTOMER_COUPON, self::POS_SPECIAL_DISCOUNT], true)) {
                $discount = ($discount ?? 0) + abs($lineTotal ?? 0);
                continue;
            }
            if ($type !== self::POS_ARTICLE && $type !== self::POS_FREE_GIFT) {
                continue;
            }
            $item = self::clean([
                'name' => self::text($pos->cName ?? null, $language),
                'sku' => $pos->cArtNr ?? null,
                'productId' => isset($pos->kArtikel) && (int) $pos->kArtikel > 0 ? (int) $pos->kArtikel : null,
                'quantity' => $qty,
                'price' => $gross,
                'total' => $lineTotal,
            ]);
            if (!isset($item['name']) && !isset($item['sku'])) {
                continue;
            }
            $lineItems[] = $item;
            if (isset($item['name'])) {
                $names[] = ($qty != 1 ? self::qty($qty) . ' x ' : '') . $item['name'];
            }
            if (isset($item['sku']) || isset($item['productId'])) {
                $codes[] = (string) ($item['sku'] ?? $item['productId']);
            }
        }
        $meta['lineItems'] = $lineItems;
        $meta['itemDesc'] = $names !== [] ? self::cut(implode(', ', $names), 250) : null;
        $meta['itemCode'] = $codes !== [] ? self::cut(implode(', ', $codes), 250) : null;
        $meta['shippingTotal'] = $shipping !== null ? round($shipping, 2) : null;
        $meta['discountTotal'] = $discount !== null ? round($discount, 2) : null;
        $meta['taxTotal'] = $tax !== null ? round($tax, 2) : null;

        return self::clean($meta);
    }

    // Names can be a string or one string per language (cart positions).
    private static function text($v, string $language)
    {
        if (is_array($v)) {
            if ($language !== '' && isset($v[$language]) && is_scalar($v[$language])) {
                return $v[$language];
            }
            foreach ($v as $s) {
                if (is_scalar($s) && trim((string) $s) !== '') {
                    return $s;
                }
            }
            return null;
        }
        return $v;
    }

    private static function str($v): string
    {
        return is_scalar($v) ? trim(html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8')) : '';
    }

    private static function first(array $values): ?string
    {
        foreach ($values as $v) {
            if (self::str($v) !== '') {
                return self::str($v);
            }
        }
        return null;
    }

    private static function qty(float $q): string
    {
        return floor($q) == $q ? (string) (int) $q : (string) $q;
    }

    private static function join(array $parts): string
    {
        return implode(' ', array_filter(array_map([self::class, 'str'], $parts), 'strlen'));
    }

    private static function cut(string $s, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    // Drops empty values; strings are trimmed and HTML entities decoded.
    private static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    $out[$key] = $value;
                }
            } elseif (is_int($value) || is_float($value)) {
                $out[$key] = $value;
            } elseif (($s = self::str($value)) !== '') {
                $out[$key] = $s;
            }
        }
        return $out;
    }
}
