<?php

/**
 * Observer runs upong event: controller_front_send_response_before
 */
class InstaPunchout_Punchout_Model_Observer extends Varien_Event_Observer
{
    public function __construct()
    {
    }

    /**
     * Adds the script tag to include the js file required for punchout to work
     *
     * @param Observer $observer the observer object
     *
     * @return null
     */
    public function addScript($observer)
    {
        $response = $observer->getData('front')->getResponse();
        $html = $response->getBody();
        // get current store
        $store = Mage::app()->getStore();
        // get store base url
        $baseUrl = $store->getBaseUrl();

        $html = str_replace("</head>", '<script src="' . $baseUrl . 'punchout/index/script"></script></head>', $html);
        $response->setBody($html);
    }
}