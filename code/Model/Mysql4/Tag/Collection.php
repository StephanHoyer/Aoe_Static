<?php

class Aoe_Static_Model_Mysql4_Tag_Collection extends Mage_Core_Model_Mysql4_Collection_Abstract
{
    protected function _construct()
    {
            $this->_init('aoestatic/tag');
    }
}