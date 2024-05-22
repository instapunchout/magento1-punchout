<?php
class InstaPunchout_Punchout_IndexController extends Mage_Core_Controller_Front_Action
{


    private function getAllowedCountries()
    {
        $countries = Mage::getModel('directory/country')->getResourceCollection()->loadByStore()->toOptionArray(true);
        $allowedCountries = array();
        foreach ($countries as $country) {
            $allowedCountries[] = $country['value'];
        }
        return $allowedCountries;
    }

    private function getOptions()
    {
        // get websites
        $websites = Mage::app()->getWebsites();
        $websiteOptions = array();
        foreach ($websites as $website) {
            $websiteOptions[] = array(
                'value' => $website->getId(),
                'label' => $website->getName()
            );
        }
        // get stores
        $stores = Mage::app()->getStores();
        $storeOptions = array();
        foreach ($stores as $store) {
            $storeOptions[] = array(
                'value' => $store->getId(),
                'label' => $store->getName(),
                'base_url' => $store->getBaseUrl()
            );
        }
        // get customer groups
        $customerGroups = Mage::getModel('customer/group')->getCollection();
        $customerGroupOptions = array();
        foreach ($customerGroups as $customerGroup) {
            $customerGroupOptions[] = array(
                'value' => $customerGroup->getId(),
                'label' => $customerGroup->getCustomerGroupCode()
            );
        }
        // get roles
        $roleOptions = array();
        $roles_model = Mage::getModel('equip_customer/role');
        if ($roles_model) {
            $roles = Mage::getModel('equip_customer/role')->getCollection();
            foreach ($roles as $role) {
                $roleOptions[] = array(
                    'value' => $role->getId(),
                    'label' => $role->getName(),
                    'website_id' => $role->getWebsiteId(),
                    'description' => $role->getDescription()
                );
            }
        }

        // get payment methods
        $paymentMethods = Mage::getSingleton('payment/config')->getActiveMethods();
        $paymentMethodOptions = array();
        foreach ($paymentMethods as $paymentCode => $paymentModel) {
            $paymentTitle = Mage::getStoreConfig('payment/' . $paymentCode . '/title');
            $paymentMethodOptions[] = array(
                'value' => $paymentCode,
                'label' => $paymentTitle
            );
        }
        // get shipping methods
        $shippingMethods = Mage::getSingleton('shipping/config')->getActiveCarriers();
        $shippingMethodOptions = array();
        foreach ($shippingMethods as $shippingCode => $shippingModel) {
            $shippingTitle = Mage::getStoreConfig('carriers/' . $shippingCode . '/title');
            $shippingMethodOptions[] = array(
                'value' => $shippingCode,
                'label' => $shippingTitle
            );
        }
        // get allowed countries
        $allowedCountries = $this->getAllowedCountries();
        // return all
        $res = array(
            'websites' => $websiteOptions,
            'stores' => $storeOptions,
            'groups' => $customerGroupOptions,
            'roles' => $roleOptions,
            'payment_methods' => $paymentMethodOptions,
            'shipping_methods' => $shippingMethodOptions,
            'allowed_countries' => $allowedCountries
        );

        if (isset($_GET['product_attribute'])) {
            $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $_GET['product_attribute']);
            if ($attribute->usesSource()) {
                $options = $attribute->getSource()->getAllOptions(false);
                $res[$_GET['product_attribute']] = $options;
            }

        }
        return $res;
    }

    private function updateCustomer($customer, $res)
    {
        $updated = false;
        if (isset($res['store_id'])) {
            $customer->setStoreId($res['store_id']);
            $updated = false;
        }

        if (isset($res['group_id'])) {
            $customer->setGroupId($res['group_id']);
            $updated = true;
        }

        if (isset($res['website_id'])) {
            $customer->setWebsiteId($res['website_id']);
            $updated = true;
        }

        return $updated;
    }

    public function cartJsonAction()
    {
        // Make sure the content type for this response is JSON
        $this->getResponse()->clearHeaders()
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setHeader(
                'Content-type',
                'application/javascript;charset=UTF-8'
            );

        // Set the response body / contents to be the JSON data
        $this->getResponse()->setBody(json_encode($this->getCart()));
    }



    public function scriptAction()
    {
        $session = Mage::getSingleton('customer/session');
        $punchout_id = $session->getPunchoutId();
        if (empty($punchout_id)) {
            $response = "console.log('Punchout ID not found');";
        } else {
            $response = $this->get('https://punchout.cloud/punchout.js?id=' . $punchout_id);
        }

        // Make sure the content type for this response is JSON
        $this->getResponse()->clearHeaders()
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setHeader(
                'Content-type',
                'application/javascript;charset=UTF-8'
            );

        // Set the response body / contents to be the JSON data
        $this->getResponse()->setBody($response);
    }

    public function cartAction()
    {
        try {
            $session = Mage::getSingleton('customer/session');
            $punchout_id = $session->getPunchoutId();
            if (isset($punchout_id)) {
                $cart = $this->getCart();
                $data = [
                    'cart' => [
                        'Magento1' => $cart,
                    ]
                ];
                $response = $this->post('https://punchout.cloud/cart/' . $punchout_id, $data);
            } else {
                $response = ['message' => "You're not in a punchout session"];
            }

            // Make sure the content type for this response is JSON
            $this->getResponse()->clearHeaders()->setHeader(
                'Content-type',
                'application/json'
            );

            // Set the response body / contents to be the JSON data
            $this->getResponse()->setBody(
                json_encode($response)
            );
        } catch (Exception $e) {
            die($e->getMessage());
        }
    }

    private function getCart()
    {
        $cart = Mage::getSingleton('checkout/cart');
        $cartItems = $cart->getItems();
        foreach ($cartItems as $item) {
            $product = Mage::getModel('catalog/product')->load($item->product_id);
            $item['product_data'] = $product->getData();
            $item['buy'] = json_decode(Mage::helper('core')->jsonEncode($item->getBuyRequest()));
            $options = Mage::helper('catalog/product_configuration')->getCustomOptions($item);
            $item['options'] = $options;
            $staff_model = Mage::getModel('staff/staff');
            if ($staff_model) {
                $staff = $staff_model->load((int) $item['staff_id']);
                $item['staff'] = json_decode(Mage::helper('core')->jsonEncode($staff), true);
            }
        }
        $data = json_decode(Mage::helper('core')->jsonEncode($cartItems), true);
        $data['currency'] = Mage::app()->getStore()->getCurrentCurrencyCode();
        return $data;
    }
    private function prepareCustomer($res)
    {
        // get magento 2 customer by email
        $email = $res['email'];
        $websiteId = Mage::app()->getWebsite()->getId();
        if (isset($res['website_id'])) {
            $websiteId = $res['website_id'];
        }

        $storeId = Mage::app()->getStore()->getId();
        if (isset($res['store_id'])) {
            if ($res['store_id'] != $storeId) {
                Mage::app()->setCurrentStore($storeId);
            }
            $storeId = $res['store_id'];
        }

        $updated = false;
        // Instance of customer loaded by the given email
        $customer = Mage::getModel('customer/customer')
            ->setWebsiteId($websiteId)
            ->loadByEmail($email);
        if (!$customer->getId()) {
            // create customer 
            $customer = Mage::getModel("customer/customer");
            $customer->setEmail($email)
                ->setWebsiteId($websiteId)
                ->setStoreId($storeId)
                ->setFirstname($res['firstname'])
                ->setLastname($res['lastname'])
                ->setPassword($res['password']);
            $customer->save();
            $customer = Mage::getModel('customer/customer')
                ->setWebsiteId($websiteId)
                ->loadByEmail($email);
        }

        if (isset($res['store_id'])) {
            $customer->setStoreId($res['store_id']);
            $updated = true;
        }

        if (isset($res['group_id'])) {
            $customer->setGroupId($res['group_id']);
            $updated = true;
        }
        ;
        if (isset($res['website_id'])) {
            $customer->setWebsiteId($res['website_id']);
            $updated = true;
        }
        ;

        if (isset($res['account_id'])) {
            $accountId = $res['account_id'];
            $parentCustomer = Mage::getModel('customer/customer')
                ->getCollection()
                ->addAttributeToSelect('*')
                ->addFieldToFilter('erp_account_id', $accountId)
                ->getFirstItem();
            if (!$parentCustomer->getId()) {
                die("Parent customer not found with erp_account_id " . $accountId);
            }
            $customer->setData('parent_customer', $accountId);
            $customer->setData('parent_erp_account_id', $accountId);
            $customer->setData('parent_customer_id', $parentCustomer->getEntityId());
            $updated = true;
        }

        if ($res['properties']['roles']) {
            $customer->setRoles($res['properties']['roles']);
            $updated = true;
        }

        if ($updated) {
            $customer->save();
        }

        return $customer;
    }


    private function clearCart()
    {
        $cart = Mage::getSingleton('checkout/cart');
        $cart->truncate()->save();
    }

    public function indexAction()
    {
        try {
            $request = $this->getRequest();
            $session = Mage::getSingleton('customer/session');

            $action = $request->getParam('action');

            if ($action == 'options.json') {
                $this->authorize();
                $response = $this->getOptions();
            } else if ($action == 'script') {
                $this->scriptAction();
                exit;
            } else if ($action == 'order.json') {
                $this->authorize();
                $this->orderAction();
                exit;
            } else if ($action == 'invoices.json') {
                $this->authorize();
                $response = $this->getInvoices();
            } else {

                // no need for further sanization as we need to capture all the server data as is
                $server = json_decode(json_encode($_SERVER), true);
                $query = json_decode(json_encode($_GET), true);

                $res = $this->post('https://punchout.cloud/proxy', [
                    'headers' => getallheaders(),
                    'server' => $server,
                    'query' => $query,
                    'body' => file_get_contents('php://input'),
                ]);

                if ($res['action'] == 'login') {

                    $customer = $this->prepareCustomer($res);
                    $session = Mage::getSingleton('customer/session');
                    $session->loginById($customer->getId());
                    $session->setPunchoutId($res['punchout_id']);

                    $this->clearCart();

                    return $this->_redirect('/');
                } else {
                    $response = ["error" => "unknown action", "response" => $res];
                }
            }
        } catch (Exception $e) {
            $response = ["error" => $e->getMessage()];
        }

        // Make sure the content type for this response is JSON
        $this->getResponse()->clearHeaders()->setHeader(
            'Content-type',
            'application/json'
        )->setHeader('X-XSS-Protection', '0')
            ->setHeader('Cross-Origin-Opener-Policy', 'unsafe-none')
            ->setHeader('Referrer-Policy', 'no-referrer');

        // Set the response body / contents to be the JSON data
        $this->getResponse()->setBody(
            Mage::helper('core')->jsonEncode($response)
        );
    }


    private function post($url, $data = null)
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($handle, CURLOPT_POST, true);
        if (isset($data)) {
            $data = json_encode($data);
        }
        curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
        $response = json_decode(curl_exec($handle), true);
        curl_close($handle);
        return $response;
    }

    public function get($url)
    {
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($handle);
        curl_close($handle);
        return $response;
    }


    public function orderAction()
    {

        $body = $this->getRequest()->getRawBody();
        $data = json_decode($body, true);

        $store = Mage::app()->getStore();
        $website = Mage::app()->getWebsite();

        /**
         * You need to enable this method from Magento admin
         * Other methods: tablerate_tablerate, freeshipping_freeshipping, etc.
         */
        $shippingMethod = $data['shipping_method']; // 'flatrate_flatrate';

        /**
         * You need to enable this method from Magento admin
         * Other methods: checkmo, free, banktransfer, ccsave, purchaseorder, etc.
         */
        $paymentMethod = $data['payment_method']; // 'cashondelivery';


        // Initialize sales quote object
        $quote = Mage::getModel('sales/quote')
            ->setStoreId($store->getId());

        // Set currency for the quote
        $quote->setCurrency(Mage::app()->getStore()->getBaseCurrencyCode());

        $customer = $this->prepareCustomer($data);

        // Assign customer to quote
        $quote->assignCustomer($customer);


        // Add products to quote
        foreach ($data['items'] as $item) {

            $product;
            if (isset($item['product'])) {
                $product = Mage::getModel('catalog/product')->load($item['product']);
            } else if (isset($item['sku'])) {
                $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $item['sku']);
                if (!isset($product)) {
                    Mage::app()->getResponse()
                        ->setBody(Mage::helper('core')->jsonEncode(['error' => 'Coudnt find product with sku ' . $item['sku']]));
                    return;
                }
            } else {
                Mage::app()->getResponse()
                    ->setBody(Mage::helper('core')->jsonEncode(['error' => 'Required field product or sku']));
                return;
            }

            // custom: get staff id for equipsolution
            $staff_id = false;
            if (isset($item['staff_id'])) {
                $staff_id = $item['staff_id'];
                unset($item['staff_id']);
            }

            $options = new Varien_Object($item);
            $quote_item = $quote->addProduct($product, $options);

            if ($staff_id) {
                $quote_item->setStaffId($staff_id);
                if ($quote_item->getParentItem()) {
                    $quote_item->getParentItem()->setStaffId($staff_id);
                }
            }

        }

        // Add billing address to quote
        $billingAddressData = $quote->getBillingAddress()->addData($data['billing']);

        // Add shipping address to quote
        $shippingAddressData = $quote->getShippingAddress()->addData($data['shipping']);

        /**
         * Billing or Shipping address for already registered customers can be fetched like below
         * 
         * $customerBillingAddress = $customer->getPrimaryBillingAddress();
         * $customerShippingAddress = $customer->getPrimaryShippingAddress();
         * 
         * Instead of the custom address, you can add these customer address to quote as well
         * 
         * $billingAddressData = $quote->getBillingAddress()->addData($customerBillingAddress);
         * $shippingAddressData = $quote->getShippingAddress()->addData($customerShippingAddress);
         */

        // Collect shipping rates on quote shipping address data
        $shippingAddressData->setCollectShippingRates(true)
            ->collectShippingRates();

        // Set shipping and payment method on quote shipping address data
        $shippingAddressData->setShippingMethod($shippingMethod)
            ->setPaymentMethod($paymentMethod)->setCollectShippingRates(true)->save();

        // Set payment method for the quote
        $quote->getPayment()->importData(array('method' => $paymentMethod));
        $quote->getPayment()->setPoNumber($data['po']);
        //        die('getPayment');
        $shippingAddressData->setShippingMethod($shippingMethod)
            ->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod($shippingMethod);

        // check if shipping method is valid
        if (!$quote->getShippingAddress()->getShippingRateByCode($shippingMethod)) {
            $out = '';
            foreach ($quote->getShippingAddress()->getShippingRatesCollection() as $rate) {
                $out .= $rate->getCode() . ',';
            }
            echo json_encode(['error' => 'shipping_method must be one of ' . $out]);
            return;
        }
        // Collect totals of the quote
        $quote->collectTotals();
        // Save quote
        $quote->save();

        // Create Order From Quote
        $service = Mage::getModel('sales/service_quote', $quote);
        $service->submitAll();
        $incrementId = $service->getOrder()->getRealOrderId();

        Mage::getSingleton('checkout/session')
            ->setLastQuoteId($quote->getId())
            ->setLastSuccessQuoteId($quote->getId())
            ->clearHelperData();

        /**
         * For more details about saving order
         * See saveOrder() function of app/code/core/Mage/Checkout/Onepage.php
         */

        $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        $order->setData('state', $data['status']);
        $order->setStatus($data['status']);
        $history = $order->addStatusHistoryComment('Order was set to ' . $data['status'] . ' by punchout.', false);
        $history->setIsCustomerNotified(false);
        $order->save();


        $result['id'] = $incrementId;

        // Show response
        echo Mage::helper('core')->jsonEncode($result);
    }

    private function authorize()
    {
        $authorization_header = $this->getRequest()->getParam('authorization_header');
        $res = $this->post('https://punchout.cloud/authorize', ["authorization" => $authorization_header]);
        if ($res["authorized"] !== true) {
            echo json_encode(["error" => "You're not authorized", "error_data" => $res]);
            exit;
        }
    }

    private function getInvoices()
    {
        $order_increment_id = $this->getRequest()->getParam('order_increment_id');
        $order = Mage::getModel('sales/order')->loadByIncrementId($order_increment_id);
        $invoices = $order->getInvoiceCollection();
        $data = [];
        foreach ($invoices as $invoice) {
            $invoice_data = $invoice->getData();
            $invoice_data['items'] = [];
            foreach ($invoice->getItemsCollection() as $item) {
                $invoice_data['items'][] = $item->getData();
            }
            $data[] = $invoice_data;
        }
        return $data;
    }
}