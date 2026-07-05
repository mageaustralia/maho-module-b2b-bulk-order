<?php

/**
 * Maho
 *
 * @package    MageAustralia_B2bBulkOrder
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * B2B Bulk Order helper. Resolves SKUs to loaded products, formats prices in
 * the caller's chosen tax display, decides whether the caller is entitled to
 * use bulk order at all (config + group + login state).
 */
class MageAustralia_B2bBulkOrder_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_ENABLED         = 'b2bbulkorder/general/enabled';
    public const XML_REQUIRE_LOGIN   = 'b2bbulkorder/general/require_login';
    public const XML_ALLOWED_GROUPS  = 'b2bbulkorder/general/allowed_groups';
    public const XML_MAX_ROWS        = 'b2bbulkorder/general/max_rows';

    public const TAX_DISPLAY_SESSION_KEY = 'b2b_bulk_tax_display';

    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_ENABLED, $storeId);
    }

    /** Guest can use if require_login=0 and their group is in the allowlist. */
    public function isAccessible(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $requireLogin = (bool) Mage::getStoreConfigFlag(self::XML_REQUIRE_LOGIN);
        $customer = Mage::getSingleton('customer/session');
        if ($requireLogin && !$customer->isLoggedIn()) {
            return false;
        }
        $allowed = array_filter(
            array_map('trim', explode(',', (string) Mage::getStoreConfig(self::XML_ALLOWED_GROUPS))),
            static fn(string $s): bool => $s !== '',
        );
        if ($allowed === []) {
            return true;
        }
        $groupId = (string) $customer->getCustomerGroupId();
        return in_array($groupId, $allowed, true);
    }

    public function getMaxRows(): int
    {
        $max = (int) Mage::getStoreConfig(self::XML_MAX_ROWS);
        return $max > 0 ? $max : 500;
    }

    /**
     * Tax display mode picked by the buyer:
     *   1 = including tax (invoice view)
     *   0 = excluding tax (procurement / ledger view)
     *
     * Session-scoped so the choice persists across the bulk-order → cart → check
     * out flow, but doesn't leak beyond the customer's session.
     */
    public function getTaxDisplayMode(): int
    {
        $session = Mage::getSingleton('core/session');
        $val = $session->getData(self::TAX_DISPLAY_SESSION_KEY);
        if ($val === null) {
            return (int) Mage::getStoreConfig('b2bbulkorder/general/default_tax_display');
        }
        return (int) $val;
    }

    public function setTaxDisplayMode(int $mode): void
    {
        Mage::getSingleton('core/session')->setData(self::TAX_DISPLAY_SESSION_KEY, $mode ? 1 : 0);
    }

    /**
     * Resolve a list of SKU tokens to loaded product objects. Returns two lists:
     *   matched  → SKU => product
     *   unmatched → list of SKUs we could not resolve
     *
     * SKU comparison is case-insensitive to match how buyers paste from
     * spreadsheets (SKUs frequently drift in case).
     *
     * @param list<string> $skus
     * @return array{matched: array<string, Mage_Catalog_Model_Product>, unmatched: list<string>}
     */
    public function resolveSkus(array $skus): array
    {
        $clean = [];
        foreach ($skus as $s) {
            $s = trim((string) $s);
            if ($s === '') {
                continue;
            }
            $clean[strtolower($s)] = $s;
        }
        if ($clean === []) {
            return ['matched' => [], 'unmatched' => []];
        }

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection')
            ->addAttributeToSelect(['name', 'price', 'sku'])
            ->addAttributeToFilter('sku', ['in' => array_values($clean)]);

        $matched = [];
        foreach ($collection as $product) {
            $key = strtolower((string) $product->getSku());
            $matched[$clean[$key] ?? (string) $product->getSku()] = $product;
        }

        $unmatched = [];
        foreach ($clean as $key => $original) {
            $hit = false;
            foreach ($matched as $sku => $_) {
                if (strtolower($sku) === $key) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $unmatched[] = $original;
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Parse the paste-in textarea. Each non-empty line is either:
     *   SKU
     *   SKU<tab>qty
     *   SKU,qty
     *   SKU qty
     * Qty defaults to 1. Returns [SKU => qty, ...] preserving first occurrence.
     *
     * @return array<string, int>
     */
    public function parsePastedLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/[\s,\t]+/', $line) ?: [];
            $sku = trim((string) ($parts[0] ?? ''));
            if ($sku === '') {
                continue;
            }
            $qty = (int) ($parts[1] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }
            if (!isset($out[$sku])) {
                $out[$sku] = $qty;
            } else {
                $out[$sku] += $qty;
            }
        }
        return $out;
    }

    /**
     * Format a price in the current tax display mode. Used by the block.
     */
    public function formatPrice(Mage_Catalog_Model_Product $product, ?float $qty = null): string
    {
        $qty = $qty ?? 1;
        $priceIncTax = (float) Mage::helper('tax')->getPrice($product, (float) $product->getFinalPrice(), true);
        $priceExTax  = (float) Mage::helper('tax')->getPrice($product, (float) $product->getFinalPrice(), false);
        $unit = $this->getTaxDisplayMode() === 1 ? $priceIncTax : $priceExTax;
        return Mage::helper('core')->currency($unit * $qty, true, false);
    }
}
