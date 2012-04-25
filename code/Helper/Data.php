<?php

/**
 * Data helper
 *
 * @category    Aoe
 * @package     Aoe_Static
 * @author      Toni Grigoriu <toni@tonigrigoriu.com>
 * @author      Stephan Hoyer <ste.hoyer@gmail.com>
 */
class Aoe_Static_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Chechs, if varnish is currently active
     *
     * @return boolean
     */
    public function isActive()
    {
        return Mage::app()->useCache('aoestatic');
    }

    /**
     * Check if a fullActionName is configured as cacheable
     *
     * @param string $fullActionName
     * @return false|int false if not cacheable, otherwise lifetime in seconds
     */
    public function isCacheableAction($fullActionName=null)
    {
        if (!$this->isActive()) {
            return false;
        }
        if (is_null($fullActionName)) {
            $fullActionName = $this->getFullActionName();
        }
        $cacheActionsString = Mage::getStoreConfig('system/aoe_static/cache_actions');
        foreach (explode(',', $cacheActionsString) as $singleActionConfiguration) {
            list($actionName, $lifeTime) = explode(';', $singleActionConfiguration);
            if (trim($actionName) == $fullActionName) {
                return intval(trim($lifeTime));
            }
        }
        return false;
    }

    /**
     * Return all block names that are configured to be customer related.
     *
     * @return array
     */
    public function getCustomerBlocks()
    {
        return array_map('trim', explode(',',
            Mage::getStoreConfig('system/aoe_static/customer_blocks'))
        );
    }

    /**
     * Function to determine, if we are in cache context. Returns true, if
     * we are currently building content that will be written to cache.
     *
     * @return boolean
     */
    public function cacheContent()
    {
        if (!$this->isActive()) {
            return false;
        }
        return !$this->isAjaxCallback() and $this->isCacheableAction();
    }

    /**
     * Determines, if we are currenly generating content for ajax callback.
     *
     * @return boolean
     */
    public function isAjaxCallback()
    {
        if (!$this->isActive()) {
            return false;
        }
        return 'phone_call_index' == $this->getFullActionName();
    }

    /**
     * Returns full action name of current request like so:
     * ModuleName_ControllerName_ActionName
     *
     * @return string
     */
    public function getFullActionName()
    {
        return implode('_', array(
            Mage::app()->getRequest()->getModuleName(),
            Mage::app()->getRequest()->getControllerName(),
            Mage::app()->getRequest()->getActionName(),
        ));
    }

    /**
     * Purges complete cache
     *
     * @return array errors if any
     */
    public function purgeAll()
    {
        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
        # TODO Truncate table instead of deleting every url
        $urls = Mage::getModel('aoestatic/url')->getCollection();
        foreach ($urls as $url) {
            $url->delete();
        }
        return $this->purge(array($baseUrl . '.*'));
    }

    /**
     * Purges cache by given tags with given priority in asyncron mode
     * 
     * @param mixed $tags 
     * @param int $priority 
     * @return Aoe_Static_Helper_Data
     */
    public function purgeByTags($tags, $priority=0)
    {
        Mage::getModel('aoestatic/cache')->purgeByTags($tags, $priority);
        return $this;
    }

    /**
     * Purge an array of urls on varnish server.
     *
     * @param array|Collection $urls
     * @return array with all errors
     */
    public function purge($urls)
    {
        $errors = array();
        // Init curl handler
        $curlRequests = array(); // keep references for clean up
        $mh = curl_multi_init();
        $syncronPurge = Mage::getStoreConfig('system/aoe_static/purge_syncroniously');
        $autoRebuild = Mage::getStoreConfig('system/aoe_static/auto_rebuild_cache');

        foreach ($urls as $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, ''.$url);
            if ($syncronPurge || !$autoRebuild) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PURGE');
            } else {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    "Cache-Control: no-cache", 
                    "Pragma: no-cache"
                ));
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            curl_multi_add_handle($mh, $ch);
            $curlRequests[] = array(
                'handler' => $ch,
                'url' => $url
            );
        }

        do {
            $n = curl_multi_exec($mh, $active);
        } while ($active);

        // Error handling and clean up
        foreach ($curlRequests as $request) {
            $ch = $request['handler'];
            $info = curl_getinfo($ch);
            if (curl_errno($ch)) {
                $errors[] = $this->__("Cannot purge url %s due to error: %s", 
                    $info['url'],
                    curl_error($ch)
                );
            } else if ($info['http_code'] != 200 && $info['http_code'] != 404) {
                $msg = 'Cannot purge url %s, http code: %s. curl error: %s';
                $errors[] = $this->__($msg, $info['url'], $info['http_code'],
                    curl_error($ch)
                );
            } else {
                if ($request['url'] instanceof Aoe_Static_Model_Url) {
                    #$request['url']->delete();
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $errors;
    }
}
