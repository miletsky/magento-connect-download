<?php
/**
 * Get download link of a Magento extension from MagentoConnect key
 *
 * Dependencies: Zend_Http_Client, Zend_Uri (Zend Framework)
 */

class Narno_Mage_Extension
{
    protected $_key         = null;
    protected $_parsedKey   = null;
    protected $_keyVersion  = null;
    protected $_pool        = null;
    protected $_name        = null;
    protected $_stability   = null;
    protected $_releases    = null;
    protected $_version     = null;
    protected $_lastVersion = null;

    const KEY_V1 = '1.0';
    const KEY_V2 = '2.0';

    function __construct($key)
    {
        $this->setKey($key);
        $this->setStability('none'); // "none", "stable" or "beta"
    }

    public function setKey($key)
    {
        if (empty($key)) {
            throw new Exception('Key is empty.');
        }

        $this->_key = $key;
    }

    public function getKey()
    {
        if ($this->_key === null) {
            throw new Exception('Key is not defined.');
        }

        return $this->_key;
    }

    public function setStability($stability)
    {
        if ($stability != 'beta' && $stability != 'stable' && $stability != 'none') {
            $this->_stability = null;
        }
        else {
            $this->_stability = $stability;
        }
    }

    public function getStability()
    {
        if ($this->_stability === null) {
            throw new Exception('Stability is not defined');
        }

        return $this->_stability;
    }

    public function parseKey()
    {
        if ($this->_parsedKey === null) {
            $keyVersion = null;
            $pool       = null;
            $version    = null;

            $key = $this->getKey();

            /**
             * MagentoConnect key version
             */
            // http://connect20.magentocommerce.com/community/Interface_Frontend_Default_Modern
            if (strstr($key, 'connect20') !== false) {
                $keyVersion = self::KEY_V2;
            }
            // magento-core/Interface_Frontend_Default_Modern
            elseif (strstr($key, 'magento-') !== false) {
                $keyVersion = self::KEY_V1;
            }
            else {
                throw new Exception('Key version can not be determined.');
            }
            /**
             * Extension pool
             */
            switch ($keyVersion) {
                case self::KEY_V2:
                    $uri = Zend_Uri::factory($key);
                    $path = $uri->getPath();
                    $pathAsArray = explode('/', $path);
                    if (array_key_exists(1, $pathAsArray)) {
                        $pool = $pathAsArray[1];
                    }
                    break;
                case self::KEY_V1:
                    $pathAsArray = explode('/', $key);
                    $pool = reset($pathAsArray);
                    $pool = strstr($pool, '-');
                    $pool = substr($pool, 1);
                    break;
            }
            if (empty($pool)) {
                throw new Exception('Extension pool can not be determined.');
            }
            /**
             * Extension version and name
             */
            $extension = end($pathAsArray);
            if (strstr($extension, '-') !== false) {
                $version = substr(strstr($extension, '-'), 1);
            }
            if (strstr($extension, '-', true) !== false) {
                $name = strstr($extension, '-', true);
            }
            else {
                $name = $extension;
            }
            if (empty($name)) {
                throw new Exception('Extension name can not be determined.');
            }

            $this->_parsedKey = array(
                'key' => array(
                    'value'   => $key,
                    'version' => $keyVersion,
                ),
                'extension' => array(
                    'name'    => $name,
                    'pool'    => $pool,
                    'version' => $version,
                ),
            );
        }

        return $this->_parsedKey;
    }

    public function getKeyVersion()
    {
        if ($this->_keyVersion === null) {
            $parsedKey = $this->parseKey();
            $this->_keyVersion = $parsedKey['key']['version'];
            if ($this->_keyVersion === null) {
                throw new Exception('Extension key version is null.');
            }
        }

        return $this->_keyVersion;
    }

    public function getName()
    {
        if ($this->_name === null) {
            $parsedKey = $this->parseKey();
            $this->_name = $parsedKey['extension']['name'];
            if ($this->_name === null) {
                throw new Exception('Extension name is null.');
            }
        }

        return $this->_name;
    }

    public function getPool()
    {
        if ($this->_pool === null) {
            $parsedKey = $this->parseKey();
            $this->_pool = $parsedKey['extension']['pool'];
            if ($this->_pool === null) {
                throw new Exception('Extension pool is null.');
            }
        }

        return $this->_pool;
	}

    public function getVersion()
    {
        if ($this->_version === null) {
            $parsedKey = $this->parseKey();
            $version = $parsedKey['extension']['version'];
            if ($version !== null) {
                $this->_version = $version;
            }
            else {
                $this->_version = $this->getLastVersion();
            }
            if (empty($this->_version)) {
                throw new Exception('Extension version is empty.');
            }
        }

        return $this->_version;
    }

    public function getDownloadUrl()
    {
        switch ($this->getKeyVersion()) {
            // pattern: http://connect20.magentocommerce.com/{pool}/{name}/{version}/{name}-{version}.tgz
            case self::KEY_V2:
                $url = $this->_getKeyForDl() . '/' . $this->getVersion() . '/' . $this->getName() . '-' . $this->getVersion() . '.tgz';
                break;
            // pattern: http://connect.magentocommerce.com/{pool}/get/{name}-{version}.tgz
            case self::KEY_V1:
                $url = 'http://connect.magentocommerce.com/' . $this->getPool() . '/get/' . $this->getName() . '-' . $this->getVersion() . '.tgz';
                break;
        }

        return $url;
    }

    public function getFilename()
    {
        // pattern: {name}-{version}.tgz
        return $this->getName() . '-' . $this->getVersion() . '.tgz';
    }

    public function getReleases()
    {
        if ($this->_releases === null) {
            // XML to array
            $xml = $this->_getReleasesAsXml();
            foreach ($xml->r as $r => $rv) {
                $array["$rv->v"] = "$rv->s";
            }
            $this->_releases = $array;
        }

        return $this->_releases;
    }

    public function getLastVersion()
    {
        switch ($this->getStability()) {
            case 'beta':
                $callback = "_isBeta";
                break;
            case 'stable':
                $callback = "_isStable";
                break;
            default:
                $callback = "none";
        }
        if ($this->_lastVersion === null) {
            // no filter on stability
            if ($callback == "none") {
                $array = $this->getReleases();
            }
            // filter on stability, based on simple custom functions
            else {
                $array = array_filter($this->getReleases(), array($this, $callback));
            }
            $array = array_keys($array); // get version number
            $this->_lastVersion = max($array); // last version = higher value

            // could be empty if there isstatbility filter is used
            if ($this->_lastVersion == '') {
                throw new Exception('There is no ' . $this->getStability() . ' version.');
            }
        }

        return $this->_lastVersion;
    }

    protected function _getReleasesAsXml()
    {
        require_once 'Zend/Http/Client.php';
        $client = new Zend_Http_Client();

        switch ($this->getKeyVersion()) {
            // pattern: http://connect20.magentocommerce.com/{pool}/{name}/releases.xml
            case self::KEY_V2:
                $client->setUri($this->getKey() . '/releases.xml');
                break;
            // pattern: http://connect.magentocommerce.com/{pool}/Chiara_PEAR_Server_REST/r/{name.lowercase}/allreleases.xml
            case self::KEY_V1:
                $client->setUri('http://connect.magentocommerce.com/'
                	. $this->getPool()
                	. '/Chiara_PEAR_Server_REST/r/'
                	. strtolower($this->getName())
                	. '/allreleases.xml'
            	);
                break;
        }

        $response = $client->request('GET');
        if ($response->getStatus() !== 200) {
            throw new Exception('Failed to load, got response code ' . $response->getStatus());
        }

        return simplexml_load_string($response->getBody());
    }

    protected function _isBeta($var)
    {
        return ($var === 'beta') ? true : false;
    }

    protected function _isStable($var)
    {
        return ($var === 'stable') ? true : false;
    }

    protected function _getKeyForDl()
    {
        $originKey = $this->getKey();

        $version = strstr($originKey, '-');
        // sub version if present in key
        if ($version !== false) {
            $key = strstr($originKey, '-', true);
        }
        else {
            $key = $originKey;
        }

        return $key;
    }
}
