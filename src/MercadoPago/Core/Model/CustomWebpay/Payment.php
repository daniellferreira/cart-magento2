<?php

namespace MercadoPago\Core\Model\CustomWebpay;

use Exception;
use Magento\Checkout\Model\Cart;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;
use MercadoPago\Core\Helper\ConfigData;
use MercadoPago\Core\Helper\Round;
use MercadoPago\Core\Helper\SponsorId;

/**
 * Class Payment
 */
class Payment extends \MercadoPago\Core\Model\Custom\Payment
{
    /**
     * Define callback endpoints
     */
    const SUCCESS_PATH = 'mercadopago/customwebpay/pay';
    const FAILURE_PATH = 'mercadopago/customwebpay/failure';
    const NOTIFICATION_PATH = 'mercadopago/notifications/custom';

    /**
     * Define payment method code
     */
    const CODE = 'mercadopago_custom_webpay';

    /**
     * log filename
     */
    const LOG_NAME = 'custom_webpay';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function initialize($paymentAction, $stateObject){}

    /**
     * is payment method available?
     *
     * @param CartInterface|null $quote
     *
     * @return boolean
     * @throws LocalizedException
     */
    public function isAvailable(CartInterface $quote = null) {
        $isActive = $this->_scopeConfig->getValue(ConfigData::PATH_CUSTOM_WEBPAY_ACTIVE, ScopeInterface::SCOPE_STORE);

        if (empty($isActive)) {
            return false;
        }

        return parent::isAvailableMethod($quote);
    }//end isAvailable()

    /**
     * @param  DataObject $data
     * @return $this|Payment
     * @throws LocalizedException
     */
    public function assignData(DataObject $data) {
        if (!($data instanceof DataObject)) {
            $data = new DataObject($data);
        }

        $infoForm = $data->getData();

        if (isset($infoForm['additional_data'])) {
            if (empty($infoForm['additional_data'])) {
                return $this;
            }

            $info = $this->getInfoInstance();
            $info->setAdditionalInformation('method', $infoForm['method']);
        }

        return $this;
    }//end assignData

    /**
     * @param $quoteId
     * @param $token
     * @param $paymentMethodId
     * @param $issuerId
     * @param $installments
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function createPayment($quoteId, $token, $paymentMethodId, $issuerId, $installments)
    {
        $preference = $this->makePreference($quoteId, $token, $paymentMethodId, $issuerId, $installments);
        $response   = $this->_coreModel->postPaymentV1($preference);

        if (isset($response['status']) && $response['status'] >= 200 && $response['status'] <= 299) {
            if (isset($response['response']['status']) && $response['response']['status'] != 'rejected') {
                return $response;
            }
        }

        $this->_helperData->log(
            'CustomPaymentWebpay - exception: it was unable to process payment with webpay',
            self::LOG_NAME,
            $response
        );

        throw new Exception(__("Sorry, it was unable to process payment with webpay!"));
    }//end createPayment()

    /**
     * @param  $payment
     * @return void
     * @throws Exception
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function createOrder($payment)
    {
        $orderIncrementalId = $payment['external_reference'];
        $order = $this->loadOrderByIncrementalId($orderIncrementalId);

        if (!$order->getIncrementId()) {
            $quote = $this->_checkoutSession->getQuote();
            $quote->getPayment()->setMethod('mercadopago_custom_webpay');
            $order = $this->createOrderByPaymentWithQuote($payment);
        }

        if (!$order->getIncrementId()) {
            throw new Exception(__("Sorry, we can't create a order with external reference #%1", $orderIncrementalId));
        }

        $this->_paymentNotification->updateStatusOrderByPayment($payment);

        $this->_checkoutSession->setLastSuccessQuoteId($payment['metadata']['quote_id']);
        $this->_checkoutSession->setLastQuoteId($payment['metadata']['quote_id']);
        $this->_checkoutSession->setLastOrderId($payment['external_reference']);
        $this->_checkoutSession->setLastRealOrderId($payment['external_reference']);

        $order->getPayment()->setAdditionalInformation('paymentResponse', $payment);
        $order->save();
    }//end createOrder()

    /**
     * @return Cart
     */
    public function getCartObject()
    {
        $objectManager = ObjectManager::getInstance();
        return $objectManager->get('\Magento\Checkout\Model\Cart');
    }//end getCartObject()

    /**
     * @return string
     */
    public function getQuoteId()
    {
        return $this->getCartObject()->getQuote()->getId();
    }//end getQuoteId()

    /**
     * @param $quoteId
     * @return Quote
     * @throws NoSuchEntityException
     */
    public function activeQuote($quoteId)
    {
        return $this->getReservedQuote($quoteId)->setIsActive(true);
    }

    /**
     * @param $quoteId
     * @return CartInterface|Quote
     * @throws NoSuchEntityException
     */
    public function getReservedQuote($quoteId)
    {
        return $this->_quoteRepository->get($quoteId);
    }//end getReservedQuote()

    /**
     * @param $quoteId
     * @throws Exception
     * @throws NoSuchEntityException
     */
    public function persistCartSession($quoteId)
    {
        $quote = $this->getReservedQuote($quoteId);
        $quote->setIsActive(true)->setReservedOrderId(null)->save();
        $this->_checkoutSession->replaceQuote($quote);
    }

    /**
     * @param $quoteId
     * @param $token
     * @param $paymentMethodId
     * @param $issuerId
     * @param $installments
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function makePreference($quoteId, $token, $paymentMethodId, $issuerId, $installments)
    {
        $quote = $this->getReservedQuote($quoteId);
        $this->activeQuote($quoteId);

        $preference = $this->getPreference();
        $customer   = $this->getCustomer($quoteId);
        $siteId     = $preference['metadata']['site'];

        $quote->reserveOrderId();

        $preference['external_reference']       = $quote->getReservedOrderId();
        $preference['additional_info']['items'] = $this->getItems($quote, $siteId);
        $preference['additional_info']['payer'] = $this->getPayer($quote, $customer);
        $preference['token']                    = $token;
        $preference['issuer_id']                = $issuerId;
        $preference['installments']             = (int) $installments;
        $preference['payment_method_id']        = $paymentMethodId;
        $preference['payer']['email']           = $preference['additional_info']['payer']['email'];
        $preference['transaction_amount']       = Round::roundWithSiteId($quote->getBaseGrandTotal(), $siteId);

        if (!$customer->getId()) {
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerEmail($preference['payer']['email']);
            $quote->setCustomerFirstname($preference['additional_info']['payer']['first_name']);
            $quote->setCustomerLastname($preference['additional_info']['payer']['last_name']);
        }

        if ($quote->getShippingAddress()) {
            $preference['additional_info']['shipments'] = $this->getShipments($quote);
        }

        $preference['metadata']['test_mode'] = $this->isTestMode($preference['payer']);
        $preference['metadata']['quote_id']  = $quote->getId();

        unset($preference['additional_info']['payer']['email']);

        $this->_quoteRepository->save($quote);

        return $preference;
    }//end makePreference()

    /**
     * @return array
     */
    protected function getPreference()
    {
        $this->_version->afterLoad();

        return [
            'additional_info' => [
                'items'     => [],
                'payer'     => [],
                'shipments' => [],
            ],
            'notification_url'     => $this->getNotificationUrl(),
            'statement_descriptor' => $this->getStateDescriptor(),
            'external_reference'   => '',
            'metadata'             => [
                'site'             => $this->getSiteId(),
                'platform'         => 'BP1EF6QIC4P001KBGQ10',
                'platform_version' => $this->_productMetadata->getVersion(),
                'module_version'   => $this->_version->getValue(),
                'sponsor_id'       => $this->getSponsorId(),
                'test_mode'        => '',
                'quote_id'         => '',
                'checkout'         => 'custom',
                'checkout_type'    => 'webpay',
            ],
        ];
    }//end getPreference()

    /**
     * @param  $path
     * @param  $scopeType
     * @return mixed
     */
    protected function getConfig($path, $scopeType = ScopeInterface::SCOPE_STORE)
    {
        return $this->_scopeConfig->getValue($path, $scopeType);
    }//end getConfig()

    /**
     * @return mixed
     */
    protected function getStateDescriptor()
    {
        return $this->getConfig(ConfigData::PATH_CUSTOM_STATEMENT_DESCRIPTOR);
    }//end getStateDescriptor()

    /**
     * @return false|string|string[]
     */
    protected function getSiteId()
    {
        return mb_strtoupper($this->getConfig(ConfigData::PATH_SITE_ID));
    }//end getSiteId()

    /**
     * @return int|null
     */
    protected function getSponsorId()
    {
        return SponsorId::getSponsorId($this->getSiteId());
    }//end getSponsorId()

    /**
     * @return string|void
     */
    protected function getNotificationUrl()
    {
        $params = array(
            '_query' => array(
                'source_news' => 'webhooks'
            )
        );

        $notification_url = $this->_urlBuilder->getUrl(self::NOTIFICATION_PATH, $params);

        if (strrpos($notification_url, 'localhost')) {
            return;
        }

        return $notification_url;
    }

    /**
     * @return CustomerInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getCustomer($quoteId)
    {
        return $this->getReservedQuote($quoteId)->getCustomer();
    }//end getCustomer()

    /**
     * @param  Quote  $quote
     * @param  $siteId
     * @return array
     */
    protected function getItems(Quote $quote, $siteId)
    {
        $items      = [];
        $categoryId = $this->getConfig(ConfigData::PATH_ADVANCED_CATEGORY);

        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = $this->getItem($item, $categoryId, $siteId);
        }

        $discount = $this->getDiscountAmount($quote);
        if ($discount < 0) {
            $items[] = $this->getItemDiscountTax(__('Discount'), $discount, $siteId);
        }

        $tax = $this->getTaxAmount($quote, $siteId);
        if ($tax > 0) {
            $items[] = $this->getItemDiscountTax(__('Tax'), $tax, $siteId);
        }

        $shipping = $this->getItemShipping($quote, $siteId);
        if (!empty($shipping)) {
            $items[] = $shipping;
        }

        return $items;
    }//end getItems()

    /**
     * @param  Item   $item
     * @param  string $categoryId
     * @param  string $siteId
     * @return array
     */
    protected function getItem(Item $item, $categoryId, $siteId)
    {
        $product = $item->getProduct();
        $image   = $this->_helperImage->init($product, 'product_thumbnail_image');

        return [
            'id'          => $item->getSku(),
            'title'       => $product->getName(),
            'description' => $product->getName(),
            'picture_url' => $image->getUrl(),
            'category_id' => $categoryId,
            'quantity'    => Round::roundInteger($item->getQty()),
            'unit_price'  => Round::roundWithSiteId($item->getPrice(), $siteId),
        ];
    }//end getItem()

    /**
     * @param Quote $quote
     * @return float
     */
    protected function getDiscountAmount(Quote $quote)
    {
        return ($quote->getSubtotalWithDiscount() - $quote->getBaseSubtotal());
    }//end processDiscount()

    /**
     * @param Quote $quote
     * @return float
     */
    protected function getTaxAmount(Quote $quote)
    {
        return $quote->getGrandTotal() - ($quote->getShippingAddress()->getShippingAmount() + $quote->getSubtotalWithDiscount());
    }//end processTaxes()

    /**
     * @param  $title
     * @param  $amount
     * @param  $siteId
     * @return array
     */
    protected function getItemDiscountTax($title, $amount, $siteId)
    {
        return [
            'id'          => $title,
            'title'       => $title,
            'description' => $title,
            'quantity'    => 1,
            'unit_price'  => Round::roundWithSiteId($amount, $siteId),
        ];
    }//end getItemDiscountTax()

    /**
     * @param  Quote  $quote
     * @param  string $siteId
     * @return array
     */
    protected function getItemShipping(Quote $quote, $siteId)
    {
        return [
            'id'          => __('Shipping'),
            'title'       => __('Shipping'),
            'quantity'    => 1,
            'description' => $quote->getShippingAddress()->getShippingMethod(),
            'unit_price'  => Round::roundWithSiteId($quote->getShippingAddress()->getShippingAmount(), $siteId),
        ];
    }//end getItemShipping()

    /**
     * @param  Quote             $quote
     * @param  CustomerInterface $customer
     * @return array
     */
    protected function getPayer(Quote $quote, CustomerInterface $customer)
    {
        $billing   = $quote->getBillingAddress();
        $shipping  = $quote->getShippingAddress();
        $data      = $customer->getId() ? $customer : $billing;
        $email     = $data->getEmail() ? $data->getEmail() : $shipping->getEmail();

        return [
            'email'        => htmlentities($email),
            'first_name'   => htmlentities($data->getFirstname()),
            'last_name'    => htmlentities($data->getLastname()),
            'address'      => [
                'zip_code'    => $billing->getPostcode(),
                'street_name' => sprintf(
                    '%s - %s - %s - %s',
                    implode(', ', $billing->getStreet()),
                    $billing->getCity(),
                    $billing->getRegion(),
                    $billing->getCountry()
                ),
                'street_number' => '',
            ],
            'phone' => [
                "area_code" => '-',
                "number"    => $shipping['telephone']
            ],
        ];
    }//end getPayer()

    /**
     * @param Quote $quote
     * @return array
     */
    protected function getShipments(Quote $quote)
    {
        $billing = $quote->getBillingAddress();

        return [
            'receiver_address' => [
                'zip_code'     => $billing->getPostcode(),
                'street_name'  => sprintf(
                    '%s - %s - %s - %s',
                    implode(', ', $billing->getStreet()),
                    $billing->getCity(),
                    $billing->getRegion(),
                    $billing->getCountry()
                ),
                'street_number' => '-',
                'apartment'     => '-',
                'floor'         => '-',
            ],
        ];
    }//end getShipments()

    /**
     * @param  $payer
     * @return boolean
     */
    protected function isTestMode($payer)
    {
        if (!empty($this->getSponsorId())) {
            return false;
        }

        if (preg_match('/@testuser\.com$/i', $payer['email'])) {
            return true;
        }

        return false;
    }//end isTestMode()

    /**
     * @param  $incrementalId
     * @return OrderInterface
     */
    public function loadOrderByIncrementalId($incrementalId)
    {
        return $this->_order->loadByIncrementId($incrementalId);
    }//end loadOrderByIncrementalId()

    /**
     * @param  $orderId
     * @return OrderInterface
     */
    public function loadOrderById($orderId)
    {
        return $this->_order->loadByAttribute('entity_id', $orderId);
    }//end loadOrderById()

    /**
     * @param $payment
     * @return OrderInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    protected function createOrderByPaymentWithQuote($payment)
    {
        $quoteId = $payment['metadata']['quote_id'];

        $quote = $this->_quoteRepository->get($quoteId);
        $quote->getPayment()->importData(['method' => 'mercadopago_custom_webpay']);

        $orderId = $this->_quoteManagement->placeOrder($quote->getId());

        return $this->loadOrderById($orderId);
    }//end createOrderByPaymentWithQuote()
}
