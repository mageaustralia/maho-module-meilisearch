<?php

/**
 * SPDX-License-Identifier: OSL-3.0
 * Copyright (c) 2026 Mageaus.
 */

/*
 * Subclass to be able to catch the error
 */
class Meilisearch_Search_Helper_Image extends Mage_Catalog_Helper_Image
{
    /**
     * The (string) cast invokes __toString(); the parent Mage_Catalog_Helper_Image::__toString()
     * is the DEFERRED path (returns a /core/index/resize signed URL when the file is not yet
     * cached), which the indexer would then freeze into the search index. Delegate to toString()
     * which force-generates the cache file and returns the STATIC, cacheable cache/ URL.
     */
    public function __toString(): string
    {
        try {
            return (string) $this->toString();
        } catch (\Throwable $e) {
            Mage::logException($e);
            return Mage::getDesign()->getSkinUrl($this->getPlaceholder());
        }
    }

    public function toString()
    {
        $model = $this->_getModel();

        if ($this->getImageFile()) {
            $model->setBaseFile($this->getImageFile());
        } else {
            $model->setBaseFile($this->getProduct()->getData($model->getDestinationSubdir()));
        }

        if ($model->isCached()) {
            return $this->removeProtocol($model->getUrl());
        }

        if ($this->_scheduleRotate) {
            $model->rotate($this->getAngle());
        }

        if ($this->_scheduleResize) {
            $model->resize();
        }

        if ($this->getWatermark()) {
            $model->setWatermark($this->getWatermark());
        }

        return $this->removeProtocol($model->saveFile()->getUrl());
    }

    /**
     * Return the resized image URL ONLY if it is already cached on disk.
     * Never triggers GD generation. Returns null on a cache miss so the caller
     * can fall back to a placeholder. Used by the barcode reindex so it does not
     * generate thousands of thumbnails (incl. for disabled / no-image products)
     * at cron time.
     *
     * @return string|null
     */
    public function toStringIfCached()
    {
        $model = $this->_getModel();

        if ($this->getImageFile()) {
            $model->setBaseFile($this->getImageFile());
        } else {
            $model->setBaseFile($this->getProduct()->getData($model->getDestinationSubdir()));
        }

        if ($model->isCached()) {
            return $this->removeProtocol($model->getUrl());
        }

        return null;
    }

    public function removeProtocol($url)
    {
        return str_replace(['https://', 'http://'], '//', $url);
    }
}
