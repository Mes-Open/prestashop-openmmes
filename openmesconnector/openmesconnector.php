<?php
/**
 * OpenMES Connector for PrestaShop
 *
 * Sends new orders to OpenMES as work orders when products
 * are marked as "manufactured" (custom product flag).
 *
 * @author   OpenMES Team
 * @license  MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OpenmesConnector extends Module
{
    const CONFIG_API_URL    = 'OPENMESCONN_API_URL';
    const CONFIG_API_TOKEN  = 'OPENMESCONN_API_TOKEN';
    const CONFIG_LINE_ID    = 'OPENMESCONN_DEFAULT_LINE_ID';
    const CONFIG_ENABLED    = 'OPENMESCONN_ENABLED';

    public function __construct()
    {
        $this->name          = 'openmesconnector';
        $this->tab           = 'administration';
        $this->version       = '1.0.0';
        $this->author        = 'OpenMES Team';
        $this->need_instance = 0;
        $this->bootstrap     = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0',
            'max' => '8.99.99',
        ];

        parent::__construct();

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

        return Db::getInstance()->execute($sql);
    }

    private function uninstallDb(): bool
    {
        return Db::getInstance()->execute(
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
        $id_tab = (int) Tab::getIdFromClassName('AdminOpenmesConnector');
        if ($id_tab) {
            $tab = new Tab($id_tab);
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

        Configuration::updateValue(self::CONFIG_API_URL,   $apiUrl);
        Configuration::updateValue(self::CONFIG_LINE_ID,   $lineId);
        Configuration::updateValue(self::CONFIG_ENABLED,   $enabled);

        if (!empty($apiToken)) {
            Configuration::updateValue(self::CONFIG_API_TOKEN, $apiToken);
        }

        return $this->displayConfirmation($this->l('Settings saved.'));
    }

    private function renderSettingsForm(): string
    {
        $lines   = $this->fetchOpenMesLines();
        $options = [['id' => 0, 'name' => '— ' . $this->l('No default line') . ' —']];
        foreach ($lines as $line) {
            $options[] = ['id' => (int) $line['id'], 'name' => $line['name']];
        }

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
                            ['id' => 'active_on',  'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('OpenMES API URL'),
                        'name'     => self::CONFIG_API_URL,
                        'required' => true,
                        'desc'     => $this->l('Base URL of your OpenMES instance, e.g. https://demo.getopenmes.com'),
                    ],
                    [
                        'type'     => 'password',
                        'label'    => $this->l('API Token'),
                        'name'     => self::CONFIG_API_TOKEN,
                        'desc'     => $this->l('Bearer token from OpenMES Settings → API Tokens. Leave blank to keep existing token.'),
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
        $helper->currentIndex      = AdminController::$currentIndex . '&configure=' . $this->name;
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
        $idProduct = (int) ($params['id_product'] ?? Tools::getValue('id_product'));
        if (!$idProduct) {
            return '';
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'openmesconn_product` WHERE id_product = ' . $idProduct
        );

        $lines    = $this->fetchOpenMesLines();
        $options  = [['id' => 0, 'name' => '— ' . $this->l('Use default line') . ' —']];
        foreach ($lines as $line) {
            $options[] = ['id' => (int) $line['id'], 'name' => $line['name']];
        }

        $this->context->smarty->assign([
            'openmesconn_manufacture' => (bool) ($row['manufacture'] ?? false),
            'openmesconn_line_id'     => (int)  ($row['line_id']     ?? 0),
            'openmesconn_lines'       => $options,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/product_tab.tpl');
    }

    public function hookActionProductSave(array $params): void
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        if (!$idProduct) {
            return;
        }

        $manufacture = (int) Tools::getValue('openmesconn_manufacture', 0);
        $lineId      = (int) Tools::getValue('openmesconn_line_id', 0) ?: null;

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'openmesconn_product`
                (id_product, manufacture, line_id)
             VALUES (' . $idProduct . ', ' . $manufacture . ', ' . ($lineId ?? 'NULL') . ')
             ON DUPLICATE KEY UPDATE
                manufacture = ' . $manufacture . ',
                line_id     = ' . ($lineId ?? 'NULL')
        );
    }

    public function hookActionValidateOrder(array $params): void
    {
        if (!(int) Configuration::get(self::CONFIG_ENABLED)) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return;
        }

        foreach ($order->getProducts() as $product) {
            $idProduct = (int) $product['product_id'];
            $row = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'openmesconn_product`
                 WHERE id_product = ' . $idProduct . ' AND manufacture = 1'
            );

            if (!$row) {
                continue;
            }

            $lineId = (int) ($row['line_id'] ?: Configuration::get(self::CONFIG_LINE_ID)) ?: null;
            $this->createOpenMesWorkOrder($order, $product, $lineId);
        }
    }

    // ── OpenMES API ──────────────────────────────────────────────────────────

    private function createOpenMesWorkOrder(Order $order, array $product, ?int $lineId): void
    {
        $apiUrl   = rtrim(Configuration::get(self::CONFIG_API_URL), '/');
        $apiToken = Configuration::get(self::CONFIG_API_TOKEN);

        if (empty($apiUrl) || empty($apiToken)) {
            PrestaShopLogger::addLog(
                '[OpenMES] Integration not configured — skipping order #' . $order->id,
                2, null, 'Order', (int) $order->id
            );
            return;
        }

        $orderRef = $order->reference ?? ('PS-' . str_pad($order->id, 8, '0', STR_PAD_LEFT));
        $orderNo  = 'PS-' . $orderRef . '-' . $product['product_id'];

        $payload = [
            'order_no'    => $orderNo,
            'planned_qty' => (float) $product['product_quantity'],
            'description' => $this->l('PrestaShop order') . ' #' . $orderRef . ' — ' . $product['product_name'],
            'extra_data'  => [
                'source'          => 'prestashop',
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

        $response = $this->apiPost($apiUrl . '/api/v1/work-orders', $apiToken, $payload);

        if ($response === false || isset($response['error'])) {
            $msg = $response['message'] ?? 'Unknown error';
            PrestaShopLogger::addLog(
                '[OpenMES] Failed to create work order for order #' . $order->id . ': ' . $msg,
                3, null, 'Order', (int) $order->id
            );
        } else {
            PrestaShopLogger::addLog(
                '[OpenMES] Work order created: ' . $orderNo . ' (ID: ' . ($response['data']['id'] ?? '?') . ')',
                1, null, 'Order', (int) $order->id
            );
        }
    }

    private function fetchOpenMesLines(): array
    {
        $apiUrl   = rtrim(Configuration::get(self::CONFIG_API_URL), '/');
        $apiToken = Configuration::get(self::CONFIG_API_TOKEN);

        if (empty($apiUrl) || empty($apiToken)) {
            return [];
        }

        $response = $this->apiGet($apiUrl . '/api/v1/lines', $apiToken);

        return (is_array($response) && isset($response['data'])) ? $response['data'] : [];
    }

    private function apiPost(string $url, string $token, array $payload): array|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err) {
            PrestaShopLogger::addLog('[OpenMES] cURL error: ' . $err, 3);
            return false;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : false;
    }

    private function apiGet(string $url, string $token): array|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err) {
            return false;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : false;
    }
}
