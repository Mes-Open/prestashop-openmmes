<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminOpenmesConnectorController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    public function initContent(): void
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=openmesconnector'
        );
    }
}
