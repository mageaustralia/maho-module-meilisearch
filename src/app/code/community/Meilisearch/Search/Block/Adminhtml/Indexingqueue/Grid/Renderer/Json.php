<?php

/**
 * SPDX-License-Identifier: OSL-3.0
 * Copyright (c) 2026 Mageaus.
 */

class Meilisearch_Search_Block_Adminhtml_Indexingqueue_Grid_Renderer_Json extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    /**
     * @return string
     */
    #[\Override]
    public function render(\Maho\DataObject $row)
    {
        $html = '';
        if ($json = $row->getData('data')) {
            try {
                $json = Mage::helper('core')->jsonDecode((string) $json);
            } catch (JsonException $e) {
                return $this->escapeHtml((string) $row->getData('data'));
            }

            foreach ($json as $var => $value) {
                $html .= $var . ': ' . (is_array($value) ? implode(',', $value) : $value) . '<br/>';
            }
        }
        return $html;
    }
}
