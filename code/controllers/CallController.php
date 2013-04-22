<?php

/**
 * CallController
 * Renders the block that are requested via an ajax call
 *
 * @author Fabrizio Branca <fabrizio.branca@aoemedia.de>
 */
class Aoe_Static_CallController extends Mage_Core_Controller_Front_Action
{
    /**
     * Index action. This action is called by an ajax request
     *
     * @return void
     * @author Fabrizio Branca <fabrizio.branca@aoemedia.de>
     */
    public function indexAction()
    {
        // if (!$this->getRequest()->isXmlHttpRequest()) { Mage::throwException('This is not an XmlHttpRequest'); }

        $response = array();
        $response['sid'] = Mage::getModel('core/session')->getEncryptedSessionId();

        if ($currentProductId = $this->getRequest()->getParam('currentProductId')) {
            Mage::getSingleton('catalog/session')->setLastViewedProductId($currentProductId);
        }

        $this->loadLayout();
        $this->_initLayoutMessages('checkout/session');
        $this->_initLayoutMessages('catalog/session');
        $this->_initLayoutMessages('customer/session');
        $this->_initLayoutMessages('core/session');
        $layout = $this->getLayout();

        $requestedBlockNames = $this->getRequest()->getParam('getBlocks');
        if (is_array($requestedBlockNames)) {
            $requestedBlockNames = array_unique($requestedBlockNames);
            foreach ($requestedBlockNames as $id => $requestedBlockName) {
                $tmpBlock = $layout->getBlock($requestedBlockName);
                if ($tmpBlock) {
                    if($requestedBlockName == 'messages'){
                        $response['blocks'][$id] = $layout->getMessagesBlock()->getGroupedHtml();
                    }elseif($requestedBlockName == 'global_messages'){
                        $response['blocks'][$id] = $tmpBlock->getGroupedHtml();
                    }else{
                        $response['blocks'][$id] = $tmpBlock->toHtml();
                    }
                } else {
                    $response['blocks'][$id] = '<!--BLOCK NOT FOUND-->';
                }
            }
        }
        $this->getResponse()->setBody(Zend_Json::encode($response));
    }
}
