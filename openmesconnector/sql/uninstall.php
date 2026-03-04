<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'openmesconn_product`');

return true;
