<?php
class Aoe_Static_Model_Url extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('aoestatic/url');
    }

    public function __toString()
    {
        return $this->getUrl();
    }

    public function getHelper()
    {
        return Mage::helper('aoestatic');
    }

    /**
     * Finds url given by path, if not in db, creates it
     *
     * @param String $path
     * @return Aoe_Static_Model_Url
     */
    public function loadOrCreateUrl($path)
    {
        $url = Mage::getModel('aoestatic/url')
            ->getCollection()
            ->addFieldToFilter('url', $path)
            ->getFirstItem();
        if (!$url->getId()) {
            $url->setUrl($path);
        } else {
            $url->setPurgePrio(null);
        }

        $lifetime = $this->getHelper()->isCacheableAction();
        $expire = date(
            'Y-m-d H:i:s', 
            strtotime(sprintf('+ %d seconds', $lifetime))
        );
        $url->setExpire($expire);
        $url->save();
        return $url;
    }

    /**
     * Replaces tags of this url with given tags
     *
     * @param Aoe_Static_Model_Mysql4_Tag_Collection $tags
     * @return Aoe_Static_Model_Url
     */
    public function setTags($tags)
    {
        $this->deleteExistingTags();

        foreach ($tags as $tag) {
            $urlTag = Mage::getModel('aoestatic/urltag')
                ->setUrlId($this->getId())
                ->setTagId($tag->getId());
            try {
                $urlTag->save();
            } catch (Exception $e) {
                // ignore duplicate url tag combination
            }
        }
        return $this;
    }

    /**
     * Deletes all existing tags for this url
     *
     * @return Aoe_Static_Model_Url
     */
    public function deleteExistingTags()
    {
        $collection = Mage::getModel('aoestatic/urltag')->getCollection()
            ->addFieldToFilter('url_id', $this->getId());
        // the followng should work according docs but it doesn't
        // $collection->delete();
        foreach($collection as $tag) {
            $tag->delete();
        }
        return $this;
    }

    /**
     * Fetches url-collection with urls that are tagged
     * with at least on of the given tags
     *
     * @param String|Array $tags
     * @return Aoe_Static_Model_Mysql4_Url_Collection
     */
    public function getUrlsByTagStrings($tags)
    {
        if (empty($tags)) {
            return array();
        }
        $resource = Mage::getSingleton('core/resource');
        $urls = Mage::getModel('aoestatic/url')->getCollection()
            ->addFieldToFilter('tag.tag', $tags);
        $urls->getSelect()
            ->join(
                array('urltag'=>$resource->getTableName('aoestatic/urltag')),
                'main_table.url_id = urltag.url_id'
            )
            ->join(
                array('tag'=>$resource->getTableName('aoestatic/tag')),
                'urltag.tag_id = tag.tag_id'
            )
            ->group('main_table.url_id');
        return $urls;
    }

    /**
     * Get expired urls
     *
     * @return Aoe_Static_Model_Resource_Url_Collection
     */
    public function getExpiredUrls()
    {
        return Mage::getResourceModel('aoestatic/url_collection')
            ->addFieldToFilter('expire', array('lteq' => date("Y-m-d H:i:s")));
    }

    /**
     * Get urls to purge ordered by prio
     *
     * @return Aoe_Static_Model_Resource_Url_Collection
     */
    public function getUrlsToPurgeByPrio()
    {
        return Mage::getResourceModel('aoestatic/url_collection')
            ->addFieldToFilter('purge_prio', array('notnull' => true))
            ->setOrder('purge_prio', 'DESC');
    }
}

