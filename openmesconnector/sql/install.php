<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'openmesconn_product` (
    `id_product`    INT(10) UNSIGNED NOT NULL,
    `manufacture`   TINYINT(1)       NOT NULL DEFAULT 0,
    `line_id`       INT(10) UNSIGNED          DEFAULT NULL,
    PRIMARY KEY (`id_product`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}

return true;
