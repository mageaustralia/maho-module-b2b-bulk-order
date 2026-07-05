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
 * Storefront block for the bulk-order page. Renders three input paths (grid
 * with per-row qty inputs, paste-multi-line textarea, CSV file upload), the
 * ex-tax / inc-tax toggle, the download-template link, and the two submit
 * buttons (add to cart / send as quote).
 */
class MageAustralia_B2bBulkOrder_Block_Grid extends Mage_Core_Block_Template
{
    /**
     * Own helper - can't name this method `helper()` because
     * Mage_Core_Block_Abstract::helper($name) already exists with a
     * different signature; PHP 8 signature check fatals the class load.
     */
    public function b2bHelper(): MageAustralia_B2bBulkOrder_Helper_Data
    {
        /** @var MageAustralia_B2bBulkOrder_Helper_Data $h */
        $h = Mage::helper('b2bbulkorder');
        return $h;
    }

    /** URLs the JS + form actions target. */
    public function getParseUrl(): string
    {
        return $this->getUrl('bulk-order/index/parse');
    }
    public function getUploadUrl(): string
    {
        return $this->getUrl('bulk-order/index/upload');
    }
    public function getTemplateUrl(): string
    {
        return $this->getUrl('bulk-order/index/template');
    }
    public function getAddToCartUrl(): string
    {
        return $this->getUrl('bulk-order/index/add-to-cart');
    }
    public function getSetTaxDisplayUrl(): string
    {
        return $this->getUrl('bulk-order/index/set-tax-display');
    }

    /**
     * URL the "Send as quote" button targets. The RFQ module registers this
     * route; if the RFQ module isn't installed the button is hidden.
     */
    public function getSendAsQuoteUrl(): ?string
    {
        if (!class_exists('MageAustralia_B2bRfq_Helper_Data')) {
            return null;
        }
        return $this->getUrl('rfq/index/create');
    }

    public function isRfqAvailable(): bool
    {
        return $this->getSendAsQuoteUrl() !== null;
    }
}
