<?php 

/**
 * ShipStation for Community Store
 *
 * @package     Public
 * @subpackage  Community Store ShipStation
 * @author      David Buonomo <dnb@blueatlas.com>
 * @copyright   2019 David Buonomo (c)
 * @version     1.0
 * @license     The MIT License (MIT)
 *
 */

namespace Concrete\Package\CommunityStoreShipStation;

use Package;
use Route;
use Page;
use SinglePage;
use Config;

class Controller extends Package
{
    protected $pkgHandle = 'community_store_ship_station';
    protected $appVersionRequired = '5.7.1';
    protected $pkgVersion = '0.9.0';

    public function getPackageDescription()
    {
        return t("ShipStation for Community Store using the custom store method.");
    }

    public function getPackageName()
    {
        return t("ShipStation for Community Store");
    }
    
    public function install()
    {
        $pkg = parent::install();

        $installed = Package::getInstalledHandles();
        if (!(is_array($installed) && in_array('community_store', $installed)))
            throw new ErrorException(t('This package requires concrete5 Community Store.'));

        self::setConfigValue('ship_station.mode', 'test');
        self::setConfigValue('ship_station.orders_per_page', 100);
        self::setConfigValue('ship_station.auth.enabled', false);
        self::setConfigValue('ship_station.auth.username', '');
        self::setConfigValue('ship_station.auth.password', '');
        self::setConfigValue('ship_station.other.name1', 'value1');
        self::setConfigValue('ship_station.other.name2', 'value2');

        $page = self::installSinglePage('/dashboard/store/ship_station/', 'ShipStation', $pkg);
    }

    private function setConfigValue($key, $value)
    {
        if (!Config::has($key)) {
            Config::save($key, $value);
        }
    }

    public static function installSinglePage($path, $name, $pkg)
    {
        $page = Page::getByPath($path);
        if (!is_object($page) || $page->isError()) {
            $page = SinglePage::add($path, $pkg);
            $page->update(array('cName' => t($name)));
        }
    }

    public function on_start()
    {
        Route::register('/api/shipstation', '\Concrete\Package\CommunityStoreShipStation\Controller\ShipStation::response');
    }

    public function uninstall()
    {
        parent::uninstall();    
    }

    public function upgrade()
    {
        parent::upgrade();    
    }
}
?>
