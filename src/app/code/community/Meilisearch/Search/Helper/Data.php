<?php

/**
 * SPDX-License-Identifier: OSL-3.0
 * Copyright (c) 2026 Mageaus.
 */

class Meilisearch_Search_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const COLLECTION_PAGE_SIZE = 100;

    /** @var Meilisearch_Search_Helper_Meilisearchhelper */
    protected $meilisearch_helper;

    /** @var Meilisearch_Search_Helper_Entity_Pagehelper */
    protected $page_helper;

    /** @var Meilisearch_Search_Helper_Entity_Bloghelper */
    protected $blog_helper;

    /** @var Meilisearch_Search_Helper_Entity_Categoryhelper */
    protected $category_helper;

    /** @var Meilisearch_Search_Helper_Entity_Producthelper */
    protected $product_helper;

    /** @var Meilisearch_Search_Helper_Logger */
    protected $logger;

    /** @var Meilisearch_Search_Helper_Config */
    protected $config;

    /** @var Meilisearch_Search_Helper_Entity_Suggestionhelper */
    protected $suggestion_helper;

    /** @var Meilisearch_Search_Helper_Entity_Additionalsectionshelper */
    protected $additionalsections_helper;

    public function __construct()
    {
        $this->meilisearch_helper = Mage::helper('meilisearch_search/meilisearchhelper');

        $this->page_helper = Mage::helper('meilisearch_search/entity_pagehelper');
        $this->blog_helper = Mage::helper('meilisearch_search/entity_bloghelper');
        $this->category_helper = Mage::helper('meilisearch_search/entity_categoryhelper');
        $this->product_helper = Mage::helper('meilisearch_search/entity_producthelper');
        $this->suggestion_helper = Mage::helper('meilisearch_search/entity_suggestionhelper');
        $this->additionalsections_helper = Mage::helper('meilisearch_search/entity_additionalsectionshelper');

        $this->config = Mage::helper('meilisearch_search/config');

        $this->logger = Mage::helper('meilisearch_search/logger');
    }

    public function deleteProductsStoreIndices($storeId = null)
    {
        if ($storeId !== null) {
            if ($this->config->isEnabledBackend($storeId) === false) {
                $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

                return;
            }
        }

        $this->meilisearch_helper->deleteIndex($this->product_helper->getIndexName($storeId));
    }

    public function deleteCategoriesStoreIndices($storeId = null)
    {
        if ($storeId !== null) {
            if ($this->config->isEnabledBackend($storeId) === false) {
                $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

                return;
            }
        }

        $this->meilisearch_helper->deleteIndex($this->category_helper->getIndexName($storeId));
    }

    public function saveConfigurationToMeilisearch($storeId, $saveToTmpIndicesToo = false)
    {
        $this->meilisearch_helper->resetCredentialsFromConfig();

        if (!($this->config->getServerUrl() && $this->config->getAPIKey())) {
            return;
        }

        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        try {
            $this->meilisearch_helper->setSettings(
                $this->category_helper->getIndexName($storeId),
                $this->category_helper->getIndexSettings($storeId),
            );
            $this->meilisearch_helper->setSettings(
                $this->page_helper->getIndexName($storeId),
                $this->page_helper->getIndexSettings($storeId),
            );

            // Push blog index settings only when the Maho_Blog module is
            // available - skipping otherwise avoids creating an empty
            // `<prefix>_blog` index on installs without the blog.
            if ($this->blog_helper->isBlogModuleEnabled()) {
                $this->meilisearch_helper->setSettings(
                    $this->blog_helper->getIndexName($storeId),
                    $this->blog_helper->getIndexSettings($storeId),
                );
            }
            $this->meilisearch_helper->setSettings(
                $this->suggestion_helper->getIndexName($storeId),
                $this->suggestion_helper->getIndexSettings($storeId),
            );
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Invalid API key') ||
                str_contains($e->getMessage(), 'The provided API key is invalid') ||
                (method_exists($e, 'getCode') && $e->getCode() === 403)) {
                throw new \Exception('The provided API key is invalid.');
            }
            throw $e;
        }

        foreach ($this->config->getAutocompleteSections() as $section) {
            if (in_array($section['name'], ['products', 'categories', 'pages', 'suggestions', 'amasty_pages'], true)) {
                continue;
            }

            $this->meilisearch_helper->setSettings(
                $this->additionalsections_helper->getIndexName($storeId) . '_' . $section['name'],
                $this->additionalsections_helper->getIndexSettings($storeId),
            );
        }

        // On full reindex, delete the tmp index first to ensure a clean slate.
        // Without this, products deleted from Magento would persist in the tmp index
        // from a previous (possibly failed) reindex and survive the swap.
        if ($saveToTmpIndicesToo) {
            $tmpIndexName = $this->product_helper->getIndexName($storeId, true);
            try {
                $this->meilisearch_helper->deleteIndex($tmpIndexName);
            } catch (\Exception $e) {
                // Index might not exist - that's fine
            }
        }

        $this->product_helper->setSettings($storeId, $saveToTmpIndicesToo);

        $this->setExtraSettings($storeId, $saveToTmpIndicesToo);

        // Push (or clear) the semantic-search embedder on the products index
        // whenever core search settings are pushed. Decoupled into its own
        // method so it can be re-triggered standalone via the admin "Re-push
        // embedder" action without forcing a full settings rebuild.
        $this->pushEmbedderSettings($storeId, $saveToTmpIndicesToo);
    }

    /**
     * Push the hybrid-search embedder config to the products index for the
     * given store. If semantic search is disabled in admin, this clears any
     * previously-pushed embedder so a former hybrid setup doesn't keep
     * embedding new docs after the feature is turned off.
     */
    public function pushEmbedderSettings($storeId, $saveToTmpIndicesToo = false): void
    {
        $embedderName = $this->config->getSemanticEmbedderName($storeId);
        $embedderSettings = $this->config->getEmbedderSettings($storeId);

        $payload = $embedderSettings !== null
            ? [$embedderName => $embedderSettings]
            : [];

        $indexName = $this->product_helper->getIndexName($storeId);
        try {
            $this->meilisearch_helper->setEmbedders($indexName, $payload);
            if ($saveToTmpIndicesToo) {
                $this->meilisearch_helper->setEmbedders($indexName . '_tmp', $payload);
            }
        } catch (\Throwable $e) {
            // Embedder PATCH can fail for genuine reasons (model download
            // blocked by sandboxing, model not supported by Meilisearch's
            // candle build, etc). Log and continue rather than break the
            // settings push — the operator sees the error in the index page
            // and the rest of search remains usable.
            Mage::log(
                'Meilisearch: failed to push embedder settings for ' . $indexName . ': ' . $e->getMessage(),
                Mage::LOG_WARNING,
                'meilisearch.log',
            );
        }
    }

    public function getSearchResult($query, $storeId)
    {
        $resultsLimit = $this->config->getResultsLimit($storeId);

        $index_name = $this->product_helper->getIndexName($storeId);

        $number_of_results = 1000;

        if ($this->config->isInstantEnabled()) {
            $number_of_results = min($this->config->getNumberOfProductResults($storeId), 1000);
        }

        $answer = $this->meilisearch_helper->query($index_name, $query, [
            'hitsPerPage'            => $number_of_results, // retrieve all the hits (hard limit is 1000)
            'attributesToRetrieve'   => 'objectID',
            // 'attributesToHighlight'  => '', // Commented out - empty string not needed
            // 'attributesToSnippet'    => '', // Commented out - not supported in Meilisearch
            // 'numericFilters'         => 'visibility_search=1', // Commented out temporarily - not configured as filterable yet
        ]);

        $data = [];

        foreach ($answer['hits'] as $i => $hit) {
            $productId = $hit['objectID'];

            if ($productId) {
                $data[$productId] = $resultsLimit - $i;
            }
        }

        return $data;
    }

    public function removeCategories($ids, $store_id = null)
    {
        $store_ids = Meilisearch_Search_Helper_Entity_Helper::getStores($store_id);

        foreach ($store_ids as $store_id) {
            if ($this->config->isEnabledBackend($store_id) === false) {
                $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($store_id));
                continue;
            }

            $index_name = $this->category_helper->getIndexName($store_id);

            $this->meilisearch_helper->deleteObjects($ids, $index_name);
        }
    }

    public function rebuildStoreAdditionalSectionsIndex($storeId)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $additionnal_sections = $this->config->getAutocompleteSections();

        foreach ($additionnal_sections as $section) {
            if (in_array($section['name'], ['products', 'categories', 'pages', 'suggestions', 'amasty_pages'], true)) {
                continue;
            }

            $index_name = $this->additionalsections_helper->getIndexName($storeId) . '_' . $section['name'];

            $attribute_values = $this->additionalsections_helper->getAttributeValues($storeId, $section);

            foreach (array_chunk($attribute_values, 100) as $chunk) {
                $this->meilisearch_helper->addObjects($chunk, $index_name . '_tmp');
            }

            // Block until the tmp index is fully populated before swapping.
            // Without this, moveIndex() can race ahead of the last addDocuments
            // task and swap a half-populated tmp over the live index.
            $this->meilisearch_helper->waitLastTask();
            $this->meilisearch_helper->moveIndex($index_name . '_tmp', $index_name);

            $this->meilisearch_helper->setSettings(
                $index_name,
                $this->additionalsections_helper->getIndexSettings($storeId),
            );
        }
    }

    public function rebuildStorePageIndex($storeId, $pageIds = null)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $shouldUseTmpIndex = ($pageIds === null);

        $emulationInfo = $this->startEmulation($storeId);

        try {
            $indexName = $this->page_helper->getIndexName($storeId, $shouldUseTmpIndex);

            /** @var array $pages */
            $pages = $this->page_helper->getPages($storeId, $pageIds);
            foreach (array_chunk($pages, 100) as $chunk) {
                $this->meilisearch_helper->addObjects($chunk, $indexName);
            }

            if ($shouldUseTmpIndex === true) {
                $finalIndexName = $this->page_helper->getIndexName($storeId);

                $this->meilisearch_helper->waitLastTask();
                $this->meilisearch_helper->moveIndex($indexName, $finalIndexName);
                $this->meilisearch_helper->setSettings($finalIndexName, $this->page_helper->getIndexSettings($storeId));
            }
        } finally {
            // Always unwind emulation so the rest of the PHP process isn't
            // left with frontend translator/flat-catalog settings pinned.
            $this->stopEmulation($emulationInfo);
        }
    }

    public function rebuildStoreBlogIndex($storeId, $postIds = null)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }
        if (!$this->blog_helper->isBlogModuleEnabled()) {
            return;
        }

        $shouldUseTmpIndex = ($postIds === null);

        $emulationInfo = $this->startEmulation($storeId);

        try {
            $indexName = $this->blog_helper->getIndexName($storeId, $shouldUseTmpIndex);

            $posts = $this->blog_helper->getPosts($storeId, $postIds);
            foreach (array_chunk($posts, 100) as $chunk) {
                $this->meilisearch_helper->addObjects($chunk, $indexName);
            }

            if ($shouldUseTmpIndex === true) {
                $finalIndexName = $this->blog_helper->getIndexName($storeId);

                $this->meilisearch_helper->waitLastTask();
                $this->meilisearch_helper->moveIndex($indexName, $finalIndexName);
                $this->meilisearch_helper->setSettings($finalIndexName, $this->blog_helper->getIndexSettings($storeId));
            }
        } finally {
            $this->stopEmulation($emulationInfo);
        }
    }

    public function rebuildStoreFaqIndex($storeId, $faqIds = null)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }
        if (!Mage::helper('core')->isModuleEnabled('Mageaustralia_Faq')) {
            return;
        }

        $shouldUseTmpIndex = ($faqIds === null);

        $emulationInfo = $this->startEmulation($storeId);

        try {
            $faqHelper = Mage::helper('meilisearch_search/entity_faqhelper');
            $indexName = $faqHelper->getIndexName($storeId, $shouldUseTmpIndex);

            $faqs = $faqHelper->getFaqs($storeId, $faqIds);
            foreach (array_chunk($faqs, 100) as $chunk) {
                $this->meilisearch_helper->addObjects($chunk, $indexName);
            }

            if ($shouldUseTmpIndex === true) {
                $finalIndexName = $faqHelper->getIndexName($storeId);

                $this->meilisearch_helper->waitLastTask();
                $this->meilisearch_helper->moveIndex($indexName, $finalIndexName);
                $this->meilisearch_helper->setSettings($finalIndexName, $faqHelper->getIndexSettings($storeId));
            }
        } finally {
            $this->stopEmulation($emulationInfo);
        }
    }

    public function rebuildAmastyPagesIndex($storeId, $pageIds = null)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));
            return;
        }

        // Check if Amasty Shopby module is enabled
        if (!Mage::helper('core')->isModuleEnabled('Amasty_Shopby')) {
            $this->logger->log('Amasty Shopby module is not enabled');
            return;
        }

        $this->logger->log('Starting Amasty pages index for store ' . $storeId);

        $shouldUseTmpIndex = ($pageIds === null);

        $emulationInfo = $this->startEmulation($storeId);

        $amastyHelper = Mage::helper('meilisearch_search/entity_amastypagehelper');
        $indexName = $amastyHelper->getIndexName($storeId, $shouldUseTmpIndex);

        try {
            /** @var array $pages */
            $pages = $amastyHelper->getAmastyPages($storeId);

            $this->logger->log('Got ' . count($pages) . ' Amasty pages from helper for store ' . $storeId);

            $totalIndexed = 0;
            foreach (array_chunk($pages, 100) as $i => $chunk) {
                $this->logger->log('Processing chunk ' . ($i + 1) . ' with ' . count($chunk) . ' pages');
                $this->meilisearch_helper->addObjects($chunk, $indexName);
                $totalIndexed += count($chunk);
            }

            if ($shouldUseTmpIndex === true) {
                $finalIndexName = $amastyHelper->getIndexName($storeId);
                $this->meilisearch_helper->waitLastTask();
                $this->meilisearch_helper->moveIndex($indexName, $finalIndexName);
                $this->meilisearch_helper->setSettings($finalIndexName, $amastyHelper->getIndexSettings($storeId));
            }

            $this->logger->log('Indexed ' . $totalIndexed . ' Amasty pages in store ' . $this->logger->getStoreName($storeId) . ' (original count: ' . count($pages) . ')');
        } catch (Exception $e) {
            $this->logger->log('Error indexing Amasty pages: ' . $e->getMessage());
        }

        $this->stopEmulation($emulationInfo);
    }

    public function rebuildStoreCategoryIndex($storeId, $categoryIds = null)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $emulationInfo = $this->startEmulation($storeId);

        try {
            $collection = $this->category_helper->getCategoryCollectionQuery($storeId, $categoryIds);

            $size = $collection->getSize();

            if ($size > 0) {
                $index_name = $this->category_helper->getIndexName($storeId);

                // Clear the index first when doing a full reindex
                if ($categoryIds === null) {
                    $this->meilisearch_helper->clearIndex($index_name);
                }

                $pages = ceil($size / $this->config->getNumberOfElementByPage());
                $collection->clear();
                $page = 1;

                while ($page <= $pages) {
                    $this->rebuildStoreCategoryIndexPage(
                        $storeId,
                        $collection,
                        $page,
                        $this->config->getNumberOfElementByPage(),
                        $emulationInfo,
                    );

                    $page++;
                }

                unset($indexData);
            }
        } finally {
            $this->stopEmulation($emulationInfo);
        }
    }

    public function rebuildStoreBarcodesIndex($storeId, $productIds = null)
    {
        if (!$this->config->isEnabledBackend($storeId)) {
            $this->logger->log('Barcode indexing is disabled for store: ' . $storeId);
            return;
        }

        $emulationInfo = $this->startEmulation($storeId);

        try {
            /** @var Meilisearch_Search_Helper_Entity_Barcodeshelper $barcodesHelper */
            $barcodesHelper = Mage::helper('meilisearch_search/entity_barcodeshelper');

            // Get barcode index name
            $indexName = $barcodesHelper->getIndexName($storeId);

            // Clear existing barcode index if no specific product IDs
            if (empty($productIds)) {
                $this->meilisearch_helper->clearIndex($indexName);
            }

            // Get products to index
            $collection = $this->product_helper->getProductCollectionQuery($storeId, $productIds, false);
            $size = $collection->getSize();

            if ($size > 0) {
                $pages = ceil($size / $this->config->getNumberOfElementByPage());
                $page = 1;

                while ($page <= $pages) {
                    $this->logger->log("Barcode indexing page {$page}/{$pages} for store {$storeId}");

                    $collection->clear();
                    $collection->setPageSize($this->config->getNumberOfElementByPage());
                    $collection->setCurPage($page);
                    $collection->load();

                    $barcodeData = [];
                    foreach ($collection as $product) {
                        $barcodeRecord = $barcodesHelper->getObject($product);
                        if ($barcodeRecord && !empty($barcodeRecord['barcode'])) {
                            $barcodeData[] = $barcodeRecord;
                        }
                    }

                    if (!empty($barcodeData)) {
                        $this->meilisearch_helper->addObjects($barcodeData, $indexName);
                    }

                    $page++;
                }
            }

            $this->logger->log("Barcode indexing complete for store {$storeId}");
        } catch (Exception $e) {
            $this->logger->log('Error during barcode indexing: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->stopEmulation($emulationInfo);
        }
    }

    public function rebuildStoreSuggestionIndex($storeId)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $collection = $this->suggestion_helper->getSuggestionCollectionQuery($storeId);

        // Limit to top 50 most popular suggestions - more than enough for autocomplete
        $collection->setOrder('popularity', 'DESC');
        $collection->setOrder('num_results', 'DESC');
        $collection->getSelect()->limit(50);

        $size = $collection->getSize();

        if ($size > 0) {
            // Single page - we capped at 50
            $this->rebuildStoreSuggestionIndexPage(
                $storeId,
                $collection,
                1,
                50,
            );
        }
    }

    public function moveStoreSuggestionIndex($storeId)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        // Ensure all pending addDocuments tasks against the tmp index have
        // completed before swap - see moveIndex() for why this matters.
        $this->meilisearch_helper->waitLastTask();
        $this->meilisearch_helper->moveIndex(
            $this->suggestion_helper->getIndexName($storeId) . '_tmp',
            $this->suggestion_helper->getIndexName($storeId),
        );
    }

    public function moveProductsIndex($storeId)
    {
        $indexName = $this->product_helper->getIndexName($storeId);
        $tmpIndexName = $this->product_helper->getIndexName($storeId, true);

        $this->meilisearch_helper->waitLastTask();
        $this->meilisearch_helper->moveIndex($tmpIndexName, $indexName);
    }

    public function rebuildStoreProductIndex($storeId, $productIds)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $emulationInfo = $this->startEmulation($storeId);

        try {
            $collection = $this->product_helper->getProductCollectionQuery($storeId, $productIds, false);
            $size = $collection->getSize();

            if (!empty($productIds)) {
                $size = max(count($productIds), $size);
            }

            $this->logger->log('Store ' . $this->logger->getStoreName($storeId) . ' collection size : ' . $size);

            if ($size > 0) {
                $pages = ceil($size / $this->config->getNumberOfElementByPage());
                $page = 1;

                $collection->clear();

                while ($page <= $pages) {
                    $this->rebuildStoreProductIndexPage(
                        $storeId,
                        $collection,
                        $page,
                        $this->config->getNumberOfElementByPage(),
                        $emulationInfo,
                        $productIds,
                    );

                    $page++;
                }
            }
        } finally {
            $this->stopEmulation($emulationInfo);
        }
    }

    public function rebuildStoreSuggestionIndexPage($storeId, $collectionDefault, $page, $pageSize)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $collection = clone $collectionDefault;
        $collection->setCurPage($page)->setPageSize($pageSize);
        $collection->load();

        $index_name = $this->suggestion_helper->getIndexName($storeId) . '_tmp';

        if ($page == 1) {
            $this->meilisearch_helper->setSettings($index_name, $this->suggestion_helper->getIndexSettings($storeId));
        }

        $indexData = [];

        /** @var Mage_CatalogSearch_Model_Query $suggestion */
        foreach ($collection as $suggestion) {
            $suggestion->setStoreId($storeId);

            $suggestionObject = $this->suggestion_helper->getObject($suggestion);

            if (strlen((string) $suggestionObject['query']) >= 3) {
                $indexData[] = $suggestionObject;
            }
        }

        if (count($indexData) > 0) {
            $this->meilisearch_helper->addObjects($indexData, $index_name);
        }

        unset($indexData);

        $collection->walk('clearInstance');
        $collection->clear();

        unset($collection);
    }

    public function rebuildStoreCategoryIndexPage($storeId, $collectionDefault, $page, $pageSize, $emulationInfo = null)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $emulationInfoPage = null;

        if ($emulationInfo === null) {
            $emulationInfoPage = $this->startEmulation($storeId);
        }

        try {
            $collection = clone $collectionDefault;
            $collection->setCurPage($page)->setPageSize($pageSize);
            $collection->load();

            $index_name = $this->category_helper->getIndexName($storeId);

            $indexData = [];

            /** @var $category Mage_Catalog_Model_Category */
            foreach ($collection as $category) {
                if (!$this->category_helper->isCategoryActive($category->getId(), $storeId)) {
                    continue;
                }

                $category->setStoreId($storeId);

                $categoryObject = $this->category_helper->getObject($category);

                if ($this->config->shouldIndexEmptyCategories($storeId) === true || $categoryObject['product_count'] > 0) {
                    $indexData[] = $categoryObject;
                }
            }

            if (count($indexData) > 0) {
                $this->meilisearch_helper->addObjects($indexData, $index_name);
            }

            unset($indexData);

            $collection->walk('clearInstance');
            $collection->clear();

            unset($collection);
        } finally {
            if ($emulationInfoPage !== null) {
                $this->stopEmulation($emulationInfoPage);
            }
        }
    }

    protected function getProductsRecords($storeId, $collection, $potentiallyDeletedProductsIds = [])
    {
        $productsToIndex = [];
        $productsToRemove = [];

        // In $potentiallyDeletedProductsIds there might be IDs of deleted products which will not be in a collection
        if (is_array($potentiallyDeletedProductsIds) && !empty($potentiallyDeletedProductsIds)) {
            $potentiallyDeletedProductsIds = array_combine($potentiallyDeletedProductsIds, $potentiallyDeletedProductsIds);
        } else {
            $potentiallyDeletedProductsIds = [];
        }

        if (method_exists('Mage', 'getEdition') === true && Mage::getEdition() === Mage::EDITION_ENTERPRISE) {
            $productIds = [];

            /** @var Mage_Catalog_Model_Product $products */
            foreach ($collection as $products) {
                $productIds[] = $products->getId();
            }

            /** @var Meilisearch_Search_Helper_IndexChecker $indexChecker */
            $indexChecker = Mage::helper('meilisearch_search/indexChecker');
            $indexChecker->checkIndexers($storeId, $productIds);
        }

        $this->logger->start('CREATE RECORDS ' . $this->logger->getStoreName($storeId));
        $this->logger->log(count($collection) . ' product records to create');

        /** @var $product Mage_Catalog_Model_Product */
        foreach ($collection as $product) {
            $product->setStoreId($storeId);

            $productId = $product->getId();

            // If $productId is in the collection, remove it from $potentiallyDeletedProductsIds so it's not removed without check
            if (isset($potentiallyDeletedProductsIds[$productId])) {
                unset($potentiallyDeletedProductsIds[$productId]);
            }

            Mage::dispatchEvent('meilisearch_before_product_availability_check', ['product' => $product, 'store' => $storeId]);

            if ($product->getData('meilisearch__noIndex') === true) {
                $productsToRemove[$productId] = $productId;
            }

            if ($product->getData('meilisearch__alwaysIndex') === true) {
                $productsToIndex[$productId] = $this->product_helper->getObject($product);
            }

            if (isset($productsToIndex[$productId]) || isset($productsToRemove[$productId])) {
                continue;
            }

            try {
                $this->product_helper->canProductBeReindexed($product, $storeId);
            } catch (Meilisearch_Search_Model_Exception_ProductReindexException) {
                $productsToRemove[$productId] = $productId;
                continue;
            }

            $productsToIndex[$productId] = $this->product_helper->getObject($product);
        }

        // A product missing from the collection is NOT proof that it was deleted.
        //
        // The collection is filtered and joined (website, status, visibility, category,
        // stock, rating). Anything that makes it under-return - most obviously the
        // catalog_category_product reindex that runs every 15 minutes, during which the
        // category tables are transiently incomplete - used to be read as "these products
        // no longer exist" and purged.
        //
        // On 2026-08-19 that emptied the catalogue: the collection returned 505 of ~1,981
        // products and the other 1,476 were deleted in a single call, taking the ball
        // machines out of search for six hours.
        //
        // Confirm against catalog_product_entity before deleting anything. One query per
        // batch, and it makes "deleted" mean deleted.
        if (!empty($potentiallyDeletedProductsIds)) {
            $removable = $this->filterToRemovableProductIds($potentiallyDeletedProductsIds, $storeId);
            $kept = count($potentiallyDeletedProductsIds) - count($removable);

            if ($kept > 0) {
                $this->logger->log(sprintf(
                    'SKIPPED REMOVAL of %d product(s): absent from the collection but still present and '
                    . 'still indexable (the collection under-returned; they were not deleted or disabled).',
                    $kept,
                ));
            }

            $productsToRemove = array_merge($productsToRemove, $removable);
        }

        $this->logger->stop('CREATE RECORDS ' . $this->logger->getStoreName($storeId));

        return [
            'toIndex'  => $productsToIndex,
            'toRemove' => array_unique($productsToRemove),
        ];
    }
    /**
     * Batch size above which the per-product indexability check is skipped.
     *
     * A normal incremental pass has a handful of ids here. A batch this large means the
     * collection under-returned, which is the failure this guard exists for - so the ids
     * are kept rather than spending minutes proving what is already obvious.
     */
    /**
     * Largest share of a live index one incremental pass may delete.
     *
     * A routine pass removes a handful of products. Anything approaching a fifth of the
     * index means the caller decided most of the catalogue should go, which is never right
     * for an incremental run - it means the input was wrong.
     */
    public const MAX_INCREMENTAL_REMOVAL_RATIO = 0.2;

    /** Below this many documents the ratio is meaningless, so it is not applied. */
    public const MIN_INDEX_SIZE_FOR_RATIO = 100;

    /**
     * Refuse a removal that would gut the index.
     *
     * Defence in depth behind filterToRemovableProductIds(): that fixes the known cause,
     * this catches the next one. A blocked removal leaves stale documents in the index,
     * which is visible and recoverable; a completed one takes the catalogue out of search
     * until somebody notices - six hours, on 2026-08-19.
     *
     * @param array<int|string> $toRemove
     */
    public function isRemovalProportionate(array $toRemove, string $indexName): bool
    {
        try {
            $indexed = (int) ($this->meilisearch_helper->getClient()->index($indexName)->stats()['numberOfDocuments'] ?? 0);
        } catch (\Throwable $e) {
            // Never block indexing because a stats call failed.
            return true;
        }

        if ($indexed < self::MIN_INDEX_SIZE_FOR_RATIO) {
            return true;
        }

        $limit = (int) ceil($indexed * self::MAX_INCREMENTAL_REMOVAL_RATIO);
        if (count($toRemove) <= $limit) {
            return true;
        }

        Mage::log(sprintf(
            'REMOVAL BLOCKED for %s: asked to delete %d of %d documents (limit %d, %.0f%%). '
            . 'An incremental pass never legitimately removes this much - treating the input as suspect. '
            . 'Product IDs: %s',
            $indexName,
            count($toRemove),
            $indexed,
            $limit,
            self::MAX_INCREMENTAL_REMOVAL_RATIO * 100,
            implode(',', array_slice(array_map('strval', $toRemove), 0, 50)) . (count($toRemove) > 50 ? ',...' : ''),
        ), Mage::LOG_WARNING, 'meilisearch_guard.log', true);

        return false;
    }

    public const MAX_REMOVAL_VERIFY_BATCH = 200;

    /**
     * Of the ids missing from the collection, return those that genuinely should be removed.
     *
     * Absence from the collection has two very different causes and the old code conflated
     * them, treating both as "deleted":
     *
     *   1. The product is gone, or is no longer indexable (disabled, hidden, out of stock
     *      with out-of-stock indexing off). It SHOULD be removed.
     *   2. The collection under-returned - most obviously while catalog_category_product is
     *      being rebuilt, when the joins it depends on are transiently incomplete. Removing
     *      these is what emptied the index on 2026-08-19: 1,476 live products deleted in one
     *      call because the collection returned 505 of ~1,981.
     *
     * Existence alone cannot separate the two: a disabled product still exists and must
     * still be removed. So ask the same question the indexer asks - canProductBeReindexed().
     * Anything that fails it is removed as before; anything that passes is kept, because a
     * product that should be indexed has no business being deleted just for missing a join.
     *
     * @param  array<int|string> $productIds
     * @return array<int|string>
     */
    public function filterToRemovableProductIds(array $productIds, $storeId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return [];
        }

        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');

        $existing = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            $select = $connection->select()
                ->from($resource->getTableName('catalog/product'), ['entity_id'])
                ->where('entity_id IN (?)', $chunk);
            $existing = array_merge($existing, array_map('intval', $connection->fetchCol($select)));
        }

        // Gone from the catalogue: unambiguous, remove.
        $removable = array_values(array_diff($productIds, $existing));

        if (empty($existing)) {
            return $removable;
        }

        if (count($existing) > self::MAX_REMOVAL_VERIFY_BATCH) {
            $this->logger->log(sprintf(
                'NOT verifying %d still-present products for removal (over %d): a batch this large means the '
                . 'collection under-returned, so they are kept.',
                count($existing),
                self::MAX_REMOVAL_VERIFY_BATCH,
            ));

            return $removable;
        }

        foreach ($existing as $productId) {
            $product = Mage::getModel('catalog/product')->setStoreId($storeId)->load($productId);
            if (!$product->getId()) {
                $removable[] = $productId;
                continue;
            }

            try {
                $this->product_helper->canProductBeReindexed($product, $storeId);
            } catch (Meilisearch_Search_Model_Exception_ProductReindexException) {
                // Legitimately not indexable now - disabled, hidden, or out of stock.
                $removable[] = $productId;
            }
        }

        return array_values(array_unique($removable));
    }

    public function rebuildStoreProductIndexPage($storeId, $collectionDefault, $page, $pageSize, $emulationInfo = null, $productIds = null, $useTmpIndex = false)
    {
        if ($this->config->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return;
        }

        $this->logger->start('rebuildStoreProductIndexPage ' . $this->logger->getStoreName($storeId) . ' page ' . $page . ' pageSize ' . $pageSize);
        $emulationInfoPage = null;

        if ($emulationInfo === null) {
            $emulationInfoPage = $this->startEmulation($storeId);
        }

        try {

            $index_prefix = Mage::getConfig()->getTablePrefix();

            $additionalAttributes = $this->config->getProductAdditionalAttributes($storeId);

            /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
            $collection = clone $collectionDefault;

            $collection->setCurPage($page)->setPageSize($pageSize);
            $collection->addCategoryIds();
            $collection->addUrlRewrite();

            if ($this->product_helper->isAttributeEnabled($additionalAttributes, 'stock_qty')) {
                $collection->joinField(
                    'stock_qty',
                    $index_prefix . 'cataloginventory_stock_item',
                    'qty',
                    'product_id=entity_id',
                    '{{table}}.stock_id=1',
                    'left',
                );
            }

            // ordered_qty + total_ordered are correlated subqueries ON PURPOSE.
            // product_id is indexed on sales_flat_order_item, so each row costs one
            // small index dive (a product's own order items). The tempting
            // alternative - one LEFT JOIN onto a derived
            // (SELECT ... GROUP BY product_id) - aggregates the ENTIRE order-item
            // table on EVERY collection load. That is ruinous for incremental
            // reindexes (a stock change on one product re-aggregates all sales
            // history, per website) and was observed saturating a shared DB server.
            $needsOrdered = $this->product_helper->isAttributeEnabled($additionalAttributes, 'ordered_qty');
            $needsTotal = $this->product_helper->isAttributeEnabled($additionalAttributes, 'total_ordered');
            if ($needsOrdered || $needsTotal) {
                $orderItemTable = $index_prefix . 'sales_flat_order_item';
                $columns = [];
                if ($needsOrdered) {
                    $columns['ordered_qty'] = new \Maho\Db\Expr(sprintf(
                        '(SELECT SUM(oi.qty_ordered) FROM %s AS oi WHERE oi.product_id = e.entity_id)',
                        $orderItemTable,
                    ));
                }
                if ($needsTotal) {
                    $columns['total_ordered'] = new \Maho\Db\Expr(sprintf(
                        '(SELECT SUM(oi.row_total) FROM %s AS oi WHERE oi.product_id = e.entity_id)',
                        $orderItemTable,
                    ));
                }
                $collection->getSelect()->columns($columns);
            }

            if ($this->product_helper->isAttributeEnabled($additionalAttributes, 'rating_summary')) {
                $collection->joinField(
                    'rating_summary',
                    $index_prefix . 'review_entity_summary',
                    'rating_summary',
                    'entity_pk_value=entity_id',
                    '{{table}}.store_id=' . $storeId,
                    'left',
                );
            }

            Mage::dispatchEvent(
                'meilisearch_before_products_collection_load',
                ['collection' => $collection, 'store' => $storeId],
            );

            $this->logger->start('LOADING ' . $this->logger->getStoreName($storeId) . ' collection page ' . $page . ', pageSize ' . $pageSize);

            $collection->load();

            $this->logger->log('Loaded ' . count($collection) . ' products');
            $this->logger->stop('LOADING ' . $this->logger->getStoreName($storeId) . ' collection page ' . $page . ', pageSize ' . $pageSize);

            $indexName = $this->product_helper->getIndexName($storeId, $useTmpIndex);

            $indexData = $this->getProductsRecords($storeId, $collection, $productIds);

            if (!empty($indexData['toIndex'])) {
                $this->logger->start('ADD/UPDATE TO MEILISEARCH');

                // Convert associative array to indexed array for Meilisearch
                $this->meilisearch_helper->addObjects(array_values($indexData['toIndex']), $indexName);

                $this->logger->log('Product IDs: ' . implode(', ', array_keys($indexData['toIndex'])));
                $this->logger->stop('ADD/UPDATE TO MEILISEARCH');
            }

            if (!empty($indexData['toRemove'])) {
                $toRealRemove = [];

                if (count($indexData['toRemove']) === 1) {
                    // array_values matters: toRemove is keyed by product id, so passing it
                    // through unchanged makes json_encode emit an object ({"33019":"33019"})
                    // where the API requires an array. Meilisearch answers 400 "invalid type:
                    // map, expected a sequence", so removing exactly one product silently
                    // never worked - which is why disabling a single product from a grid left
                    // it searchable. The multi-product branch below rebuilds an indexed list
                    // and was unaffected, so only single removals were broken.
                    $toRealRemove = array_values($indexData['toRemove']);
                } else {
                    $indexData['toRemove'] = array_map(strval(...), $indexData['toRemove']);

                    foreach (array_chunk($indexData['toRemove'], 1000) as $chunk) {
                        $objects = $this->meilisearch_helper->getObjects($indexName, $chunk);
                        foreach ($objects['results'] as $object) {
                            if (isset($object['objectID'])) {
                                $toRealRemove[] = $object['objectID'];
                            }
                        }
                    }
                }

                if (!empty($toRealRemove) && $this->isRemovalProportionate($toRealRemove, $indexName)) {
                    $this->logger->start('REMOVE FROM MEILISEARCH');

                    $this->meilisearch_helper->deleteObjects($toRealRemove, $indexName);
                    $this->logger->log('Product IDs: ' . implode(', ', $toRealRemove));

                    $this->logger->stop('REMOVE FROM MEILISEARCH');
                }
            }

            unset($indexData);

            $collection->walk('clearInstance');
            $collection->clear();

            unset($collection);

        } finally {
            if ($emulationInfoPage !== null) {
                $this->stopEmulation($emulationInfoPage);
            }
        }

        $this->logger->stop('rebuildStoreProductIndexPage ' . $this->logger->getStoreName($storeId) . ' page ' . $page . ' pageSize ' . $pageSize);
    }

    public function startEmulation($storeId)
    {
        $this->logger->start('START EMULATION');

        /** @var Mage_Core_Model_App_Emulation $appEmulation */
        $appEmulation = Mage::getSingleton('core/app_emulation');

        $info = $appEmulation->startEnvironmentEmulation($storeId);

        // Catalog price rule, tier price, special price, etc. observers are
        // registered under <frontend><events>. Indexing usually runs from CLI
        // or cron (no area loaded), so those observers never fire inside
        // getFinalPrice() and per-group prices regress to the full price.
        // Loading the frontend event area here - once per batch, alongside
        // the other frontend-emulation setup - is idempotent on repeat calls.
        Mage::app()->loadAreaPart(
            Mage_Core_Model_App_Area::AREA_FRONTEND,
            Mage_Core_Model_App_Area::PART_EVENTS,
        );

        $info->setInitialStoreId(Mage::app()->getStore()->getId());
        $info->setEmulatedStoreId($storeId);
        $info->setUseProductFlat(Mage::getStoreConfigFlag(
            Mage_Catalog_Helper_Product_Flat::XML_PATH_USE_PRODUCT_FLAT,
            $storeId,
        ));
        $info->setUseCategoryFlat(Mage::getStoreConfigFlag(
            Mage_Catalog_Helper_Category_Flat::XML_PATH_IS_ENABLED_FLAT_CATALOG_CATEGORY,
            $storeId,
        ));
        Mage::app()->setCurrentStore($storeId);
        Mage::app()->getStore($storeId)->setConfig(Mage_Catalog_Helper_Product_Flat::XML_PATH_USE_PRODUCT_FLAT, false);
        Mage::app()->getStore($storeId)
            ->setConfig(Mage_Catalog_Helper_Category_Flat::XML_PATH_IS_ENABLED_FLAT_CATALOG_CATEGORY, false);

        // Init translator so it's available in custom events
        Mage::app()->getTranslator()->init('frontend', true);

        $this->logger->stop('START EMULATION');

        return $info;
    }

    public function stopEmulation($info)
    {
        $this->logger->start('STOP EMULATION');

        /** @var Mage_Core_Model_App_Emulation $appEmulation */
        $appEmulation = Mage::getSingleton('core/app_emulation');

        Mage::app()->setCurrentStore($info->getInitialStoreId());
        Mage::app()->getStore($info->getEmulatedStoreId())
            ->setConfig(Mage_Catalog_Helper_Product_Flat::XML_PATH_USE_PRODUCT_FLAT, $info->getUseProductFlat());
        Mage::app()->getStore($info->getEmulatedStoreId())
            ->setConfig(
                Mage_Catalog_Helper_Category_Flat::XML_PATH_IS_ENABLED_FLAT_CATALOG_CATEGORY,
                $info->getUseCategoryFlat(),
            );

        $appEmulation->stopEnvironmentEmulation($info);
        $this->logger->stop('STOP EMULATION');
    }

    public function escapeJsTranslatedString(Mage_Core_Block_Template $template, $string, $useAddSlashes = false)
    {
        $translated = $template->__($string);

        if ($useAddSlashes === true) {
            return addslashes($translated);
        }

        return Mage::helper('core')->jsonEncode($translated);
    }

    public function isX3Version()
    {
        if (method_exists('Mage', 'getEdition') === false) {
            return false;
        }

        return Mage::EDITION_ENTERPRISE === Mage::getEdition() && version_compare(Mage::getVersion(), '1.14.3', '>=') ||
               Mage::EDITION_COMMUNITY === Mage::getEdition() && version_compare(Mage::getVersion(), '1.9.3', '>=');
    }

    private function setExtraSettings($storeId, $saveToTmpIndicesToo)
    {
        $sections = [
            'products' => $this->product_helper->getIndexName($storeId),
            'categories' => $this->category_helper->getIndexName($storeId),
            'pages' => $this->page_helper->getIndexName($storeId),
            'suggestions' => $this->suggestion_helper->getIndexName($storeId),
            'additional_sections' => $this->additionalsections_helper->getIndexName($storeId),
        ];

        $error = [];
        foreach ($sections as $section => $indexName) {
            try {
                $extraSettings = $this->config->getExtraSettings($section, $storeId);

                if ($extraSettings) {
                    $extraSettings = Mage::helper('core')->jsonDecode($extraSettings);

                    $this->meilisearch_helper->setSettings($indexName, $extraSettings, true);

                    if ($section === 'products' && $saveToTmpIndicesToo === true) {
                        $this->meilisearch_helper->setSettings($indexName . '_tmp', $extraSettings, true);
                    }
                }
            } catch (\MeilisearchSearch\MeilisearchException $e) {
                if (str_starts_with($e->getMessage(), 'Invalid object attributes:')) {
                    $error[] = 'Extra settings for "' . $section . '" indices were not saved. Error message: "' . $e->getMessage() . '"';
                    continue;
                }

                throw $e;
            }
        }

        if (!empty($error)) {
            throw new \MeilisearchSearch\MeilisearchException('<br>' . implode('<br> ', $error));
        }
    }

}
