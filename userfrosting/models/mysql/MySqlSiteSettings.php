<?php

namespace UserFrosting;

/**
 * MySqlSiteSettings Class
 *
 * A site settings database object for MySQL databases.
 *
 * @package UserFrosting
 * @author Alex Weissman
 * @link http://alexanderweissman.com
 */
class MySqlSiteSettings extends MySqlDatabase implements SiteSettingsInterface {

    protected $_environment;            // An array of UF environment variables.  Should be read-only.
    protected $_settings;               // A list of plugins => arrays of settings for that plugin.  The core plugin is "userfrosting".
    protected $_descriptions;
    protected $_settings_registered;    // A list of settings that have been registered to appear in the site settings interface.
    
    protected $_table;

    /** Construct the site settings object, loading values from the database */
    public function __construct($settings = [], $descriptions = []) {
        $this->_table = static::getTableConfiguration();
        
        // Initialize UF environment
        $this->initEnvironment();
        
        // Set default settings first
        $this->_settings = $settings;
        $this->_descriptions = $descriptions;
        
        // Now, try to load settings from database if possible
        try {
            $results = $this->fetchSettings();
            // Merge, replacing default settings with DB settings as necessary.
            $this->_settings = array_replace_recursive($this->_settings , $results['settings']);
            $this->_descriptions = array_replace_recursive($this->_descriptions, $results['descriptions']);
        } catch (\PDOException $e){
            $connection = static::connection();
            $prefix = static::$app->config('db')['db_prefix'];
            
            // If the database connection is fine, but the table doesn't exist, create it!
            $connection->query("CREATE TABLE IF NOT EXISTS `$prefix" . "configuration` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `plugin` varchar(50) NOT NULL COMMENT 'The name of the plugin that manages this setting (set to ''userfrosting'' for core settings)',
                `name` varchar(150) NOT NULL COMMENT 'The name of the setting.',
                `value` longtext NOT NULL COMMENT 'The current value of the setting.',
                `description` text NOT NULL COMMENT 'A brief description of this setting.',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='A configuration table, mapping global configuration options to their values.' AUTO_INCREMENT=1 ;");
        }
    }
    
    // Determine if any setting appears in the object but not the DB.
    public function isConsistent(){
        $connection = static::connection();
        $prefix = static::$app->config('db')['db_prefix'];
        
        $table_exists = $connection->query("SHOW TABLES LIKE '$prefix" . "configuration'")->rowCount() > 0;
        
        if (!$table_exists){
            return false;
        }

        $db_data = $this->fetchSettings()['settings'];
        foreach ($this->_settings as $plugin => $setting){
            if (!isset($db_data[$plugin])){
                return false;
            }
            foreach ($setting as $name => $value){
                if (!isset($db_data[$plugin][$name])){
                    return false;
                }
            }
        }
        return true;
    }
    
    // Retrieve all site settings from the DB.  
    public function fetchSettings(){
        $db = static::connection();
        
        $table = $this->_table;
        
        $stmt = $db->query("SELECT * FROM `$table`");
                  
        $results = [];
        $results['settings'] = [];
        $results['descriptions'] = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $name = $row['name'];
            $value = $row['value'];
            $plugin = $row['plugin'];
            $description = $row['description'];
            if (!isset($results['settings'][$plugin])) {
                $results['settings'][$plugin] = [];
                $results['descriptions'][$plugin] = [];
            }
                        
            $results['settings'][$plugin][$name] = $value;
            $results['descriptions'][$plugin][$name] = $description;          
        }
        return $results;
    }
    
    private function initEnvironment(){
        /***** Site Environment Setup *****/
        
        // Auto-detect the public root URI
        $environment = static::$app->environment();
        
        // TODO: can we trust this?  should we revert to storing this in the DB?
        $uri_public_root = $environment['slim.url_scheme'] . "://" . $environment['SERVER_NAME'] . $environment['SCRIPT_NAME'];
        
        $this->_environment = [
            'uri' => [
                'public' =>    $uri_public_root,
                'js' =>        $uri_public_root . "/js/",
                'css' =>       $uri_public_root . "/css/",        
                'favicon' =>   $uri_public_root . "/css/favicon.ico",
                'image' =>     $uri_public_root . "/images/"
            ]
        ];
    }
    
    public function __isset($name) {
        if (isset($this->_environment[$name]) || isset($this->_settings['userfrosting'][$name]))
            return true;
        else
            return false;
    }
    
    // Set the value of a core UF setting
    public function __set($name, $value){
        return $this->set('userfrosting', $name, $value);   
    }

    // Get a core UF setting
    public function __get($name){
        if (isset($this->_environment[$name])){
            return $this->_environment[$name];
        } else if (isset($this->_settings['userfrosting'][$name])){
            return $this->_settings['userfrosting'][$name];
        } else {
            throw new \Exception("The value '$name' does not exist in the core userfrosting settings.");
        }
    }

    public function set($plugin, $name, $value = null, $description = null){
        if (!isset($this->_settings[$plugin])){
            $this->_settings[$plugin] = [];
            $this->_descriptions[$plugin] = [];
        }
        if ($value !== null) {
            $this->_settings[$plugin][$name] = $value; 
        } else {
            if (!isset($this->_settings[$plugin][$name]))
                $this->_settings[$plugin][$name] = ""; 
        }
        if ($description !== null) {
            $this->_descriptions[$plugin][$name] = $description; 
        } else {
            if (!isset($this->_descriptions[$plugin][$name]))
                $this->_descriptions[$plugin][$name] = ""; 
        }
    }
    
    // Register a setting to appear in the site settings interface
    public function register($plugin, $name, $label, $type = "text", $options = []){
        // Get the array of settings & descriptions
        if (isset($this->_settings[$plugin])){
            $settings = $this->_settings[$plugin];
            $descriptions = $this->_descriptions[$plugin];      
        } else {
            throw new \Exception("The plugin '$plugin' does not have any site settings.  Be sure to add them first by calling set().");
        }

        if (!isset($settings[$name])){
            throw new \Exception("The plugin '$plugin' does not have a value for '$name'.  Please add it first by calling set().");
        }
        
        // Check type
        if (!in_array($type, ["readonly", "text", "toggle", "select"]))
            throw new \Exception("Type must be one of 'readonly', 'text', 'toggle', or 'select'.");
            
        if (!isset($this->_settings_registered[$plugin]))
            $this->_settings_registered[$plugin] = [];
        if (!isset($this->_settings_registered[$plugin][$name]))
            $this->_settings_registered[$plugin][$name] = [];
            
        $this->_settings_registered[$plugin][$name]['label'] = $label;
        $this->_settings_registered[$plugin][$name]['type'] = $type;
        $this->_settings_registered[$plugin][$name]['options'] = $options;
        $this->_settings_registered[$plugin][$name]['description'] = $descriptions[$name];
    }
    
    public function getRegisteredSettings(){
        foreach ($this->_settings_registered as $plugin => $setting){
            foreach ($setting as $name => $params){
                $this->_settings_registered[$plugin][$name]['value'] = $this->_settings[$plugin][$name];
            }
        }
        return $this->_settings_registered;
    }
    
    // Get a list of all supported locales
    public function getLocales(){
    	$directory = static::$app->config('locales.path');
        $languages = glob($directory . "/*.php");
        $results = [];
        foreach ($languages as $language){
            $basename = basename($language, ".php");
            $results[$basename] = $basename;
        }
        return $results;
    }

    // Get a list of all supported themes
    public function getThemes(){
    	$directory = static::$app->config('themes.path');
        $themes = glob($directory . "/*", GLOB_ONLYDIR);
        $results = [];
        foreach ($themes as $theme){
            $basename = basename($theme);
            $results[$basename] = $basename;
        }
        return $results;
    }
    
    // Get a list of all plugins
    public function getPlugins(){
    	$directory = static::$app->config('plugins.path');
        $themes = glob($directory . "/*", GLOB_ONLYDIR);
        $results = [];
        foreach ($themes as $theme){
            $basename = basename($theme);
            $results[$basename] = $basename;
        }
        return $results;
    }
    // Return an array of system and server configuration info
    public function getSystemInfo(){
        $results = [];
        $results['UserFrosting Version'] = $this->version;
        $results['Web Server'] = $_SERVER['SERVER_SOFTWARE'];
        $results['PHP Version'] = phpversion();
        $dbinfo = static::getInfo();
        $results['Database Version'] = $dbinfo['db_type'] . " " .  $dbinfo['db_version'];
        $results['Database Name'] = $dbinfo['db_name'];
        $results['Table Prefix'] = $dbinfo['table_prefix'];
        $environment = static::$app->environment();
        $results['Application Root'] = static::$app->config('base.path');
        $results['Document Root'] = $this->uri['public'];
        return $results;
    }
    
    // Return the error log
    public function getLog($lines = null){
        // Check if error logging is enabled
        if (!ini_get("error_log")){
            $path = "Unavailable";
            $messages = ["You do not seem to have an error log set up.  Please check your php.ini file."];
        } else if (!ini_get("log_errors")){
            $path = ini_get('error_log');
            $messages = ["Error logging appears to be disabled.  Please check your php.ini file."];
        } else {    
            $path = ini_get('error_log');
            if ($lines){
                $messages = array_reverse(array_slice(file($path), -$lines));
            } else {
                $messages = array_reverse(file($path));
            }
        }
        return [
            "path"      => $path,
            "messages"  => $messages
        ];
    }
    
    // Update site settings in database
    public function store(){
        // Get current values as stored in DB
        $db_settings = $this->fetchSettings();
        
        $db = static::connection();
        $table = $this->_table;
        
        $stmt_insert = $db->prepare("INSERT INTO `$table`
            (plugin, name, value, description)
            VALUES (:plugin, :name, :value, :description);");
        
        $stmt_update = $db->prepare("UPDATE `$table` SET
            value = :value,
            description = :description 
            WHERE plugin = :plugin and name = :name;");
        
        // For each setting in this object, check if it exists in DB.  If it does not exist, add.  If it exists and is different from the current value, update.
        foreach ($this->_settings as $plugin => $setting){
            foreach ($setting as $name => $value){
                $sqlVars = [
                    ":plugin" => $plugin,
                    ":name" => $name,
                    ":value" => $value,
                    ":description" => $this->_descriptions[$plugin][$name]
                ];
                if (!isset($db_settings['settings'][$plugin]) || !isset($db_settings['settings'][$plugin][$name])){
                    $stmt_insert->execute($sqlVars);
                } else if (($db_settings['settings'][$plugin][$name] !== $this->_settings[$plugin][$name]) || ($db_settings['descriptions'][$plugin][$name] !== $this->_descriptions[$plugin][$name])){
                    $stmt_update->execute($sqlVars);
                }
            }
        }
        
        return true;   
    }
}