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
 * The customer-facing bulk-order controller.
 *
 * Five routes:
 *   GET  /bulk-order/                → the grid page (index)
 *   POST /bulk-order/parse           → parse a paste-textarea; returns matched
 *                                       + unmatched as JSON for progressive
 *                                       enhancement (no full reload needed)
 *   POST /bulk-order/upload          → same shape, but reads a CSV file upload
 *   GET  /bulk-order/template        → download a starter CSV (SKU,Quantity)
 *   POST /bulk-order/add-to-cart     → batch add all matched lines
 *   POST /bulk-order/set-tax-display → toggle ex-tax / inc-tax display
 *
 * Send-as-quote is implemented in the RFQ module which observes the same POST
 * shape so the button on the grid is one small extra form action, not a
 * dependency this module has to model directly.
 */
class MageAustralia_B2bBulkOrder_IndexController extends Mage_Core_Controller_Front_Action
{
    #[\Override]
    public function preDispatch()
    {
        parent::preDispatch();
        /** @var MageAustralia_B2bBulkOrder_Helper_Data $helper */
        $helper = Mage::helper('b2bbulkorder');
        if ($helper->isAccessible()) {
            return;
        }
        // Not eligible. If it's because they're not logged in, redirect to
        // login with a return-to so post-login they land back here. Otherwise
        // it's a group / disabled-module gate - send them to the home page
        // (a hard 404 would be misleading; the page exists, they're just not
        // in the allowlist). NEVER _forward('noRoute') - the noRoute handler
        // rewrites the request back through the same router and re-enters
        // this preDispatch = infinite loop terminated by Maho's iteration cap.
        $customer = Mage::getSingleton('customer/session');
        if (!$customer->isLoggedIn()
            && (bool) Mage::getStoreConfigFlag(MageAustralia_B2bBulkOrder_Helper_Data::XML_REQUIRE_LOGIN)
        ) {
            $customer->setBeforeAuthUrl(Mage::getUrl('bulk-order'));
            $this->_redirect('customer/account/login');
            $this->setFlag('', self::FLAG_NO_DISPATCH, true);
            return;
        }
        $this->_redirect('');
        $this->setFlag('', self::FLAG_NO_DISPATCH, true);
    }

    public function indexAction(): void
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    public function parseAction(): void
    {
        $this->_validateFormKey();
        /** @var MageAustralia_B2bBulkOrder_Helper_Data $helper */
        $helper = Mage::helper('b2bbulkorder');

        $text = (string) $this->getRequest()->getPost('lines', '');
        $requested = $helper->parsePastedLines($text);
        $this->_respondWithMatchResult($helper, $requested);
    }

    public function uploadAction(): void
    {
        $this->_validateFormKey();
        /** @var MageAustralia_B2bBulkOrder_Helper_Data $helper */
        $helper = Mage::helper('b2bbulkorder');

        $requested = $this->_readCsvUpload($helper);
        $this->_respondWithMatchResult($helper, $requested);
    }

    /** @param array<string,int> $requested */
    private function _respondWithMatchResult(
        MageAustralia_B2bBulkOrder_Helper_Data $helper,
        array $requested,
    ): void {
        $skus = array_keys($requested);
        $res  = $helper->resolveSkus($skus);
        $rows = [];
        foreach ($res['matched'] as $sku => $product) {
            /** @var Mage_Catalog_Model_Product $product */
            $qty = (int) ($requested[$sku] ?? 1);
            $rows[] = [
                'sku'      => (string) $product->getSku(),
                'name'     => (string) $product->getName(),
                'qty'      => $qty,
                'unitPriceInc' => (float) Mage::helper('tax')->getPrice($product, (float) $product->getFinalPrice(), true),
                'unitPriceEx'  => (float) Mage::helper('tax')->getPrice($product, (float) $product->getFinalPrice(), false),
                'url'      => (string) $product->getProductUrl(),
                'productId' => (int) $product->getId(),
            ];
        }
        $this->getResponse()
            ->clearHeaders()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode([
                'matched'   => $rows,
                'unmatched' => array_values($res['unmatched']),
            ]));
    }

    /**
     * Return a configurable product's attribute set + variant matrix as JSON
     * so the storefront modal can render an inline option picker (reusing
     * the same shape POS uses in its configurable-product modal).
     *
     * Response:
     *   {
     *     name: string,
     *     sku:  string,
     *     imageUrl: string|null,
     *     options: [                              // configurable attributes
     *       {code, label, values: [{id, label}, ...]}
     *     ],
     *     variants: [                             // one row per child
     *       {sku, name, priceEx, priceInc, inStock,
     *        attributes: {code: valueId, ...}}
     *     ]
     *   }
     */
    public function optionsAction(): void
    {
        $productId = (int) $this->getRequest()->getParam('product_id');
        if ($productId <= 0) {
            $this->_json(['error' => 'missing product_id'], 400);
            return;
        }
        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product')->load($productId);
        if (!$product->getId()) {
            $this->_json(['error' => 'not_found'], 404);
            return;
        }

        $typeId = (string) $product->getTypeId();
        $imgUrl = (string) $product->getSmallImage();
        if ($imgUrl && $imgUrl !== 'no_selection') {
            $imgUrl = Mage::getSingleton('catalog/product_media_config')->getMediaUrl($imgUrl);
        } else {
            $imgUrl = null;
        }
        $ex  = (float) Mage::helper('tax')->getPrice($product, (float) $product->getFinalPrice(), false);
        $inc = (float) Mage::helper('tax')->getPrice($product, (float) $product->getFinalPrice(), true);

        $out = [
            'typeId'   => $typeId,
            'name'     => (string) $product->getName(),
            'sku'      => (string) $product->getSku(),
            'imageUrl' => $imgUrl,
            'priceEx'  => $ex,
            'priceInc' => $inc,
        ];

        // Type-dispatched shape. Each branch fills the right subset of keys
        // so the modal JS can render one UI per type without a big switch on
        // the response.
        switch ($typeId) {
            case Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE:
                $out += $this->_configurableOptions($product);
                break;
            case Mage_Downloadable_Model_Product_Type::TYPE_DOWNLOADABLE:
                $out += $this->_downloadableOptions($product);
                break;
            case Mage_Catalog_Model_Product_Type::TYPE_GROUPED:
                $out += $this->_groupedOptions($product);
                break;
            case Mage_Catalog_Model_Product_Type::TYPE_BUNDLE:
                $out += ['unsupported' => 'bundle', 'productUrl' => (string) $product->getProductUrl()];
                break;
            default:
                // Simple + virtual with custom_options
                if ($product->getHasOptions()) {
                    $out += $this->_customOptions($product);
                }
                break;
        }

        $this->_json($out);
    }

    /**
     * Configurable: attribute list + child matrix.
     *
     * @return array<string, mixed>
     */
    private function _configurableOptions(Mage_Catalog_Model_Product $product): array
    {
        /** @var Mage_Catalog_Model_Product_Type_Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance(true);
        $configAttributes = $typeInstance->getConfigurableAttributesAsArray($product);
        $options = [];
        foreach ($configAttributes as $attr) {
            $values = [];
            foreach ((array) ($attr['values'] ?? []) as $v) {
                $values[] = [
                    'id'    => (int) ($v['value_index'] ?? 0),
                    'label' => (string) ($v['label'] ?? ($v['default_label'] ?? '')),
                ];
            }
            $options[] = [
                'code'   => (string) ($attr['attribute_code'] ?? ''),
                'label'  => (string) ($attr['label'] ?? ($attr['frontend_label'] ?? '')),
                'values' => $values,
            ];
        }
        $variants = [];
        $children = $typeInstance->getUsedProductCollection($product)
            ->addAttributeToSelect(['name', 'sku', 'price', 'small_image', 'status', 'stock_item']);
        foreach ($configAttributes as $a) {
            if (!empty($a['attribute_code'])) {
                $children->addAttributeToSelect($a['attribute_code']);
            }
        }
        foreach ($children as $child) {
            /** @var Mage_Catalog_Model_Product $child */
            $attrs = [];
            foreach ($configAttributes as $a) {
                $code = (string) ($a['attribute_code'] ?? '');
                if ($code !== '' && $child->hasData($code)) {
                    $attrs[$code] = (int) $child->getData($code);
                }
            }
            $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
            $variants[] = [
                'sku'        => (string) $child->getSku(),
                'name'       => (string) $child->getName(),
                'priceEx'    => (float) Mage::helper('tax')->getPrice($child, (float) $child->getFinalPrice(), false),
                'priceInc'   => (float) Mage::helper('tax')->getPrice($child, (float) $child->getFinalPrice(), true),
                'inStock'    => (bool) $stock->getIsInStock(),
                'attributes' => $attrs,
            ];
        }
        return ['options' => $options, 'variants' => $variants];
    }

    /**
     * Downloadable: link list. If links aren't purchased separately, the
     * links[] array is informational (the parent SKU already includes them
     * all at the parent price).
     *
     * @return array<string, mixed>
     */
    private function _downloadableOptions(Mage_Catalog_Model_Product $product): array
    {
        $links = [];
        /** @var Mage_Downloadable_Model_Product_Type $typeInstance */
        $typeInstance = $product->getTypeInstance(true);
        $linkCollection = $typeInstance->getLinks($product);
        foreach ($linkCollection as $link) {
            /** @var Mage_Downloadable_Model_Link $link */
            $links[] = [
                'id'    => (int) $link->getId(),
                'title' => (string) $link->getTitle(),
                'priceEx'  => (float) $link->getPrice(),
                'priceInc' => (float) $link->getPrice(), // downloadable typically not taxed
                'sampleUrl' => $link->getSampleFile() ? (string) $link->getSampleUrl() : null,
            ];
        }
        return [
            'linksPurchasedSeparately' => (bool) $product->getLinksPurchasedSeparately(),
            'links' => $links,
        ];
    }

    /**
     * Grouped: children with default qty. The modal renders one qty row per
     * child; the addToCart submit adds each child as its own grid row (the
     * grouped parent SKU itself is never carted).
     *
     * @return array<string, mixed>
     */
    private function _groupedOptions(Mage_Catalog_Model_Product $product): array
    {
        $children = [];
        /** @var Mage_Catalog_Model_Product_Type_Grouped $typeInstance */
        $typeInstance = $product->getTypeInstance(true);
        $collection = $typeInstance->getAssociatedProducts($product);
        foreach ($collection as $child) {
            /** @var Mage_Catalog_Model_Product $child */
            $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
            $children[] = [
                'id'        => (int) $child->getId(),
                'sku'       => (string) $child->getSku(),
                'name'      => (string) $child->getName(),
                'priceEx'   => (float) Mage::helper('tax')->getPrice($child, (float) $child->getFinalPrice(), false),
                'priceInc'  => (float) Mage::helper('tax')->getPrice($child, (float) $child->getFinalPrice(), true),
                'inStock'   => (bool) $stock->getIsInStock(),
                'defaultQty' => (float) ($child->getQty() ?: 0),
            ];
        }
        return ['groupedChildren' => $children];
    }

    /**
     * Simple / virtual product with custom options.
     *
     * @return array<string, mixed>
     */
    private function _customOptions(Mage_Catalog_Model_Product $product): array
    {
        $out = [];
        foreach ($product->getOptions() as $option) {
            /** @var Mage_Catalog_Model_Product_Option $option */
            $type = (string) $option->getType();
            $values = [];
            foreach ($option->getValues() ?: [] as $v) {
                /** @var Mage_Catalog_Model_Product_Option_Value $v */
                $values[] = [
                    'id'        => (int) $v->getId(),
                    'title'     => (string) $v->getTitle(),
                    'priceType' => (string) $v->getPriceType(),
                    'price'     => (float) $v->getPrice(),
                ];
            }
            $out[] = [
                'id'        => (int) $option->getId(),
                'title'     => (string) $option->getTitle(),
                'type'      => $type,
                'isRequire' => (bool) $option->getIsRequire(),
                'priceType' => (string) $option->getPriceType(),
                'price'     => (float) $option->getPrice(),
                'values'    => $values,
            ];
        }
        return ['customOptions' => $out];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function _json(array $data, int $status = 200): void
    {
        $this->getResponse()
            ->clearHeaders()
            ->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($data));
    }

    public function templateAction(): void
    {
        $body = "SKU,Quantity\r\n"
              . "# Add one SKU per line then set the quantity you want.\r\n"
              . "# Lines starting with # are ignored.\r\n"
              . "# Delete this template header before uploading.\r\n";
        $this->getResponse()
            ->clearHeaders()
            ->setHeader('Content-Type', 'text/csv; charset=utf-8', true)
            ->setHeader('Content-Disposition', 'attachment; filename="bulk-order-template.csv"', true)
            ->setBody($body);
    }

    public function addToCartAction(): void
    {
        $this->_validateFormKey();
        /** @var MageAustralia_B2bBulkOrder_Helper_Data $helper */
        $helper = Mage::helper('b2bbulkorder');

        $items = $this->_normalizeLineItems();
        if ($items === []) {
            Mage::getSingleton('checkout/session')->addError(
                $helper->__('No matched products to add to cart.'),
            );
            $this->_redirect('*/*/');
            return;
        }

        // Per-SKU option config blobs from the modal - see the config[SKU]
        // hidden input the JS injects. Each blob is JSON. Merged into the
        // addProduct params so downloadable links[], custom options[], and
        // (future) bundle_option[] all flow through the same code path.
        $rawConfigs = (array) $this->getRequest()->getPost('config', []);
        $configs = [];
        foreach ($rawConfigs as $sku => $json) {
            $decoded = null;
            if (is_string($json) && $json !== '') {
                $decoded = json_decode($json, true);
            }
            if (is_array($decoded)) {
                $configs[(string) $sku] = $decoded;
            }
        }

        $cart = Mage::getSingleton('checkout/cart');
        $addedCount = 0;
        foreach ($items as $sku => $qty) {
            $product = Mage::getModel('catalog/product')->loadByAttribute('sku', (string) $sku);
            if (!$product || !$product->getId()) {
                continue;
            }
            $params = ['qty' => (float) $qty] + (array) ($configs[$sku] ?? []);
            try {
                $cart->addProduct($product, $params);
                $addedCount++;
            } catch (Mage_Core_Exception $e) {
                Mage::getSingleton('checkout/session')->addError(
                    $helper->__('%s: %s', $product->getSku(), $e->getMessage()),
                );
            }
        }
        if ($addedCount > 0) {
            $cart->save();
            Mage::getSingleton('checkout/session')->addSuccess(
                $helper->__('Added %d product(s) to your cart.', $addedCount),
            );
            $this->_redirect('checkout/cart');
            return;
        }
        $this->_redirect('*/*/');
    }

    public function setTaxDisplayAction(): void
    {
        $this->_validateFormKey();
        $mode = (int) $this->getRequest()->getPost('mode');
        /** @var MageAustralia_B2bBulkOrder_Helper_Data $helper */
        $helper = Mage::helper('b2bbulkorder');
        $helper->setTaxDisplayMode($mode);
        $this->getResponse()
            ->clearHeaders()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode(['ok' => true, 'mode' => $helper->getTaxDisplayMode()]));
    }

    /**
     * Read the POST body into a SKU=>qty map. Supports two shapes:
     *   1. `qty[SKU] = N` - how the pre-populated grid submits (v0.2+)
     *   2. `sku[i] + qty[i]` (parallel arrays) - how the paste-populated grid
     *      submitted in v0.1; kept for headless / API-driven POSTs
     * @return array<string, int>
     */
    private function _normalizeLineItems(): array
    {
        $post = $this->getRequest()->getPost();
        $qtyMap = (array) ($post['qty'] ?? []);
        $out = [];

        // Shape 1: qty[SKU] = N
        if (array_keys($qtyMap) !== range(0, max(0, count($qtyMap) - 1))) {
            foreach ($qtyMap as $sku => $qty) {
                $sku = trim((string) $sku);
                $qty = (int) $qty;
                if ($sku === '' || $qty < 1) {
                    continue;
                }
                $out[$sku] = ($out[$sku] ?? 0) + $qty;
            }
            return $out;
        }

        // Shape 2: parallel sku[]/qty[]
        $skus = (array) ($post['sku'] ?? []);
        foreach ($skus as $i => $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }
            $qty = (int) ($qtyMap[$i] ?? 1);
            if ($qty < 1) {
                continue;
            }
            $out[$sku] = ($out[$sku] ?? 0) + $qty;
        }
        return $out;
    }

    /**
     * Read a CSV upload from the "file" field. Header row optional (auto-skips
     * a first "SKU,..." line). Comment lines starting with # are ignored.
     * @return array<string, int>
     */
    private function _readCsvUpload(MageAustralia_B2bBulkOrder_Helper_Data $helper): array
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [];
        }
        $path = $file['tmp_name'] ?? '';
        if (!$path || !is_readable($path)) {
            return [];
        }
        $out = [];
        $rows = 0;
        $max = $helper->getMaxRows();
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }
        while (($line = fgetcsv($handle)) !== false) {
            if (++$rows > $max) {
                break;
            }
            if (!isset($line[0])) {
                continue;
            }
            $sku = trim((string) $line[0]);
            if ($sku === '' || str_starts_with($sku, '#') || strcasecmp($sku, 'sku') === 0) {
                continue;
            }
            $qty = (int) ($line[1] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }
            $out[$sku] = ($out[$sku] ?? 0) + $qty;
        }
        fclose($handle);
        return $out;
    }
}
