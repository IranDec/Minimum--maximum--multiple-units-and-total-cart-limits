<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade script for version 2.1.0
 * - Adds time_frame to mbmaxlimit_rule table for time-based limits
 * @param Mbmaxlimit $module
 * @return bool
 */
function upgrade_module_2_1_0($module)
{
    // Check if the column already exists to prevent errors on re-run
    $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'mbmaxlimit_rule` LIKE \'time_frame\'');
    if (empty($columns)) {
        $sql = 'ALTER TABLE `' . _DB_PREFIX_ . 'mbmaxlimit_rule`
            ADD `time_frame` VARCHAR(32) NOT NULL DEFAULT \'all_time\' AFTER `dow_mask`';

        if (!Db::getInstance()->execute($sql)) {
            return false;
        }
    }

    return true;
}
