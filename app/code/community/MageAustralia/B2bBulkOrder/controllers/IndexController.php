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

        $cart = Mage::getSingleton('checkout/cart');
        $addedCount = 0;
        foreach ($items as $sku => $qty) {
            $product = Mage::getModel('catalog/product')->loadByAttribute('sku', (string) $sku);
            if (!$product || !$product->getId()) {
                continue;
            }
            try {
                $cart->addProduct($product, ['qty' => (float) $qty]);
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
     * Read the sku[] + qty[] arrays from the POST into a SKU=>qty map.
     * @return array<string, int>
     */
    private function _normalizeLineItems(): array
    {
        $post = $this->getRequest()->getPost();
        $skus = (array) ($post['sku'] ?? []);
        $qtys = (array) ($post['qty'] ?? []);
        $out = [];
        foreach ($skus as $i => $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }
            $qty = (int) ($qtys[$i] ?? 1);
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
