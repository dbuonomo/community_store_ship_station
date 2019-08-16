<?php

/**
 * ShipStation for Community Store
 *
 * @package     Public
 * @subpackage  Community Store ShipStation
 * @author      David Buonomo <dnb@blueatlas.com>
 * @copyright   2018 David Buonomo (c)
 * @version     1.0
 * @license     The MIT License (MIT)
 *
 */

namespace Concrete\Package\CommunityStoreShipStation\Controller\SinglePage\Dashboard\Store;

use Concrete\Core\Page\Controller\DashboardPageController;
use Config;

class ShipStation extends DashboardPageController
{
    public function view()
    {
        $this->set('pageTitle', t('ShipStation'));
    }
}
