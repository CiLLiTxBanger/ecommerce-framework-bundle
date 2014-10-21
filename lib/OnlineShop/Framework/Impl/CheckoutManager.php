<?php

class OnlineShop_Framework_Impl_CheckoutManager implements OnlineShop_Framework_ICheckoutManager {

    const CURRENT_STEP = "checkout_current_step";
    const FINISHED = "checkout_finished";
    const CART_READONLY_PREFIX = "checkout_cart_readonly";
    const COMMITTED = "checkout_committed";
    const TRACK_ECOMMERCE = "checkout_trackecommerce";
    const TRACK_ECOMMERCE_UNIVERSAL = "checkout_trackecommerce_universal";

    protected $checkoutSteps;
    protected $checkoutStepOrder;
    protected $currentStep;
    protected $finished = false;
    protected $committed = false;
    protected $paid = true;

    protected $parentFolderId = 1;
    protected $orderClassname;
    protected $orderItemClassname;
    protected $confirmationMail;

    /**
     * @var OnlineShop_Framework_ICommitOrderProcessor
     */
    protected $commitOrderProcessor;
    protected $commitOrderProcessorClassname;

    /**
     * @var OnlineShop_Framework_ICart
     */
    protected $cart;

    /**
     * Payment Provider
     *
     * @var OnlineShop_Framework_IPayment
     */
    protected $payment;


    /**
     * @param OnlineShop_Framework_ICart $cart
     * @param                            $config
     */
    public function __construct(OnlineShop_Framework_ICart $cart, $config) {
        $this->cart = $cart;

        $parentFolderId = (string)$config->parentorderfolder;
        if(is_numeric($parentFolderId))
        {
            $this->parentFolderId = (int)$parentFolderId;
        }
        else
        {
            $p = Object_Service::createFolderByPath( strftime($parentFolderId, time()) );
            $this->parentFolderId = $p->getId();
            unset($p);
        }

        $this->commitOrderProcessorClassname = $config->commitorderprocessor->class;
        $this->orderClassname = (string)$config->orderstorage->orderClass;
        $this->orderItemClassname = (string)$config->orderstorage->orderItemClass;
        $this->confirmationMail = (string)$config->mails->confirmation;
        foreach($config->steps as $step) {
            $step = new $step->class($this->cart);
            $this->checkoutStepOrder[] = $step;
            $this->checkoutSteps[$step->getName()] = $step;
        }

        $env = OnlineShop_Framework_Factory::getInstance()->getEnvironment();
        $this->finished = $env->getCustomItem(self::FINISHED . "_" . $this->cart->getId());
        $this->committed = $env->getCustomItem(self::COMMITTED . "_" . $this->cart->getId());
        $this->currentStep = $this->checkoutSteps[$env->getCustomItem(self::CURRENT_STEP . "_" . $this->cart->getId())];

        if(empty($this->currentStep) && !$this->isFinished()) {
            $this->currentStep = $this->checkoutStepOrder[0];  
        }


        // init payment provider
        if($config->payment)
        {
            $this->payment = OnlineShop_Framework_Factory::getInstance()->getPaymentManager()->getProvider( $config->payment->provider );
        }

    }

    protected function getCommitOrderProcessor() {
        if(!$this->commitOrderProcessor) {
            $this->commitOrderProcessor = new $this->commitOrderProcessorClassname();
            $this->commitOrderProcessor->setParentOrderFolder($this->parentFolderId);
            $this->commitOrderProcessor->setOrderClass($this->orderClassname);
            $this->commitOrderProcessor->setOrderItemClass($this->orderItemClassname);
            $this->commitOrderProcessor->setConfirmationMail($this->confirmationMail);
        }
        return $this->commitOrderProcessor;
    }

    /**
     * @return OnlineShop_Framework_AbstractPaymentInformation
     */
    public function startOrderPayment() {
        if($this->committed) {
            throw new OnlineShop_Framework_Exception_UnsupportedException("Cart already committed.");
        }

        if(!$this->isFinished()) {
            throw new OnlineShop_Framework_Exception_UnsupportedException("Checkout not finished yet.");
        }

        if(!$this->payment) {
            throw new OnlineShop_Framework_Exception_UnsupportedException("Payment is not activated");
        }

        //Create Order and PaymentInformation
        $order = $this->getCommitOrderProcessor()->getOrCreateOrder($this->cart);

        if($order->getOrderState() == OnlineShop_Framework_AbstractOrder::ORDER_STATE_COMMITTED) {
            throw new Exception("Order already committed");
        }

        $paymentInfo = $this->getCommitOrderProcessor()->getOrCreateActivePaymentInfo($order);

        //Make Cart ReadOnly and in PaymentMode
        $env = OnlineShop_Framework_Factory::getInstance()->getEnvironment();
        $env->setCustomItem(self::CART_READONLY_PREFIX . "_" . $this->cart->getId(), "READONLY");
        $env->save();

        return $paymentInfo;
    }

    /**
     * @return OnlineShop_Framework_AbstractOrder
     */
    public function getOrder() {
        return $this->getCommitOrderProcessor()->getOrCreateOrder($this->cart);
    }


    /**
     * @param OnlineShop_Framework_Payment_IStatus $status
     *
     * @return OnlineShop_Framework_AbstractOrder
     * @throws OnlineShop_Framework_Exception_UnsupportedException
     */
    public function commitOrderPayment(OnlineShop_Framework_Payment_IStatus $status) {
        if(!$this->payment) {
            throw new OnlineShop_Framework_Exception_UnsupportedException("Payment is not activated");
        }

        $order = $this->getCommitOrderProcessor()->updateOrderPayment($status);

        $env = OnlineShop_Framework_Factory::getInstance()->getEnvironment();
        $env->removeCustomItem(self::CART_READONLY_PREFIX . "_" . $this->cart->getId());
        $env->save();

        if(in_array($status->getStatus(), [OnlineShop_Framework_AbstractOrder::ORDER_STATE_COMMITTED, OnlineShop_Framework_AbstractOrder::ORDER_STATE_PAYMENT_AUTHORIZED])) {
            $order = $this->commitOrder();
        } else {
            $this->currentStep = $this->checkoutStepOrder[count($this->checkoutStepOrder) - 1];
            $this->finished = false;

            $env = OnlineShop_Framework_Factory::getInstance()->getEnvironment();
            $env->setCustomItem(self::CURRENT_STEP . "_" . $this->cart->getId(), $this->currentStep->getName());
            $env->setCustomItem(self::FINISHED . "_" . $this->cart->getId(), $this->finished);
            $env->save();
        }


        return $order;
    }

    /**
     * @return OnlineShop_Framework_AbstractOrder
     * @throws OnlineShop_Framework_Exception_UnsupportedException
     */
    public function commitOrder() {
        if($this->committed) {
            throw new OnlineShop_Framework_Exception_UnsupportedException("Cart already committed.");
        }

        if(!$this->isFinished()) {
            throw new OnlineShop_Framework_Exception_UnsupportedException("Checkout not finished yet.");
        }

        $result = $this->getCommitOrderProcessor()->commitOrder($this->cart);
        $this->committed = true;

        $env = OnlineShop_Framework_Factory::getInstance()->getEnvironment();
        $env->removeCustomItem(self::CURRENT_STEP . "_" . $this->cart->getId());
        $env->removeCustomItem(self::FINISHED . "_" . $this->cart->getId());
        $env->removeCustomItem(self::COMMITTED . "_" . $this->cart->getId());

        $env->setCustomItem(self::TRACK_ECOMMERCE . "_" . $result->getOrdernumber(), $this->generateGaEcommerceCode($result));
        $env->setCustomItem(self::TRACK_ECOMMERCE_UNIVERSAL . "_" . $result->getOrdernumber(), $this->generateUniversalEcommerceCode($result));

        $env->save();

        return $result;
    }


    protected function generateGaEcommerceCode(OnlineShop_Framework_AbstractOrder $order) {
        $code = "";

        $shipping = 0;
        $modifications = $order->getPriceModifications();
        foreach($modifications as $modification) {
            if($modification->getName() == "shipping") {
                $shipping = $modification->getAmount();
                break;
            }
        }

        $code .= "
            _gaq.push(['_addTrans',
              '" . $order->getOrdernumber() . "',           // order ID - required
              '',  // affiliation or store name
              '" . $order->getTotalPrice() . "',          // total - required
              '',           // tax
              '" . $shipping . "',              // shipping
              '',       // city
              '',     // state or province
              ''             // country
            ]);
        \n";

        $items = $order->getItems();
        if(!empty($items)) {
            foreach($items as $item) {

                $category = "";
                $p = $item->getProduct();
                if($p && method_exists($p, "getCategories")) {
                    $categories = $p->getCategories();
                    if($categories) {
                        $category = $categories[0];
                        if(method_exists($category, "getName")) {
                            $category = $category->getName();
                        }
                    }
                }

                $code .= "
                    _gaq.push(['_addItem',
                        '" . $order->getOrdernumber() . "', // order ID - required
                        '" . $item->getProductNumber() . "', // SKU/code - required
                        '" . str_replace(array("\n"), array(" "), $item->getProductName()) . "', // product name
                        '" . $category . "',   // category or variation
                        '" . $item->getTotalPrice() / $item->getAmount() . "', // unit price - required
                        '" . $item->getAmount() . "'      // quantity - required
                    ]);
                \n";
            }
        }

        $code .= "_gaq.push(['_trackTrans']);";

        return $code;
    }


    protected function generateUniversalEcommerceCode(OnlineShop_Framework_AbstractOrder $order) {
        $code = "ga('require', 'ecommerce', 'ecommerce.js');\n";


        $shipping = 0;
        $modifications = $order->getPriceModifications();
        foreach($modifications as $modification) {
            if($modification->getName() == "shipping") {
                $shipping = $modification->getAmount();
                break;
            }
        }

        $code .= "
            ga('ecommerce:addTransaction', {
              'id': '" . $order->getOrdernumber() . "',                     // Transaction ID. Required.
              'affiliation': '',   // Affiliation or store name.
              'revenue': '" . $order->getTotalPrice() . "',               // Grand Total.
              'shipping': '" . $shipping . "',                  // Shipping.
              'tax': ''                     // Tax.
            });
        \n";

        $items = $order->getItems();
        if(!empty($items)) {
            foreach($items as $item) {

                $category = "";
                $p = $item->getProduct();
                if($p && method_exists($p, "getCategories")) {
                    $categories = $p->getCategories();
                    if($categories) {
                        $category = $categories[0];
                        if(method_exists($category, "getName")) {
                            $category = $category->getName();
                        }
                    }
                }

                $code .= "
                    ga('ecommerce:addItem', {
                      'id': '" . $order->getOrdernumber() . "',                      // Transaction ID. Required.
                      'name': '" . str_replace(array("\n"), array(" "), $item->getProductName()) . "',                      // Product name. Required.
                      'sku': '" . $item->getProductNumber() . "',                     // SKU/code.
                      'category': '" . $category . "',                                // Category or variation.
                      'price': '" . $item->getTotalPrice() / $item->getAmount() . "', // Unit price.
                      'quantity': '" . $item->getAmount() . "'                        // Quantity.
                    });
                \n";
            }
        }

        $code .= "ga('ecommerce:send');\n";

        return $code;
    }

    /**
     * @param OnlineShop_Framework_ICheckoutStep $step
     * @param  $data
     * @return bool
     * @throws OnlineShop_Framework_Exception_UnsupportedException
     */
    public function commitStep(OnlineShop_Framework_ICheckoutStep $step, $data) {
        $indexCurrentStep = array_search($this->currentStep, $this->checkoutStepOrder);
        $index = array_search($step, $this->checkoutStepOrder);

        if($indexCurrentStep < $index) {
            throw new OnlineShop_Framework_Exception_UnsupportedException("There are uncommitted previous steps.");
        }
        $result = $step->commit($data);

        if($result) {
            $env = OnlineShop_Framework_Factory::getInstance()->getEnvironment();
            $index = array_search($step, $this->checkoutStepOrder);
            $index++;
            if(count($this->checkoutStepOrder) > $index) {
                $this->currentStep = $this->checkoutStepOrder[$index];
                $this->finished = false;

                $env->setCustomItem(self::CURRENT_STEP . "_" . $this->cart->getId(), $this->currentStep->getName());
            } else {
//                $this->currentStep = null;
                $this->finished = true;

//                $env->setCustomItem(self::CURRENT_STEP . "_" . $this->cart->getId(), null);
            }
            $env->setCustomItem(self::FINISHED . "_" . $this->cart->getId(), $this->finished);
            $env->setCustomItem(self::COMMITTED . "_" . $this->cart->getId(), $this->committed);

            $this->cart->save();
            $env->save();
        }
        return $result;
    }


    /**
     * @return OnlineShop_Framework_ICart
     */
    public function getCart() {
        return $this->cart;
    }

    /**
     * @param  $stepname
     * @return OnlineShop_Framework_ICheckoutStep
     */
    public function getCheckoutStep($stepname) {
        return $this->checkoutSteps[$stepname];
    }

    /**
     * @return array(OnlineShop_Framework_ICheckoutStep)
     */
    public function getCheckoutSteps() {
        return $this->checkoutStepOrder;
    }

    /**
     * @return OnlineShop_Framework_ICheckoutStep
     */
    public function getCurrentStep() {
        return $this->currentStep;
    }

    /**
     * @return bool
     */
    public function isFinished() {
        return $this->finished;
    }

    /**
     * @return bool
     */
    public function isCommitted() {
        return $this->committed;
    }


    /**
     * @return OnlineShop_Framework_IPayment
     */
    public function getPayment()
    {
        return $this->payment;
    }


    public function cleanUpPendingOrders() {
        $this->getCommitOrderProcessor()->cleanUpPendingOrders();
    }
}
