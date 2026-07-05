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
        $unit = $this->getUnitPrices($product);
        $line = ($this->getTaxDisplayMode() === 1 ? $unit['inc'] : $unit['ex']) * $qty;
        return Mage::helper('core')->currency($line, true, false);
    }

    /**
     * Ex-tax + inc-tax unit prices for a product.
     *
     * Mage_Tax_Helper_Data::getPrice() computes the tax adjustment from the
     * current customer's shipping address. A guest with no address has no
     * destination country, so the tax rate resolves to 0 and inc == ex.
     *
     * The correct "list price" behaviour for a catalogue page is to fall back
     * to the store's shipping origin country + region + postcode as the tax
     * destination when the customer has none - that way "including tax" reflects
     * the tax the customer WOULD pay if they shipped to the store's default
     * origin (usually the merchant's home country, matching what appears on
     * the tax invoice).
     *
     * @return array{ex: float, inc: float}
     */
    public function getUnitPrices(Mage_Catalog_Model_Product $product): array
    {
        $price = (float) $product->getFinalPrice();

        // Build a rate request that ALWAYS has a destination country, falling
        // back to the store's shipping origin country when the customer session
        // has none. Without this, guests see inc == ex on the catalogue.
        /** @var Mage_Tax_Model_Calculation $calc */
        $calc = Mage::getSingleton('tax/calculation');
        $storeId = (int) Mage::app()->getStore()->getId();
        $request = $calc->getRateRequest(null, null, null, $storeId);

        // getRateRequest returns null when there is nothing to base the request
        // on. In that case pull the origin data straight from config.
        if (!$request || !$request->getCountryId()) {
            $request = new Varien_Object([
                'country_id'          => (string) Mage::getStoreConfig('shipping/origin/country_id', $storeId),
                'region_id'           => (int)    Mage::getStoreConfig('shipping/origin/region_id', $storeId),
                'postcode'            => (string) Mage::getStoreConfig('shipping/origin/postcode', $storeId),
                'customer_class_id'   => (int)    Mage::helper('tax')->getDefaultCustomerTaxClass($storeId),
                'store'               => Mage::app()->getStore($storeId),
            ]);
        }
        $request->setProductClassId((int) $product->getTaxClassId());
        // Mage_Tax_Model_Calculation::_getRequestCacheKey() calls
        // ->getStore()->getId(); getRateRequest() sometimes leaves `store` as
        // the plain int we passed in, which fatals on ->getId(). Patch to a
        // real Store object before calling getRate().
        if (!is_object($request->getStore())) {
            $request->setStore(Mage::app()->getStore($storeId));
        }
        $rate = (float) $calc->getRate($request);

        // The rate is a percentage; a 10% GST -> 10.0.
        // If the catalog is set to "prices include tax" (config
        // tax/calculation/price_includes_tax = 1) then $price already has tax
        // baked in and we peel it off for the ex-tax view; otherwise $price is
        // ex-tax and we add it on for the inc-tax view.
        $priceIncludesTax = (bool) Mage::getStoreConfigFlag('tax/calculation/price_includes_tax', $storeId);
        if ($priceIncludesTax) {
            $inc = $price;
            $ex  = $rate > 0 ? $price / (1 + $rate / 100) : $price;
        } else {
            $ex  = $price;
            $inc = $rate > 0 ? $price * (1 + $rate / 100) : $price;
        }
        return ['ex' => round($ex, 4), 'inc' => round($inc, 4)];
    }
}
