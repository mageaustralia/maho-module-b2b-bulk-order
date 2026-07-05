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

    /**
     * URL the "Save as requisition list" button targets. The
     * B2bRequisitionList module registers this route; if it isn't
     * installed the button is hidden.
     */
    public function getSaveAsRequisitionUrl(): ?string
    {
        if (!class_exists('MageAustralia_B2bRequisitionList_Helper_Data')) {
            return null;
        }
        return $this->getUrl('requisition/index/saveFromBulk');
    }

    public function isRequisitionAvailable(): bool
    {
        return $this->getSaveAsRequisitionUrl() !== null;
    }

    /**
     * "Submit for approval" URL, only visible when:
     *   - the Purchase Order module is installed AND
     *   - the Company module is installed AND
     *   - the current customer is a MEMBER of a company (not an admin -
     *     admins can just order directly; there is no one above them to
     *     approve)
     */
    public function getSubmitForApprovalUrl(): ?string
    {
        if (!class_exists('MageAustralia_B2bPurchaseOrder_Helper_Data')
            || !class_exists('MageAustralia_Company_Helper_Data')
        ) {
            return null;
        }
        $customerId = (int) Mage::getSingleton('customer/session')->getCustomerId();
        if ($customerId <= 0) {
            return null;
        }
        /** @var MageAustralia_Company_Helper_Data $companyHelper */
        $companyHelper = Mage::helper('company');
        $company = $companyHelper->getCompanyForCustomer($customerId);
        if (!$company) {
            return null;
        }
        $role = $companyHelper->getRoleForCustomerAtCompany($customerId, (int) $company->getId());
        // Only members see the button - admins order directly
        if ($role !== MageAustralia_Company_Model_Company::ROLE_MEMBER) {
            return null;
        }
        return $this->getUrl('purchase-order/index/submit');
    }

    public function isSubmitForApprovalAvailable(): bool
    {
        return $this->getSubmitForApprovalUrl() !== null;
    }

    /* ---- Product listing ---- */

    public function getSearchTerm(): string
    {
        return trim((string) $this->getRequest()->getParam('q', ''));
    }

    public function getCurrentCategoryId(): int
    {
        return (int) $this->getRequest()->getParam('cat', 0);
    }

    public function getCurrentPage(): int
    {
        $p = (int) $this->getRequest()->getParam('p', 1);
        return $p > 0 ? $p : 1;
    }

    public function getPageSize(): int
    {
        return 50;
    }

    /**
     * Build the product collection the page renders. Filters:
     *   - search: matches SKU or name (LIKE)
     *   - category: single-category scope (subcategories inherited)
     *
     * @return Mage_Catalog_Model_Resource_Product_Collection
     */
    public function getProductCollection()
    {
        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection')
            ->addAttributeToSelect(['name', 'sku', 'price', 'small_image', 'stock_item', 'tax_class_id', 'has_options'])
            ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['neq' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE])
            ->addStoreFilter();
        $collection->addAttributeToSort('name', 'ASC');

        $q = $this->getSearchTerm();
        if ($q !== '') {
            $like = '%' . $q . '%';
            $collection->addFieldToFilter([
                ['attribute' => 'sku',  'like' => $like],
                ['attribute' => 'name', 'like' => $like],
            ]);
        }

        $catId = $this->getCurrentCategoryId();
        if ($catId > 0) {
            $collection->addCategoryFilter(Mage::getModel('catalog/category')->load($catId));
        }

        $collection->setPageSize($this->getPageSize())
            ->setCurPage($this->getCurrentPage());

        return $collection;
    }

    /**
     * Categories to expose in the filter dropdown - direct children of the root.
     * Kept short deliberately (buyer scan-time > exhaustive tree).
     *
     * @return array<int, string>
     */
    public function getCategoryOptions(): array
    {
        $rootId = (int) Mage::app()->getStore()->getRootCategoryId();
        if (!$rootId) {
            return [];
        }
        /** @var Mage_Catalog_Model_Resource_Category_Collection $cats */
        $cats = Mage::getResourceModel('catalog/category_collection')
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('parent_id', $rootId)
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToSort('position', 'ASC');
        $out = [];
        foreach ($cats as $cat) {
            $out[(int) $cat->getId()] = (string) $cat->getName();
        }
        return $out;
    }

    /** Build a page URL preserving search / category filters. */
    public function getPageUrl(int $page): string
    {
        $params = [];
        if ($this->getSearchTerm() !== '') { $params['q'] = $this->getSearchTerm(); }
        if ($this->getCurrentCategoryId() > 0) { $params['cat'] = $this->getCurrentCategoryId(); }
        $params['p'] = $page;
        return $this->getUrl('bulk-order', ['_query' => $params]);
    }

    /** Total pages for the current filter set. */
    public function getPageCount(): int
    {
        return (int) $this->getProductCollection()->getLastPageNumber();
    }
}
