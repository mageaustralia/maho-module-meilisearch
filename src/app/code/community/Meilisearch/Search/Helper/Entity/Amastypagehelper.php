<?php

/**
 * SPDX-License-Identifier: OSL-3.0
 * Copyright (c) 2026 Mageaus.
 */

class Meilisearch_Search_Helper_Entity_Amastypagehelper extends Meilisearch_Search_Helper_Entity_Helper
{
    protected function getIndexNameSuffix()
    {
        return '_amasty_pages';
    }

    public function getIndexSettings($storeId)
    {
        $indexSettings = [
            'searchableAttributes' => ['slug', 'name', 'content'],
            'displayedAttributes' => ['objectID', 'slug', 'name', 'url', 'content'],
            'attributesToHighlight' => ['name', 'content'],
            'attributesToCrop' => ['content:10'],
        ];

        $transport = new \Maho\DataObject($indexSettings);
        Mage::dispatchEvent('meilisearch_pages_index_before_set_settings', ['store_id' => $storeId, 'index_settings' => $transport]);
        $indexSettings = $transport->getData();

        return $indexSettings;
    }

    public function getAmastyPages($storeId)
    {
        /** @var Amasty_Shopby_Model_Resource_Page_Collection $pageCollection */
        $pageCollection = Mage::getModel('amshopby/page')->getCollection();

        // Debug: Log count before store filter
        Mage::helper('meilisearch_search/logger')->log('Amasty pages collection count BEFORE store filter: ' . $pageCollection->count());
        Mage::helper('meilisearch_search/logger')->log('Store ID for filter: ' . $storeId);

        $pageCollection->addStoreFilter($storeId);

        // Debug: Log count after store filter
        Mage::helper('meilisearch_search/logger')->log('Amasty pages collection count AFTER store filter: ' . $pageCollection->count());

        // Debug: Log the SQL query
        Mage::helper('meilisearch_search/logger')->log('Amasty pages SQL query: ' . $pageCollection->getSelect()->__toString());

        Mage::dispatchEvent('meilisearch_after_amasty_pages_collection_build', ['store' => $storeId, 'collection' => $pageCollection]);

        $pages = [];
        $seenTitles = []; // Track titles we've already processed

        // Debug: Log collection size and check for limit
        Mage::helper('meilisearch_search/logger')->log('Collection size before iteration: ' . $pageCollection->getSize());
        Mage::helper('meilisearch_search/logger')->log('Collection count method: ' . count($pageCollection));

        // Check if there's a limit set
        $select = $pageCollection->getSelect();
        $limitCount = $select->getPart(\Maho\Db\Select::LIMIT_COUNT);
        $limitOffset = $select->getPart(\Maho\Db\Select::LIMIT_OFFSET);
        Mage::helper('meilisearch_search/logger')->log('Limit count: ' . var_export($limitCount, true) . ', Offset: ' . var_export($limitOffset, true));

        $pageCount = 0;
        $skippedCount = 0;
        foreach ($pageCollection as $page) {
            $pageCount++;

            // Skip if we've already seen this title
            $title = $page->getTitle();
            if (isset($seenTitles[$title])) {
                $skippedCount++;
                Mage::helper('meilisearch_search/logger')->log('Skipping duplicate page ID ' . $page->getId() . ' with title: ' . $title . ' (already have ID ' . $seenTitles[$title] . ')');
                continue;
            }
            $seenTitles[$title] = $page->getId();
            $pageObject = [];

            $path = parse_url((string) $page->getUrl(), PHP_URL_PATH);

            $pageObject['slug'] = $path;
            $pageObject['name'] = $page->getTitle();

            $content = $page->getDescription();

            $pageObject['objectID'] = $page->getId();
            $pageObject['url'] = $page->getUrl();
            $pageObject['content'] = $this->strip($content, ['script', 'style']);

            $transport = new \Maho\DataObject($pageObject);
            Mage::dispatchEvent('meilisearch_after_create_amasty_page_object', ['page' => $transport, 'pageObject' => $page]);
            $pageObject = $transport->getData();

            $pages[] = $pageObject;
        }

        // Debug: Log final count
        Mage::helper('meilisearch_search/logger')->log('Total pages iterated: ' . $pageCount);
        Mage::helper('meilisearch_search/logger')->log('Duplicates skipped: ' . $skippedCount);
        Mage::helper('meilisearch_search/logger')->log('Total unique pages in array: ' . count($pages));

        return $pages;
    }

    public function shouldIndexPages($storeId)
    {
        // Check if Amasty Shopby module is enabled
        if (!Mage::helper('core')->isModuleEnabled('Amasty_Shopby')) {
            return false;
        }

        $autocompleteSections = $this->config->getAutocompleteSections($storeId);

        // Always return true if Amasty Shopby is enabled
        // The admin can control this via the autocomplete sections config
        return true;
    }

    public function getObject(\Maho\DataObject $page)
    {
        $pageObject = [];

        $path = parse_url($page->getUrl(), PHP_URL_PATH);

        $pageObject['slug'] = $path;
        $pageObject['name'] = $page->getTitle();
        $pageObject['objectID'] = $page->getId();
        $pageObject['url'] = $page->getUrl();
        $pageObject['content'] = $this->strip($page->getDescription(), ['script', 'style']);

        $transport = new \Maho\DataObject($pageObject);
        Mage::dispatchEvent('meilisearch_after_create_amasty_page_object', ['page' => $transport, 'pageObject' => $page]);

        return $transport->getData();
    }
}
