<?php

declare(strict_types=1);

/**
 * OpenMES Connector for PrestaShop
 *
 * Sends new orders to OpenMES as work orders when products
 * are marked as "manufactured" (custom product flag).
 * Automatically creates restock work orders when stock drops
 * to 0 or below for products that allow out-of-stock ordering.
 *
 * @author   OpenMES Team
 * @license  MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OpenmesConnector extends Module
{
    public const CONFIG_API_URL   = 'OPENMESCONN_API_URL';
    public const CONFIG_API_TOKEN = 'OPENMESCONN_API_TOKEN';
    public const CONFIG_LINE_ID   = 'OPENMESCONN_DEFAULT_LINE_ID';
    public const CONFIG_ENABLED   = 'OPENMESCONN_ENABLED';

    /** @var Db */
    private Db $db;

    /** @var int[] Product IDs that already had a WO created via actionValidateOrder in this request */
    private array $orderWoCreatedFor = [];

    public function __construct()
    {
        $this->name          = 'openmesconnector';
        $this->tab           = 'administration';
        $this->version       = '1.1.0';
        $this->author        = 'OpenMES Team';
        $this->need_instance = 0;
        $this->bootstrap     = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0',
            'max' => '8.99.99',
        ];

        parent::__construct();

        $this->db = Db::getInstance();

        $this->displayName = $this->l('OpenMES Connector');
        $this->description = $this->l(
            'Automatically creates work orders in OpenMES when orders contain manufactured products.'
        );
        $this->confirmUninstall = $this->l('Remove all OpenMES Connector settings?');
    }

    // ── Install / Uninstall ──────────────────────────────────────────────────

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionUpdateQuantity')
            && $this->registerHook('displayAdminProductsExtra')
            && $this->registerHook('actionProductSave')
            && $this->installDb()
            && $this->installTab();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallDb()
            && $this->uninstallTab()
            && Configuration::deleteByName(self::CONFIG_API_URL)
            && Configuration::deleteByName(self::CONFIG_API_TOKEN)
            && Configuration::deleteByName(self::CONFIG_LINE_ID)
            && Configuration::deleteByName(self::CONFIG_ENABLED);
    }

    private function installDb(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'openmesconn_product` (
            `id_product`        INT(10) UNSIGNED NOT NULL,
            `manufacture`       TINYINT(1) NOT NULL DEFAULT 0,
            `line_id`           INT(10) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (`id_product`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return $this->db->execute($sql);
    }

    private function uninstallDb(): bool
    {
        return $this->db->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'openmesconn_product`'
        );
    }

    private function installTab(): bool
    {
        $tab = new Tab();
        $tab->active      = 1;
        $tab->class_name  = 'AdminOpenmesConnector';
        $tab->name        = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'OpenMES Settings';
        }
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminAdmin');
        $tab->module    = $this->name;

        return $tab->add();
    }

    private function uninstallTab(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminOpenmesConnector');
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
    }

    // ── Module configuration page ────────────────────────────────────────────

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitOpenmesConnector')) {
            $output .= $this->saveSettings();
        }

        return $output . $this->renderSettingsForm();
    }

    private function saveSettings(): string
    {
        $apiUrl   = rtrim(Tools::getValue(self::CONFIG_API_URL), '/');
        $apiToken = Tools::getValue(self::CONFIG_API_TOKEN);
        $lineId   = (int) Tools::getValue(self::CONFIG_LINE_ID);
        $enabled  = (int) Tools::getValue(self::CONFIG_ENABLED);

        if (!empty($apiUrl) && !Validate::isUrl($apiUrl)) {
            return $this->displayError($this->l('Invalid API URL.'));
        }

        Configuration::updateValue(self::CONFIG_API_URL, $apiUrl);
        Configuration::updateValue(self::CONFIG_LINE_ID, $lineId);
        Configuration::updateValue(self::CONFIG_ENABLED, $enabled);

        if (!empty($apiToken)) {
            Configuration::updateValue(self::CONFIG_API_TOKEN, $apiToken);
        }

        return $this->displayConfirmation($this->l('Settings saved.'));
    }

    private function renderSettingsForm(): string
    {
        $options = $this->buildLineOptions($this->l('No default line'));

        $tokenDesc = $this->l(
            'Bearer token from OpenMES Settings. Leave blank to keep existing token.'
        );

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('OpenMES Connection Settings'),
                    'icon'  => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type'     => 'switch',
                        'label'    => $this->l('Enable integration'),
                        'name'     => self::CONFIG_ENABLED,
                        'is_bool'  => true,
                        'values'   => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('OpenMES API URL'),
                        'name'     => self::CONFIG_API_URL,
                        'required' => true,
                        'desc'     => $this->l('Base URL, e.g. https://demo.getopenmes.com'),
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->l('API Token'),
                        'name'     => self::CONFIG_API_TOKEN,
                        'desc'     => $tokenDesc,
                    ],
                    [
                        'type'     => 'select',
                        'label'    => $this->l('Default production line'),
                        'name'     => self::CONFIG_LINE_ID,
                        'options'  => [
                            'query' => $options,
                            'id'    => 'id',
                            'name'  => 'name',
                        ],
                        'desc'     => $this->l('Used when a product has no specific line assigned.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar      = false;
        $helper->table             = '';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action     = 'submitOpenmesConnector';
        $helper->currentIndex      = AdminController::$currentIndex
            . '&configure=' . $this->name;
        $helper->token             = Tools::getAdminTokenLite('AdminModules');

        $helper->fields_value = [
            self::CONFIG_API_URL   => Configuration::get(self::CONFIG_API_URL),
            self::CONFIG_API_TOKEN => '',
            self::CONFIG_LINE_ID   => (int) Configuration::get(self::CONFIG_LINE_ID),
            self::CONFIG_ENABLED   => (int) Configuration::get(self::CONFIG_ENABLED),
        ];

        return $helper->generateForm([$form]);
    }

    // ── Hooks ────────────────────────────────────────────────────────────────

    public function hookDisplayAdminProductsExtra(array $params): string
    {
        try {
            $idProduct = (int) ($params['id_product'] ?? Tools::getValue('id_product'));
            if (!$idProduct) {
                return '';
            }

            $row = $this->getManufactureRow($idProduct, false);

            $this->context->smarty->assign([
                'openmesconn_manufacture' => (bool) ($row['manufacture'] ?? false),
                'openmesconn_line_id'     => (int) ($row['line_id'] ?? 0),
                'openmesconn_lines'       => $this->buildLineOptions($this->l('Use default line')),
            ]);

            return $this->display(__FILE__, 'views/templates/hook/product_tab.tpl');
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[OpenMES] Error in hookDisplayAdminProductsExtra: ' . $e->getMessage(),
                3
            );
            return '';
        }
    }

    public function hookActionProductSave(array $params): void
    {
        try {
            $idProduct = (int) ($params['id_product'] ?? 0);
            if (!$idProduct) {
                return;
            }

            $manufacture = (int) Tools::getValue('openmesconn_manufacture', 0);
            $lineId      = (int) Tools::getValue('openmesconn_line_id', 0) ?: null;

            $this->db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'openmesconn_product`
                    (id_product, manufacture, line_id)
                 VALUES ('
                    . (int) $idProduct . ', '
                    . (int) $manufacture . ', '
                    . ((int) $lineId ?: 'NULL') . ')
                 ON DUPLICATE KEY UPDATE
                    manufacture = ' . (int) $manufacture . ',
                    line_id     = ' . ((int) $lineId ?: 'NULL')
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[OpenMES] Error in hookActionProductSave: ' . $e->getMessage(),
                3,
                null,
                'Product',
                (int) ($params['id_product'] ?? 0)
            );
        }
    }

    public function hookActionValidateOrder(array $params): void
    {
        try {
            if (!(int) Configuration::get(self::CONFIG_ENABLED)) {
                return;
            }

            /** @var Order $order */
            $order = $params['order'] ?? null;
            if (!$order || !Validate::isLoadedObject($order)) {
                return;
            }

            $credentials = $this->getApiCredentials();
            if (!$credentials) {
                PrestaShopLogger::addLog(
                    '[OpenMES] Integration not configured — skipping order #' . $order->id,
                    2,
                    null,
                    'Order',
                    (int) $order->id
                );
                return;
            }

            foreach ($order->getProducts() as $product) {
                $idProduct = (int) $product['product_id'];
                $row = $this->getManufactureRow($idProduct);

                if (!$row) {
                    continue;
                }

                $lineId = $this->resolveLineId($row);
                $this->createOrderWorkOrder($credentials, $order, $product, $lineId);

                $this->orderWoCreatedFor[] = $idProduct;
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[OpenMES] Error in hookActionValidateOrder: ' . $e->getMessage(),
                3,
                null,
                'Order',
                (int) ($params['order']->id ?? 0)
            );
        }
    }

    /**
     * When stock drops to 0 or below, create a restock work order in OpenMES
     * for products that are marked as manufactured AND allow ordering
     * when out of stock.
     */
    public function hookActionUpdateQuantity(array $params): void
    {
        try {
            if (!(int) Configuration::get(self::CONFIG_ENABLED)) {
                return;
            }

            $idProduct          = (int) ($params['id_product'] ?? 0);
            $idProductAttribute = (int) ($params['id_product_attribute'] ?? 0);
            $newQty             = (int) ($params['quantity'] ?? 0);

            if (!$idProduct || $newQty > 0) {
                return;
            }

            // Skip if actionValidateOrder already created a WO for this product in this request
            if (in_array($idProduct, $this->orderWoCreatedFor, true)) {
                return;
            }

            $row = $this->getManufactureRow($idProduct);
            if (!$row) {
                return;
            }

            if (!$this->isOrderableWhenOutOfStock($idProduct)) {
                return;
            }

            $credentials = $this->getApiCredentials();
            if (!$credentials) {
                PrestaShopLogger::addLog(
                    '[OpenMES] Integration not configured — skipping restock for product #'
                        . $idProduct,
                    2,
                    null,
                    'Product',
                    $idProduct
                );
                return;
            }

            $productObj = new Product(
                $idProduct,
                false,
                (int) Configuration::get('PS_LANG_DEFAULT')
            );
            $lineId     = $this->resolveLineId($row);
            $plannedQty = $newQty < 0 ? (float) abs($newQty) : 1.0;
            $orderNo    = 'PS-RESTOCK-' . $idProduct
                . ($idProductAttribute ? '-' . $idProductAttribute : '')
                . '-' . bin2hex(random_bytes(4));

            $payload = [
                'order_no'    => $orderNo,
                'planned_qty' => $plannedQty,
                'description' => $this->l('Auto restock — product out of stock')
                    . ': ' . $productObj->name . ' (stock: ' . $newQty . ')',
                'extra_data'  => [
                    'source'               => 'prestashop',
                    'trigger'              => 'out_of_stock',
                    'ps_product_id'        => $idProduct,
                    'ps_product_attribute' => $idProductAttribute,
                    'ps_product_name'      => $productObj->name,
                    'ps_product_ref'       => $productObj->reference ?? '',
                    'ps_stock_quantity'    => $newQty,
                ],
            ];

            if ($lineId) {
                $payload['line_id'] = $lineId;
            }

            $this->sendWorkOrder($credentials, $payload, 'Product', $idProduct);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[OpenMES] Error in hookActionUpdateQuantity: ' . $e->getMessage(),
                3,
                null,
                'Product',
                (int) ($params['id_product'] ?? 0)
            );
        }
    }

    // ── OpenMES API ──────────────────────────────────────────────────────────

    /**
     * Get API URL and token from configuration.
     *
     * @return array{url: string, token: string}|null
     */
    private function getApiCredentials(): ?array
    {
        $url   = rtrim((string) Configuration::get(self::CONFIG_API_URL), '/');
        $token = (string) Configuration::get(self::CONFIG_API_TOKEN);

        if ($url === '' || $token === '') {
            return null;
        }

        return ['url' => $url, 'token' => $token];
    }

    /**
     * Build a work-order payload for a validated PS order and send it.
     */
    private function createOrderWorkOrder(
        array $credentials,
        Order $order,
        array $product,
        ?int $lineId
    ): void {
        $orderRef = $order->reference
            ?? ('PS-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT));
        $orderNo  = 'PS-' . $orderRef . '-' . $product['product_id'];

        $payload = [
            'order_no'    => $orderNo,
            'planned_qty' => (float) $product['product_quantity'],
            'description' => $this->l('PrestaShop order')
                . ' #' . $orderRef . ' — ' . $product['product_name'],
            'extra_data'  => [
                'source'          => 'prestashop',
                'trigger'         => 'order',
                'ps_order_id'     => (int) $order->id,
                'ps_order_ref'    => $orderRef,
                'ps_product_id'   => (int) $product['product_id'],
                'ps_product_name' => $product['product_name'],
                'ps_product_ref'  => $product['product_reference'] ?? '',
                'ps_customer_id'  => (int) $order->id_customer,
            ],
        ];

        if ($lineId) {
            $payload['line_id'] = $lineId;
        }

        $this->sendWorkOrder($credentials, $payload, 'Order', (int) $order->id);
    }

    /**
     * POST a work-order payload to OpenMES and log the result.
     *
     * @param array{url: string, token: string} $credentials
     */
    private function sendWorkOrder(
        array $credentials,
        array $payload,
        string $objectType,
        int $objectId
    ): void {
        $response = $this->apiPost(
            $credentials['url'] . '/api/v1/work-orders',
            $credentials['token'],
            $payload
        );

        $orderNo = $payload['order_no'];

        if ($response === false || isset($response['error'])) {
            $msg = $response['message'] ?? 'Unknown error';
            PrestaShopLogger::addLog(
                '[OpenMES] Failed to create work order ' . $orderNo . ': ' . $msg,
                3,
                null,
                $objectType,
                $objectId
            );
        } else {
            $woId = $response['data']['id'] ?? '?';
            PrestaShopLogger::addLog(
                '[OpenMES] Work order created: ' . $orderNo . ' (ID: ' . $woId . ')',
                1,
                null,
                $objectType,
                $objectId
            );
        }
    }

    private function fetchOpenMesLines(): array
    {
        $credentials = $this->getApiCredentials();
        if (!$credentials) {
            return [];
        }

        $response = $this->apiGet(
            $credentials['url'] . '/api/v1/lines',
            $credentials['token']
        );

        return (is_array($response) && isset($response['data']))
            ? $response['data']
            : [];
    }

    /**
     * @return array|false Decoded JSON body or false on failure
     */
    private function apiPost(string $url, string $token, array $payload): array|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $body      = curl_exec($ch);
        $err       = curl_error($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3);
        curl_close($ch);

        if ($body === false || $err) {
            PrestaShopLogger::addLog(
                '[OpenMES] cURL POST error for ' . $url . ': ' . $err,
                3
            );
            return false;
        }

        if ($httpCode >= 400) {
            PrestaShopLogger::addLog(
                '[OpenMES] cURL POST ' . $url
                    . ' — HTTP ' . $httpCode . ' in ' . $totalTime . 's'
                    . ' — response: ' . $this->truncateForLog((string) $body),
                3
            );
            $decoded = json_decode((string) $body, true);
            if (is_array($decoded)) {
                $decoded['_http_code'] = $httpCode;
                return $decoded;
            }
            return false;
        }

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : false;
    }

    /**
     * @return array|false Decoded JSON body or false on failure
     */
    private function apiGet(string $url, string $token): array|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $body      = curl_exec($ch);
        $err       = curl_error($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3);
        curl_close($ch);

        if ($body === false || $err) {
            PrestaShopLogger::addLog(
                '[OpenMES] cURL GET error for ' . $url . ': ' . $err,
                3
            );
            return false;
        }

        if ($httpCode >= 400) {
            PrestaShopLogger::addLog(
                '[OpenMES] cURL GET ' . $url
                    . ' — HTTP ' . $httpCode . ' in ' . $totalTime . 's'
                    . ' — response: ' . $this->truncateForLog((string) $body),
                3
            );
            return false;
        }

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : false;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Fetch the manufacture row for a product.
     * When $onlyManufactured is true, only returns rows with manufacture = 1.
     */
    private function getManufactureRow(int $idProduct, bool $onlyManufactured = true): ?array
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('openmesconn_product')
            ->where('id_product = ' . (int) $idProduct);

        if ($onlyManufactured) {
            $sql->where('manufacture = 1');
        }

        $row = $this->db->getRow($sql);
        return $row ?: null;
    }

    /**
     * Resolve the production line ID — product-specific or module default.
     */
    private function resolveLineId(array $row): ?int
    {
        $lineId = (int) ($row['line_id'] ?: Configuration::get(self::CONFIG_LINE_ID));
        return $lineId ?: null;
    }

    /**
     * Check if a product allows ordering when out of stock.
     */
    private function isOrderableWhenOutOfStock(int $idProduct): bool
    {
        $outOfStock = (int) StockAvailable::outOfStock(
            $idProduct,
            null,
            Context::getContext()->shop->id ?? null
        );

        // 0 = deny orders, 1 = allow orders, 2 = use global setting
        if ($outOfStock === 0) {
            return false;
        }

        if ($outOfStock === 2) {
            return (bool) (int) Configuration::get('PS_ORDER_OUT_OF_STOCK');
        }

        return true;
    }

    /**
     * Build line options array for select dropdowns.
     */
    private function buildLineOptions(string $emptyLabel): array
    {
        $lines   = $this->fetchOpenMesLines();
        $options = [['id' => 0, 'name' => '— ' . $emptyLabel . ' —']];

        foreach ($lines as $line) {
            $options[] = ['id' => (int) $line['id'], 'name' => $line['name']];
        }

        return $options;
    }

    /**
     * Truncate a string for safe log storage.
     */
    private function truncateForLog(string $text, int $maxLength = 500): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength) . '... [truncated]';
    }
}
