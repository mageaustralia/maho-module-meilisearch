<?php

/**
 * SPDX-License-Identifier: OSL-3.0
 * Copyright (c) 2026 Mageaus.
 */

/**
 * Meilisearch custom sort order field.
 */
class Meilisearch_Search_Block_System_Config_Form_Field_Facets extends Meilisearch_Search_Block_System_Config_Form_Field_AbstractField
{
    public function __construct()
    {
        $this->settings = [
            'columns' => [
                'attribute' => [
                    'label'   => 'Attribute',
                    'options' => function () {
                        $options = [];

                        /** @var Meilisearch_Search_Helper_Entity_Producthelper $product_helper */
                        $product_helper = Mage::helper('meilisearch_search/entity_producthelper');

                        $attributes = $product_helper->getAllAttributes();
                        foreach ($attributes as $key => $label) {
                            $options[$key] = $key ?: $label;
                        }

                        return $options;
                    },
                    'rowMethod' => 'getAttribute',
                    'width'     => 160,
                ],
                'type' => [
                    'label'   => 'Facet type',
                    'options' => [
                        'conjunctive' => 'Conjunctive',
                        'disjunctive' => 'Disjunctive',
                        'slider'      => 'Slider',
                        'priceRanges' => 'Price Ranges',
                    ],
                    'rowMethod' => 'getType',
                ],
                'label' => [
                    'label' => 'Label',
                    'style' => 'width: 100px;',
                ],
                'searchable' => [
                    'label' => 'Searchable?',
                    'options' => [
                        '1' => 'Yes',
                        '2' => 'No',
                    ],
                    'rowMethod' => 'getSearchable',
                ],
                'create_rule' => [
                    'label'  => 'Create Query rule?',
                    'options' => [
                        '2' => 'No',
                        '1' => 'Yes',
                    ],
                    'rowMethod' => 'getCreateRule',
                    'disabled' => $this->isQueryRulesDisabled(),
                ],
            ],
            'buttonLabel' => 'Add Facet',
            'addAfter'    => false,
        ];

        parent::__construct();
    }

    /**
     * Always true for Meilisearch — the engine doesn't expose Algolia-style
     * query rules, so the per-facet "Create Query rule?" column stays
     * disabled. Previously polled an Algolia-hosted proxy endpoint via the
     * now-deleted ProxyHelper to decide whether the active subscription
     * tier unlocked the feature; that branch never made sense for the
     * Meilisearch fork and is gone.
     *
     * @return bool
     */
    public function isQueryRulesDisabled()
    {
        return true;
    }
}
