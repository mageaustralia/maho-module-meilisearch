<?php

/**
 * 2025 Maho
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@maho.org so we can send you a copy immediately.
 *
 * @category   Meilisearch
 * @package    Meilisearch_Search
 * @copyright  Copyright (c) 2025 Maho (https://www.maho.org)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Meilisearch_Search_AjaxController extends Mage_Core_Controller_Front_Action
{
    /**
     * Get form key for AJAX requests
     */
    #[\Maho\Config\Route('/msearchtrack/ajax/getformkey', name: 'msearchtrack.ajax.getformkey')]
    #[\Maho\Config\Route('/meilisearch/ajax/getformkey', name: 'meilisearch.ajax.getformkey')]
    public function getformkeyAction()
    {
        $formKey = Mage::getSingleton('core/session')->getFormKey();

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json')
            ->setBody(Mage::helper('core')->jsonEncode(['formKey' => $formKey]));
    }

    /**
     * Track search click-through: query + clicked product/category.
     * POST: { query, type, objectID, name, position }
     */
    #[\Maho\Config\Route('/msearchtrack/ajax/trackclick', name: 'msearchtrack.ajax.trackclick')]
    #[\Maho\Config\Route('/meilisearch/ajax/trackclick', name: 'meilisearch.ajax.trackclick')]
    public function trackclickAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHttpResponseCode(405);
            return;
        }

        // Origin / Referer same-origin check.
        // The storefront posts via `navigator.sendBeacon` (or keepalive fetch),
        // which can't carry a form_key header — beacons disallow custom headers
        // entirely. Form-key body injection is also fragile (the beacon may
        // outlive the page). Falling back to Origin/Referer matches the
        // canonical store base URL, which is what the browser controls and an
        // attacker cannot forge cross-site.
        if (!$this->_isSameOriginRequest()) {
            $this->getResponse()->setHttpResponseCode(403);
            return;
        }

        try {
            $body = Mage::helper('core')->jsonDecode($this->getRequest()->getRawBody());
            if (!$body || empty($body['query'])) {
                $this->getResponse()->setHttpResponseCode(400);
                return;
            }

            $query    = mb_substr(trim($body['query']), 0, 128);
            $allowedTypes = ['product', 'category', 'page', 'blog', 'suggestion'];
            $type     = in_array($body['type'] ?? '', $allowedTypes, true) ? $body['type'] : 'product';
            $objectID = $body['objectID'] ?? $body['object_id'] ?? null;
            // Accept `object_name` (current JS) OR `name` (legacy beacons).
            // The JS shipped with this module sends `object_name` to keep the
            // payload field aligned with the DB column, but pre-2026 clients
            // (and any third-party integrations) send `name`.
            $name     = mb_substr(trim((string) ($body['object_name'] ?? $body['name'] ?? '')), 0, 255);
            $position = (int) ($body['position'] ?? 0);
            $storeId  = (int) Mage::app()->getStore()->getId();

            $resource = Mage::getSingleton('core/resource');
            $write = $resource->getConnection('core_write');
            $table = $resource->getTableName('meilisearch_search_clicks');

            // Ensure table exists (created lazily)
            if (!$write->isTableExists($table)) {
                $ddl = $write->newTable($table)
                    ->addColumn('click_id', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
                        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
                    ])
                    ->addColumn('store_id', \Maho\Db\Ddl\Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false])
                    ->addColumn('query', \Maho\Db\Ddl\Table::TYPE_TEXT, 128, ['nullable' => false])
                    ->addColumn('type', \Maho\Db\Ddl\Table::TYPE_TEXT, 20, ['nullable' => false, 'default' => 'product'])
                    ->addColumn('object_id', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
                    ->addColumn('object_name', \Maho\Db\Ddl\Table::TYPE_TEXT, 255, ['nullable' => true])
                    ->addColumn('position', \Maho\Db\Ddl\Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
                    ->addColumn('created_at', \Maho\Db\Ddl\Table::TYPE_TIMESTAMP, null, ['nullable' => false, 'default' => \Maho\Db\Ddl\Table::TIMESTAMP_INIT])
                    ->addIndex($write->getIndexName($table, ['store_id', 'query']), ['store_id', 'query'])
                    ->addIndex($write->getIndexName($table, ['object_id']), ['object_id'])
                    ->setComment('Meilisearch Search Click-Through Analytics');
                $write->createTable($ddl);
            }

            $write->insert($table, [
                'store_id'    => $storeId,
                'query'       => $query,
                'type'        => $type,
                'object_id'   => ($objectID !== null && is_numeric($objectID)) ? (int) $objectID : null,
                'object_name' => $name ?: null,
                'position'    => $position,
            ]);

            // Also update catalogsearch_query popularity (upsert via canonical model)
            /** @var Mage_CatalogSearch_Model_Query $searchQuery */
            $searchQuery = Mage::getModel('catalogsearch/query');
            $searchQuery->setStoreId($storeId);
            $searchQuery->loadByQuery($query);
            if (!$searchQuery->getId()) {
                /*
                 * setStoreId() must not appear mid-chain. Mage_CatalogSearch_Model_Query
                 * overrides it and returns void rather than $this -- the one non-fluent
                 * setter on the model -- so the next call in the chain landed on null and
                 * fatalled. Core calls it as a standalone statement for the same reason.
                 */
                $searchQuery->setStoreId($storeId);
                $searchQuery->setQueryText($query)
                    ->setNumResults(0)
                    ->setPopularity(0);
            }
            $searchQuery->setPopularity((int) $searchQuery->getPopularity() + 1)
                ->setUpdatedAt(Mage_Core_Model_Locale::nowUtc())
                ->save();

            $this->getResponse()
                ->setHeader('Content-Type', 'application/json')
                ->setBody('{"ok":true}');
            // Throwable, not Exception: a PHP Error is not an Exception, so the fatal
            // above bypassed this handler and surfaced as an uncaught 500 instead of
            // being logged. Click tracking is analytics -- it must never take down
            // the request that triggered it.
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->getResponse()->setHttpResponseCode(500);
        }
    }

    /**
     * Same-origin check for beacon endpoints that can't carry a form_key.
     *
     * Validates the request `Origin` header (preferred — set by the browser
     * for cross-origin and same-origin POSTs including sendBeacon) against
     * the configured store base URL. Falls back to `Referer` if Origin is
     * absent (some same-origin same-scheme POSTs omit Origin). Returns
     * `true` only when the request originates from this store's host on
     * the same scheme.
     */
    private function _isSameOriginRequest(): bool
    {
        $origin = (string) $this->getRequest()->getHeader('Origin');
        $source = $origin !== '' ? $origin : (string) $this->getRequest()->getHeader('Referer');
        if ($source === '') {
            return false;
        }

        $sourceHost = parse_url($source, PHP_URL_HOST);
        $sourceScheme = parse_url($source, PHP_URL_SCHEME);
        if (!$sourceHost || !$sourceScheme) {
            return false;
        }

        $storeBase = (string) Mage::app()->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
        $storeHost = parse_url($storeBase, PHP_URL_HOST);
        $storeScheme = parse_url($storeBase, PHP_URL_SCHEME);

        return $sourceHost === $storeHost && $sourceScheme === $storeScheme;
    }
}
