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
            if (trim($singleActionConfiguration)) {
                $config = explode(';', $singleActionConfiguration);
                list($actionName, $lifeTime) = sizeof($config) >= 2
                    ? $config
                    : array($config[0], 86400);
                if (trim($actionName) == $fullActionName) {
                    return intval(trim($lifeTime));
                }
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
        $blocks = explode(',',
                Mage::getStoreConfig('system/aoe_static/customer_blocks'));
        $customerBlocks = array();
        foreach($blocks as $block) {
            $block = explode(';', $block);
            $customerBlocks[trim($block[0])] = sizeof($block) > 1
                ? trim($block[1]) : '';
        }
        return array_filter($customerBlocks);
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
        return $this->isCacheableAction();
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
        $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
        foreach (array('url', 'tag', 'urltag') as $table) {
            $resource = Mage::getResourceModel('aoestatic/' . $table);
            $conn->query(sprintf('DELETE FROM %s;', $resource->getMainTable()));
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
        $purgeHosts = Mage::getStoreConfig('system/aoe_static/purge_hosts');

        $purgeHosts = array_filter(array_map('trim', explode('\n', $purgeHosts)));

        $purgeHosts = $purgeHosts ? $purgeHosts : array(Mage::getBaseUrl());

        foreach ($purgeHosts as $purgeHost) {
          foreach ($urls as $url) {
              $components = parse_url('' . $url);
              $ch = curl_init();
              $this->log('Purge url: ' . $url);
              $options = array(
                  CURLOPT_URL => $url,
                  CURLOPT_HTTPHEADER => array('Host: ' . $components['host'])
              );

              if ($syncronPurge || !$autoRebuild) {
                  $options[CURLOPT_CUSTOMREQUEST] = 'PURGE';
              } else {
                  $options[CURLOPT_HTTPHEADER][] = "Cache-Control: no-cache";
                  $options[CURLOPT_HTTPHEADER][] = "Pragma: no-cache";
              }
              $options[CURLOPT_RETURNTRANSFER] = 1;
              $options[CURLOPT_SSL_VERIFYPEER] = 0;
              $options[CURLOPT_SSL_VERIFYHOST] = 0;
              curl_setopt_array($ch, $options);

              curl_multi_add_handle($mh, $ch);
              $curlRequests[] = array(
                  'handler' => $ch,
                  'url' => $url
              );
              $this->log('Info about curlRequests', compact('options'));
          }
        }

        do {
            $n = curl_multi_exec($mh, $active);
        } while ($active);

        $this->log('cUrl multi handle info', curl_multi_info_read($mh));

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
                    $request['url']->delete();
                }
            }
            $this->log( 'cUrl info', $info );
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if (count($errors) > 0)
        {
          $this->log('Errors occured while purging.', compact('urls', 'errors'));
        }

        return $errors;
    }

    protected function log( $message, $params = null )
    {
      if (!is_null($params))
      {
        $message = print_r(compact('message', 'params'), 1);
      }
      Mage::log($message, null, 'aoestatic.' . date('Y-m-d') . '.log');
    }
}
