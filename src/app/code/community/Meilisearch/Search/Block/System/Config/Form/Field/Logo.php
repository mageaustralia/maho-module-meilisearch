<?php

/**
 * SPDX-License-Identifier: OSL-3.0
 * Copyright (c) 2026 Mageaus.
 */

/**
 * "Show Logo" admin field for the Meilisearch config section.
 *
 * Historically (when this module was forked from Algolia's Magento
 * extension) the block called the now-deleted ProxyHelper to ask an
 * Algolia-hosted endpoint whether the active subscription tier required
 * displaying the Algolia logo, and if so rendered an upsell row pointing
 * at Algolia's pricing page. None of that applies to Meilisearch — there
 * is no hosted-tier logo requirement, and the proxy endpoint
 * (`magento-proxy.meilisearch.com`) has never existed.
 *
 * Kept as a pass-through subclass so the system.xml `frontend_model`
 * reference still resolves without 500'ing the whole config page.
 */
class Meilisearch_Search_Block_System_Config_Form_Field_Logo extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * Always false for Meilisearch — no hosted-tier requirement to render
     * a vendor logo. Retained for back-compat with any external caller.
     *
     * @return bool
     */
    public function showLogo()
    {
        return false;
    }
}
