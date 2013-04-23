<?php
/**
 * Observer model
 *
 * @category    Aoe
 * @package     Aoe_Static
 * @author      Stephan Hoyer <ste.hoyer@gmail.com>
 * @see         https://github.com/madalinoprea/magneto-varnish
 */
class Aoe_Static_Model_Cache
{
    var $doneTags = array();
    var $msgSent = false;
    var $tags = array();
    var $staticBlocks = array();
    var $isCacheableAction = true;

    /**
     * Collect all layout tags that where used generating the content
     *
     * @param $observer Mage_Core_Model_Observer
     * @return Aoe_Static_Model_Cache
     **/
    public function collectTags($observer)
    {
        $block = $observer->getBlock();
        $tags = $block->getCacheTags();
        if ($block instanceof Mage_Cms_Block_Block) {
            // special handling for static blocks: we cant get the real
            // id here but only the block alias, so we have to fetch it
            // later on in fetchTagsForStaticBlocks method
            $this->staticBlocks[] = $block->getBlockId();
        } else if ($block instanceof Mage_Cms_Block_Page) {
            $tags[] = 'cms_page_' . $block->getPage()->getPageId();
        } else if ($block instanceof Mage_Catalog_Block_Product_View) {
            $tags[] = 'catalog_product_' . $block->getProduct()->getId();
        } else if ($block instanceof Mage_Catalog_Block_Product_List) {
            $productCollection = $block->getLoadedProductCollection();
            foreach($productCollection as $product) {
                $tags[] = 'catalog_product_' . $product->getId();
            }
        } else if ($block instanceof Mage_Catalog_Block_Category_View) {
            $tags[] = 'catalog_category_' . $block->getCurrentCategory()->getId();
        }
        $this->tags = array_merge($this->tags, $tags);
    }

    protected function fetchTagsForStaticBlocks($staticBlocks)
    {
        if (empty($staticBlocks)) {
            return array();
        }
        $tags = array();
        $blocks = Mage::getModel('cms/block')->getCollection()
            ->addFieldToFilter('identifier', $staticBlocks);
        foreach ($blocks as $block) {
            $tags[] = 'cms_block_' . $block->getId();
        }
        return $tags;
    }

    /**
     * Saves tags off current url to database.
     *
     * @param mixed $observer
     * @return Aoe_Static_Model_Cache
     */
    public function saveTags($observer)
    {
        //cache check if cachable to improve performance
        $this->isCacheableAction = $this->isCacheableAction
            && $this->getHelper()->isCacheableAction();
        if ($this->isCacheableAction) {
            $this->tags = array_merge(
                $this->fetchTagsForStaticBlocks($this->staticBlocks),
                $this->tags
            );
            $tags = Mage::getModel('aoestatic/tag')
                ->loadTagsCollection(array_unique($this->tags));
            $currentUrl = Mage::helper('core/url')->getCurrentUrl();
            $url = Mage::getModel('aoestatic/url')
                ->loadOrCreateUrl($currentUrl);
            $url->setTags($tags);
        }
        return $this;
    }

    public function getHelper()
    {
        return Mage::helper('aoestatic');
    }

    /**
     * Sets purge flag for each url
     *
     * @param array $urls
     * @param int $priority
     * @return Aoe_Static_Model_Cache
     */
    public function markToPurge($urls=array(), $priority=0)
    {
        $count = count($urls);
        if ($count == 0) {
            return;
        }
        foreach ($urls as $url) {
            if (is_null($url->getPurgePrio()) || $priority > $url->getPurgePrio()) {
                $url->setPurgePrio($priority)->save();
            }
        }
        $helper = $this->getHelper();
        $msg = 1 == $count
            ? $helper->__("1 site has been marked to be purged.")
            : $helper->__("%s sites have been marked to be purged.", $count);
        if (!$this->msgSent) {
            Mage::getSingleton('adminhtml/session')->addSuccess($msg);
            $this->msgSent = true;
        }
        return $this;
    }

    /**
     * Listens to application_clean_cache event and gets notified when a product/category/cms
     * model is saved.
     *
     * @param $observer Mage_Core_Model_Observer
     * @return Aoe_Static_Model_Cache
     */
    public function purgeCache($observer)
    {
        $helper = $this->getHelper();
        // If Varnish is not enabled on admin don't do anything
        if (!$helper->isActive()) {
            return;
        }

        $tags = $observer->getTags();
        $tags = is_array($tags) ? $tags : array($tags);

        if ($tags == array()) {
            $errors = $helper->purgeAll();
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError("Varnish Purge failed");
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess("The Varnish cache storage has been flushed.");
            }
            return;
        }
        //Only purge cache once per request
        $tags = array_diff($tags, $this->doneTags);
        $this->purgeByTags($tags);
        $this->doneTags = array_merge($this->doneTags, $tags);
    }

    /**
     * Purges cache by tags
     *
     * @param Array\String $tags
     * @return Aoe_Static_Model_Cache
     */
    public function purgeByTags($tags=array(), $priority=0)
    {
        $tags = is_string($tags) ? array($tags) : $tags;
        if(empty($tags)){
            return;
        }
        // these tags get refreshed on every refresh of one product, category,
        // block or page so that all products/categries/pages/blocks get purged.
        // This is not expected behaviour and should be prevented.
        $neverPurgeTheseTags = array('cms_block', 'cms_page', 'catalog_product',
            'catalog_category');
        $tags = array_diff($tags, $neverPurgeTheseTags);
        $urls = Mage::getModel('aoestatic/url')->getUrlsByTagStrings($tags);
        $this->purge($urls, $priority);
    }

    /**
     * Wrapper to purge cache synconiously or async depending on configuration
     *
     * @param mixed $urls
     * @param int $priority
     * @return void
     */
    public function purge($urls, $priority=0)
    {
        $purgeSynconiously = Mage::getStoreConfig('system/aoe_static/purge_syncroniously');
        if ($purgeSynconiously) {
            $this->syncronPurge($urls);
        } else {
            $this->markToPurge($urls, $priority);
        }
        return $this;
    }

    /**
     * Trigger purge of all urls directly at varnish instance
     *
     * @param Array|Collection $urls
     * @return Aoe_Static_Model_Cache
     */
    public function syncronPurge($urls)
    {
        $helper = $this->getHelper();
        $count = is_object($urls) ? $urls->count() : count($urls);
        if ($count) {
            $errors = $this->getHelper()->purge($urls);
            if (!empty($errors)) {
                $msg = $helper->__(
                    "Some Varnish purges failed: %s",
                    self::getListHtml($errors)
                );
                Mage::getSingleton('adminhtml/session')->addError($msg);
            } else {
                $msg = $helper->__( "%s sites have been purged.", $count);
                Mage::getSingleton('adminhtml/session')->addSuccess($msg);
            }
        }
        return $this;
    }

    /**
     * generates ul-html list of given array
     * 
     * @param Array(string) $list 
     * @return string
     */
    protected static function getListHtml($list)
    {
        return '<ul><li>' . implode('</li><li>', $list) . '</li></ul>';
    }

    /**
     * This method is used by the cron to clean up expired url entries
     *
     * @return void
     */
    public function removeExpiredUrls()
    {
        $urls = Mage::getModel('aoestatic/url')->getExpiredUrls();
        foreach ($urls as $url) {
            $url->deleteExistingTags();
            $url->delete();
        }
    }
    /**
     * This method is used by the cron to trigger purges by priority
     *
     * @return void
     */
    public function processPurge()
    {
        $urls = Mage::getModel('aoestatic/url')->getUrlsToPurgeByPrio();
        $pageSize = Mage::getStoreConfig('system/aoe_static/page_size');
        $pageCount = Mage::getStoreConfig('system/aoe_static/page_count');
        $urlsToPurge = array();
        foreach ($urls as $url) {
            if (count($urlsToPurge) < $pageSize) {
                $urlsToPurge[] = $url;
            } else {
                $this->syncronPurge($urlsToPurge);
                $urlsToPurge = array();
                $pageCount--;
            }
            if ($pageCount == 0) {
                break;
            }
        }
        $this->syncronPurge($urlsToPurge);
    }
    /**
     * Returns all the urls related to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    protected function _getUrlsForProduct($product)
    {
        $urls = array();

        // Collect all rewrites
        $rewrites = Mage::getModel('core/url_rewrite')->getCollection();
        if (!Mage::getConfig('catalog/seo/product_use_categories')) {
            $rewrites->getSelect()
                ->where("id_path = 'product/{$product->getId()}'");
        } else {
            // Also show full links with categories
            $rewrites->getSelect()
                ->where("id_path = 'product/{$product->getId()}' OR id_path like 'product/{$product->getId()}/%'");
        }
        foreach($rewrites as $r) {
            unset($routeParams);
            $routePath = '';
            $routeParams['_direct'] = $r->getRequestPath();
            $routeParams['_store'] = $r->getStoreId();
            $url = Mage::getUrl($routePath, $routeParams);
            $urls[] = $url;
        }
        return $urls;
    }

    /**
     * Returns all the urls pointing to the category
     *
     * @param $category
     * @return array
     */
    protected function _getUrlsForCategory($category)
    {
        $urls = array();
        // Collect all rewrites
        $rewrites = Mage::getModel('core/url_rewrite')->getCollection();
        $rewrites->getSelect()->where("id_path = 'category/{$category->getId()}'");
        foreach($rewrites as $r) {
            $routeParams['_direct'] = $r->getRequestPath();
            $routeParams['_store'] = $r->getStoreId();
            $routeParams['_nosid'] = True;
            $url = Mage::getUrl('', $routeParams);
            $urls[] = $url;
        }
        return $urls;
    }

    /**
     * Returns all urls related to this cms page
     *
     * @param string $cmsPageId
     * @return array
     */
    protected function _getUrlsForCmsPage($cmsPageId)
    {
        $urls = array();
        $page = Mage::getModel('cms/page')->load($cmsPageId);
        foreach ($page->getStoreId() as $store) {
            $store = Mage::app()->getStore($store);
            $baseUrl = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
            if ($page->getId()) {
                $urls[] = $baseUrl . $page->getIdentifier();
            }
            //Current page is homepage?
            $homePageId = Mage::getStoreConfig('web/default/cms_home_page');
            if ($homePageId == $page->getIdentifier()) {
                $urls[] = $baseUrl;
            }
        }
        return $urls;
    }
}
