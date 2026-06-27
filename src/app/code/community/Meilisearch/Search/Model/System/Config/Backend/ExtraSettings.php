<?php

/**
 * SPDX-License-Identifier: OSL-3.0
 * Copyright (c) 2026 Mageaus.
 */

class Meilisearch_Search_Model_System_Config_Backend_ExtraSettings extends Mage_Core_Model_Config_Data
{
    #[\Override]
    protected function _beforeSave()
    {
        $value = trim($this->getValue());

        if (empty($value)) {
            return parent::_beforeSave();
        }

        $fieldConfig = $this->getFieldConfig();
        $label = (string) $fieldConfig->label;

        try {
            Mage::helper('core')->jsonDecode($value);
        } catch (JsonException $e) {
            Mage::throwException('JSON provided for "' . $label . '" field is not valid JSON.');
        }

        return parent::_beforeSave();
    }
}
