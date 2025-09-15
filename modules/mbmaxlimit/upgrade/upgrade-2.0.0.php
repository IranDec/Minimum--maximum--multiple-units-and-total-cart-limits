<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade script for version 2.0.0
 * - Adds id_product_attribute to mbmaxlimit_product table
 * - Changes primary key to a composite key
 * @param Mbmaxlimit $module
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    $sql = 'ALTER TABLE `' . _DB_PREFIX_ . 'mbmaxlimit_product`
        ADD `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id_product`,
        DROP PRIMARY KEY,
        ADD PRIMARY KEY (`id_product`, `id_product_attribute`)';

    // Check if the column already exists to prevent errors on re-run
    $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'mbmaxlimit_product` LIKE \'id_product_attribute\'');
    if (empty($columns)) {
        if (!Db::getInstance()->execute($sql)) {
            return false;
        }
    }

    return true;
}
