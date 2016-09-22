<?php
/*
 * Copyright 2007-2016 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <https://pydio.com>.
 */
namespace Pydio\Core\Services;


use Pydio\Auth\Core\AbstractAuthDriver;
use Pydio\Cache\Core\AbstractCacheDriver;
use Pydio\Conf\Core\AbstractConfDriver;

use Pydio\Core\Model\Context;

use Pydio\Core\Model\ContextInterface;
use Pydio\Core\PluginFramework\CoreInstanceProvider;
use Pydio\Core\Utils\ApplicationState;
use Pydio\Core\Utils\Vars\VarsFilter;
use Pydio\Core\PluginFramework\Plugin;
use Pydio\Core\PluginFramework\PluginsService;

defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * Configuration holder. Singleton class accessed statically, encapsulates the confDriver implementation.
 * @package Pydio
 * @subpackage Core
 */
class ConfService
{
    private static $instance;

    private $errors = array();
    private $configs = array();
    

    /**
     * @return AbstractConfDriver
     */
    public static function getBootConfStorageImpl()
    {
        $inst = PluginsService::getInstance(Context::emptyContext())->getPluginById("boot.conf");
        if (empty($inst)) {
            $inst = PluginsService::getInstance(Context::emptyContext())->softLoad("boot.conf", array());
        }
        return $inst;
    }

    /**
     * Initialize singleton
     * @static
     * @param string $installPath
     * @param string $pluginDir
     */
    public static function init($installPath=AJXP_INSTALL_PATH, $pluginDir="plugins")
    {
        $inst = self::getInstance();
        $inst->initInst();
    }

    /**
     * Load the boostrap_* files and their configs
     * @return void
     */
    private function initInst()
    {
        // INIT AS GLOBAL
        if (isSet($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) == "on") {
            $this->configs["USE_HTTPS"] = true;
        }
        if (isSet($this->configs["USE_HTTPS"])) {
            ApplicationState::safeIniSet("session.cookie_secure", true);
        }
        $this->configs["JS_DEBUG"] = AJXP_CLIENT_DEBUG;
        $this->configs["SERVER_DEBUG"] = AJXP_SERVER_DEBUG;

    }

    /**
     * Start the singleton
     * @static
     * @return void
     */
    public static function start()
    {
        $inst = self::getInstance();
        $inst->startInst();
        $confStorageDriver = self::getConfStorageImpl();
        $userFile = $confStorageDriver->getUserClassFileName();
        if(!empty($userFile)){
            require_once($userFile);
        }
    }
    /**
     * Init CONF, AUTH drivers
     * Init Repositories
     * @return void
     */
    public function startInst()
    {
        PluginsService::getInstance(Context::emptyContext())->setPluginUniqueActiveForType("conf", self::getConfStorageImpl()->getName());
    }
    /**
     * Get errors generated by the boot sequence (init/start)
     * @static
     * @return array
     */
    public static function getErrors()
    {
        return self::getInstance()->errors;
    }

    public static function clearAllCaches(){
        PluginsService::clearPluginsCache();
        LocaleService::clearMessagesCache();
        CacheService::deleteAll(AJXP_CACHE_SERVICE_NS_SHARED);
        if(function_exists('opcache_reset')){
            opcache_reset();
        }
    }

    /**
     * @static
     * @param $globalsArray
     * @param string $interfaceCheck
     * @param PluginsService|null $pService
     * @return Plugin|null
     */
    public static function instanciatePluginFromGlobalParams($globalsArray, $interfaceCheck = "", $pService = null)
    {
        $plugin = false;
        if($pService === null){
            $pService = PluginsService::getInstance(Context::emptyContext());
        }

        if (is_string($globalsArray)) {
            $globalsArray = array("instance_name" => $globalsArray);
        }

        if (is_array($globalsArray) &&  !isSet($globalsArray["instance_name"]) && isSet($globalsArray["group_switch_value"])){
            $globalsArray["instance_name"] = $globalsArray["group_switch_value"];
        }

        if (isSet($globalsArray["instance_name"])) {
            $pName = $globalsArray["instance_name"];
            unset($globalsArray["instance_name"]);

            $plugin = $pService->softLoad($pName, $globalsArray);
            $plugin->performChecks();
        }

        if ($plugin != false && !empty($interfaceCheck)) {
            if (!is_a($plugin, $interfaceCheck)) {
                $plugin = false;
            }
        }
        return $plugin;

    }
    
    /**
     * Check the presence of mcrypt and option CMDLINE_ACTIVE
     * @static
     * @return bool
     */
    public static function backgroundActionsSupported()
    {
        return function_exists("mcrypt_create_iv") && ConfService::getGlobalConf("CMDLINE_ACTIVE");
    }

    /**
     * @var AbstractConfDriver
     */
    private static $tmpConfStorageImpl;
    /**
     * @var AbstractAuthDriver
     */
    private static $tmpAuthStorageImpl;
    /**
     * @var AbstractCacheDriver
     */
    private static $tmpCacheStorageImpl;

    /**
     * @param $confStorage AbstractConfDriver
     * @param $authStorage AbstractAuthDriver
     * @param $cacheStorage AbstractCacheDriver
     */
    public static function setTmpStorageImplementations($confStorage, $authStorage, $cacheStorage)
    {
        self::$tmpConfStorageImpl = $confStorage;
        self::$tmpAuthStorageImpl = $authStorage;
        self::$tmpCacheStorageImpl = $cacheStorage;
    }

    /**
     * Get conf driver implementation
     *
     * @return AbstractConfDriver
     */
    public static function getConfStorageImpl()
    {
        if(isSet(self::$tmpConfStorageImpl)) return self::$tmpConfStorageImpl;
        /** @var CoreInstanceProvider $p */
        $p = PluginsService::getInstance(Context::emptyContext())->getPluginById("core.conf");
        return $p->getImplementation();
    }

    /**
     * Get auth driver implementation
     *
     * @return AbstractAuthDriver
     */
    public static function getAuthDriverImpl()
    {
        if(isSet(self::$tmpAuthStorageImpl)) return self::$tmpAuthStorageImpl;
        /** @var CoreInstanceProvider $p */
        $p = PluginsService::getInstance(Context::emptyContext())->getPluginById("core.auth");
        return $p->getImplementation();
    }

    /**
     * Return info about auth plugins
     * @return string
     */
    public static function getInfo(){
        return "&a=".self::getAuthDriverImpl()->getStats();
    }

    /**
     * Get auth driver implementation
     *
     * @return AbstractCacheDriver
     */
    public static function getCacheDriverImpl()
    {
        if(isSet(self::$tmpCacheStorageImpl)) return self::$tmpCacheStorageImpl;
        /**
         * Get CacheService implementation, directly from the "empty" plugin registry
         * @var CoreInstanceProvider $p
         */
        $p = PluginsService::getInstance(Context::emptyContext())->getPluginById("core.cache");
        return $p->getImplementation();
    }


    public function invalidateLoadedRepositories()
    {
        UsersService::invalidateCache();
        PluginsService::clearRegistryCaches();
    }
    
    
    /**
     *  ZIP FEATURES
     */

    /**
     * Check if the gzopen function exists
     * @static
     * @return bool
     */
    public static function zipEnabled()
    {
        return (function_exists("gzopen") || function_exists("gzopen64"));
    }

    /**
     * Check if users are allowed to browse ZIP content
     * @static
     * @return bool
     */
    public static function zipBrowsingEnabled()
    {
        if(!self::zipEnabled()) return false;
        return !ConfService::getGlobalConf("DISABLE_ZIP_BROWSING");
    }

    /**
     * Check if users are allowed to create ZIP archive
     * @static
     * @return bool
     */
    public static function zipCreationEnabled()
    {
        if(!self::zipEnabled()) return false;
        return ConfService::getGlobalConf("ZIP_CREATION");
    }
    
    /**
     * Get a config by its name
     * @static
     * @param string $varName
     * @return mixed
     */
    public static function getConf($varName)
    {
        return self::getInstance()->getConfInst($varName);
    }
    /**
     * Set a config by its name
     * @static
     * @param string $varName
     * @param mixed $varValue
     * @return void
     */
    public static function setConf($varName, $varValue)
    {
        self::getInstance()->setConfInst($varName, $varValue);
    }
    /**
     * See static method
     * @param $varName
     * @return mixed
     */
    protected function getConfInst($varName)
    {
        if (isSet($this->configs[$varName])) {
            return $this->configs[$varName];
        }
        if (defined("AJXP_".$varName)) {
            return constant("AJXP_".$varName);
        }
        return null;
    }
    /**
     * See static method
     * @param $varName
     * @param $varValue
     * @return void
     */
    protected function setConfInst($varName, $varValue)
    {
        $this->configs[$varName] = $varValue;
    }
    
    /**
     * Get config from the core.$coreType plugin without context
     * @static
     * @param string $varName
     * @param string $coreType
     * @return mixed|null|string
     */
    public static function getGlobalConf($varName, $coreType = "ajaxplorer")
    {
        $ctx = Context::emptyContext();
        $coreP = PluginsService::getInstance($ctx)->getPluginByTypeName("core", $coreType);
        if($coreP === false) return null;
        $confs = $coreP->getConfigs();
        return (isSet($confs[$varName]) ? VarsFilter::filter($confs[$varName], $ctx) : null);
    }

    /**
     * @param ContextInterface $ctx
     * @param $varName
     * @param string $coreType
     * @return mixed
     */
    public static function getContextConf(ContextInterface $ctx, $varName, $coreType = "ajaxplorer"){

        $coreP = PluginsService::getInstance($ctx)->getPluginByTypeName("core", $coreType);
        if($coreP === false) return null;
        $confs = $coreP->getConfigs();
        if($ctx->hasUser()){
            $confs = $ctx->getUser()->getMergedRole()->filterPluginConfigs("core".$coreType, $confs, $ctx->getRepositoryId());
        }
        return (isSet($confs[$varName]) ? VarsFilter::filter($confs[$varName], $ctx) : null);

    }

    /**
      * Singleton method
      *
      * @return ConfService the service instance
      */
     public static function getInstance()
     {
         if (!isSet(self::$instance)) {
             $c = __CLASS__;
             self::$instance = new $c;
         }
         return self::$instance;
     }

    /**
     * ConfService constructor.
     */
    private function __construct(){}
    public function __clone()
    {
        trigger_error("Cannot clone me, i'm a singleton!", E_USER_ERROR);
    }

}
