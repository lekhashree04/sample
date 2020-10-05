<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Frontend\Services\AccountService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Payment\History\Contracts\PaymentHistoryRepositoryContract;
use Plenty\Modules\Payment\History\Models\PaymentHistory as PaymentHistoryModel;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{

    use Loggable;
    
    /**
     * @var PaymentHistoryRepositoryContract
     */
    private $paymentHistoryRepo;
    
   /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     * @var ConfigRepository
     */
    private $config;
   
    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var TransactionLogData
     */
    private $transactionLogData;
    
    private $redirectPayment = ['NOVALNET_SOFORT', 'NOVALNET_PAYPAL', 'NOVALNET_IDEAL', 'NOVALNET_EPS', 'NOVALNET_GIROPAY', 'NOVALNET_PRZELEWY'];

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param TransactionService $transactionLogData
     */
    public function __construct(ConfigRepository $config,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                AddressRepositoryContract $addressRepository,
                                CountryRepositoryContract $countryRepository,
                                WebstoreHelper $webstoreHelper,
                                PaymentHelper $paymentHelper,
                                PaymentHistoryRepositoryContract $paymentHistoryRepo,
                                PaymentRepositoryContract $paymentRepository,
                                TransactionService $transactionLogData)
    {
        $this->config                   = $config;
        $this->sessionStorage           = $sessionStorage;
        $this->addressRepository        = $addressRepository;
        $this->countryRepository        = $countryRepository;
        $this->webstoreHelper           = $webstoreHelper;
        $this->paymentHistoryRepo       = $paymentHistoryRepo;
        $this->paymentRepository        = $paymentRepository;
        $this->paymentHelper            = $paymentHelper;
        $this->transactionLogData       = $transactionLogData;
    }
    
    /**
     * Push notification
     *
     */
    public function pushNotification($message, $type, $code = 0) {
        
    $notifications = json_decode($this->sessionStorage->getPlugin()->getValue('notifications'), true);  
        
    $notification = [
            'message'       => $message,
            'code'          => $code,
            'stackTrace'    => []
           ];
        
    $lastNotification = $notifications[$type];

        if( !is_null($lastNotification) )
    {
            $notification['stackTrace'] = $lastNotification['stackTrace'];
            $lastNotification['stackTrace'] = [];
            array_push( $notification['stackTrace'], $lastNotification );
        }
        
        $notifications[$type] = $notification;
        $this->sessionStorage->getPlugin()->setValue('notifications', json_encode($notifications));
    }
    /**
     * Validate  the response data.
     *
     */
    public function validateResponse()
    {
        $nnPaymentData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $lang = strtolower((string)$nnPaymentData['lang']);
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', null);
       
        $nnPaymentData['mop']            = $this->sessionStorage->getPlugin()->getValue('mop');
        $nnPaymentData['payment_method'] = strtolower($this->paymentHelper->getPaymentKeyByMop($nnPaymentData['mop']));
        
        $this->executePayment($nnPaymentData);
        
        if($nnPaymentData['payment_id'] == '59' && !empty($nnPaymentData['cp_checkout_token']))
        {
        $this->sessionStorage->getPlugin()->setValue('novalnet_checkout_token', $nnPaymentData['cp_checkout_token']);
              
        }
        
        $additional_info = $this->additionalInfo($nnPaymentData);

        $transactionData = [
            'amount'           => $nnPaymentData['amount'] * 100,
            'callback_amount'  => $nnPaymentData['amount'] * 100,
            'tid'              => $nnPaymentData['tid'],
            'ref_tid'          => $nnPaymentData['tid'],
            'payment_name'     => $nnPaymentData['payment_method'],
            'order_no'         => $nnPaymentData['order_no'],
            'additional_info'      => !empty($additional_info) ? json_encode($additional_info) : '0',
        ];
       
        if(in_array($nnPaymentData['payment_id'], ['27', '59']) || (in_array($nnPaymentData['tid_status'], ['85','86','90'])))
            $transactionData['callback_amount'] = 0;    

        $this->transactionLogData->saveTransaction($transactionData);

     }
     
    /**
     * Creates the payment for the order generated in plentymarkets.
     *
     * @param array $requestData 
     * @param bool $callbackfailure
     * 
     * @return array
     */
    public function executePayment($requestData, $callbackfailure = false)
    {
        try {
            if(!$callbackfailure &&  in_array($requestData['status'], ['100', '90'])) {
                if(in_array($requestData['tid_status'], ['86', '90'])) {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_payment_pending_status'));
                    $requestData['paid_amount'] = 0;
                } elseif($requestData['tid_status'] == '75') {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_payment_guarantee_status'));
                    $requestData['paid_amount'] = 0;
                } elseif($requestData['payment_id'] == '41' && $requestData['tid_status'] == '100') {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_invoice_payment_guarantee_order_completion_status'));
                    $requestData['paid_amount'] = $requestData['amount'];
                } elseif(in_array($requestData['payment_id'], ['27', '59']) && $requestData['tid_status'] == '100') {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_order_completion_status'));
                    $requestData['paid_amount'] = 0;
                } elseif(in_array($requestData['tid_status'], ['85', '91', '98', '99'])) {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_onhold_confirmation_status'));
                    $requestData['paid_amount'] = 0;
                }
            else {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_order_completion_status'));
                    $requestData['paid_amount'] = ($requestData['tid_status'] == '100') ? $requestData['amount'] : '0';
                }
            } else {
                $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_order_cancel_status'));
                $requestData['paid_amount'] = '0';
            }
        
            $this->paymentHelper->createPlentyPayment($requestData);
            $this->paymentHelper->updateOrderStatus((int)$requestData['order_no'], $requestData['order_status']);
           
            return [
                'type' => 'success',
                'value' => $this->paymentHelper->getNovalnetStatusText($requestData)
            ];
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('ExecutePayment failed.', $e);
            return [
                'type'  => 'error',
                'value' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Build Invoice and Prepayment transaction comments
     *
     * @param array $requestData
     * @return string
     */
    public function getInvoicePrepaymentComments($requestData)
    {     
        $comments = '';
        $comments .= PHP_EOL . PHP_EOL . $this->paymentHelper->getTranslatedText('transfer_amount_text');
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('account_holder_novalnet') . $requestData['invoice_account_holder'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('iban') . $requestData['invoice_iban'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bic') . $requestData['invoice_bic'];
        if($requestData['due_date'])
        {
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('due_date') . date('Y/m/d', (int)strtotime($requestData['due_date']));
        }
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bank') . $requestData['invoice_bankname']. ' ' . $requestData['invoice_bankplace'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('amount') . $requestData['amount'] . ' ' . $requestData['currency'];

        $comments .= PHP_EOL . PHP_EOL .$this->paymentHelper->getTranslatedText('any_one_reference_text');
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('payment_reference1').' ' .('BNR-' . $requestData['product_id'] . '-' . $requestData['order_no']). PHP_EOL. $this->paymentHelper->getTranslatedText('payment_reference2') .' ' . 'TID '. $requestData['tid']. PHP_EOL;
        $comments .= PHP_EOL;
        return $comments;
    }

    /**
     * Build Novalnet server request parameters
     *
     * @param Basket $basket
     * @param PaymentKey $paymentKey
     *
     * @return array
     */
    public function getRequestParameters(Basket $basket, $paymentKey = '')
    {
        
     /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
        $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);

        //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
        if(!count($vatService->getCurrentTotalVats())) {
            $basket->itemSum = $basket->itemSumNet;
            $basket->shippingAmount = $basket->shippingAmountNet;
            $basket->basketAmount = $basket->basketAmountNet;
        }
        
        $billingAddressId = $basket->customerInvoiceAddressId;
        $address = $this->addressRepository->findAddressById($billingAddressId);
        if(!empty($basket->customerShippingAddressId)){
            $shippingAddress = $this->addressRepository->findAddressById($basket->customerShippingAddressId);
        }
    
        foreach ($address->options as $option) {
        if ($option->typeId == 12) {
                $name = $option->value;
        }
        }
        $customerName = explode(' ', $name);
        $firstname = $customerName[0];
        if( count( $customerName ) > 1 ) {
            unset($customerName[0]);
            $lastname = implode(' ', $customerName);
        } else {
            $lastname = $firstname;
        }
        $firstName = empty ($firstname) ? $lastname : $firstname;
        $lastName = empty ($lastname) ? $firstname : $lastname;
    
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();
        $paymentKeyLower = strtolower((string) $paymentKey);
        $testModeKey = 'Novalnet.' . $paymentKeyLower . '_test_mode';

        $paymentRequestData = [
            'vendor'             => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
            'auth_code'          => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
            'product'            => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
            'tariff'             => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
            'test_mode'          => (int)($this->config->get($testModeKey) == 'true'),
            'first_name'         => !empty($address->firstName) ? $address->firstName : $firstName,
            'last_name'          => !empty($address->lastName) ? $address->lastName : $lastName,
            'email'              => $address->email,
            'gender'             => 'u',
            'city'               => $address->town,
            'street'             => $address->street,
            'country_code'       => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
            'zip'                => $address->postalCode,
            'customer_no'        => ($customerId) ? $customerId : 'guest',
            'lang'               => strtoupper($this->sessionStorage->getLocaleSettings()->language),
            'amount'             => $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            'currency'           => $basket->currency,
            'remote_ip'          => $this->paymentHelper->getRemoteAddress(),
            'system_ip'          => $this->paymentHelper->getServerAddress(),
            'system_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl,
            'system_name'        => 'Plentymarkets',
            'system_version'     => NovalnetConstants::PLUGIN_VERSION,
            'notify_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/callback/',
            'key'                => $this->getkeyByPaymentKey($paymentKey),
            'payment_type'       => $this->getTypeByPaymentKey($paymentKey)
        ];

        if(!empty($address->houseNumber))
        {
            $paymentRequestData['house_no'] = $address->houseNumber;
        }
        else
        {
            $paymentRequestData['search_in_street'] = '1';
        }

        if(!empty($address->companyName)) {
            $paymentRequestData['company'] = $address->companyName;
        } elseif(!empty($shippingAddress->companyName)) {
            $paymentRequestData['company'] = $shippingAddress->companyName;
        }

        if(!empty($address->phone)) {
            $paymentRequestData['tel'] = $address->phone;
        }

        if(is_numeric($referrerId = $this->paymentHelper->getNovalnetConfig('referrer_id'))) {
            $paymentRequestData['referrer_id'] = $referrerId;
        }
        $url = $this->getPaymentData($paymentKey, $paymentRequestData);
        return [
            'data' => $paymentRequestData,
            'url'  => $url
        ];
    }

    /**
     * Get payment related param
     *
     * @param array $paymentRequestData
     * @param string $paymentKey
     */
    public function getPaymentData($paymentKey, &$paymentRequestData )
    {
        $url = $this->getpaymentUrl($paymentKey);
        if(in_array($paymentKey, ['NOVALNET_CC', 'NOVALNET_SEPA', 'NOVALNET_PAYPAL', 'NOVALNET_INVOICE'])) {
            $onHoldLimit = $this->paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_on_hold');
                $onHoldAuthorize = $this->paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_payment_action');
        if((is_numeric($onHoldLimit) && $paymentRequestData['amount'] >= $onHoldLimit && $onHoldAuthorize == 'true') || ($onHoldAuthorize == 'true' && empty($onHoldLimit))) {
            $paymentRequestData['on_hold'] = '1';
        }
        if($paymentKey == 'NOVALNET_CC') {
                    $onHoldLimit = $this->paymentHelper->getNovalnetConfig('novalnet_cc_on_hold');
                    if($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' ) {
                    if($this->config->get('Novalnet.novalnet_cc_3d') == 'true') {
                        $paymentRequestData['cc_3d'] = '1';
                    }
                    
                    }
        } else if($paymentKey == 'NOVALNET_SEPA') {
                    $dueDate = $this->paymentHelper->getNovalnetConfig('novalnet_sepa_due_date');
                    if(is_numeric($dueDate) && $dueDate >= 2 && $dueDate <= 14) {
                        $paymentRequestData['sepa_due_date'] = $this->paymentHelper->dateFormatter($dueDate);
                    }
                    
        } else if($paymentKey == 'NOVALNET_INVOICE') {
                    $paymentRequestData['invoice_type'] = 'INVOICE';
                    $invoiceDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_invoice_due_date');
                    if(is_numeric($invoiceDueDate)) {
                        $paymentRequestData['due_date'] = $this->paymentHelper->dateFormatter($invoiceDueDate);
                    }
        }
        } else if($paymentKey == 'NOVALNET_PREPAYMENT') {
        $paymentRequestData['invoice_type'] = 'PREPAYMENT';
        } else if($paymentKey == 'NOVALNET_CASHPAYMENT') {
        $cashpaymentDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_cashpayment_due_date');
        if(is_numeric($cashpaymentDueDate)) {
            $paymentRequestData['cp_due_date'] = $this->paymentHelper->dateFormatter($cashpaymentDueDate);
        }
        }

        
        
        return $url;
    }

    
    
  

    /**
     * Encode the server request parameters
     *
     * @param array
     */
    public function encodePaymentData(&$paymentRequestData)
    {
        foreach (['auth_code', 'product', 'tariff', 'amount', 'test_mode'] as $key) {
            // Encoding payment data
            $paymentRequestData[$key] = $this->paymentHelper->encodeData($paymentRequestData[$key], $paymentRequestData['uniqid']);
        }

        // Generate hash value
        $paymentRequestData['hash'] = $this->paymentHelper->generateHash($paymentRequestData);
    }

    /**
     * Get the payment response controller URL to be handled
     *
     * @return string
     */
    private function getReturnPageUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/paymentResponse/';
    }

    /**
    * Get the direct payment process controller URL to be handled
    *
    * @return string
    */
    public function getProcessPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/processPayment/';
    }

   
    /**
    * Get the payment process URL by using plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getpaymentUrl($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>NovalnetConstants::PAYPORT_URL
        ];

        return $payment[$paymentKey];
    }

 

   /**
    * Get payment key by plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getkeyByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>'27'
        ];

        return $payment[$paymentKey];
    }

    /**
    * Get payment type by plenty payment Key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getTypeByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>'INVOICE_START'
        ];

        return $payment[$paymentKey];
    }

   
    
    /**
    * Get the Payment Guarantee status
    *
    * @param object $basket
    * @param string $paymentKey
    * @return string
    */
    public function getGuaranteeStatus(Basket $basket, $paymentKey)
    {
        // Get payment name in lowercase
        $paymentKeyLow = strtolower((string) $paymentKey);
        $guaranteePayment = $this->config->get('Novalnet.'.$paymentKeyLow.'_payment_guarantee_active');
        if ($guaranteePayment == 'true') {
            // Get guarantee minimum amount value
            $minimumAmount = $this->paymentHelper->getNovalnetConfig($paymentKeyLow . '_guarantee_min_amount');
            $minimumAmount = ((preg_match('/^[0-9]*$/', $minimumAmount) && $minimumAmount >= '999')  ? $minimumAmount : '999');
            $amount        = (sprintf('%0.2f', $basket->basketAmount) * 100);

            $billingAddressId = $basket->customerInvoiceAddressId;
            $billingAddress = $this->addressRepository->findAddressById($billingAddressId);
            $customerBillingIsoCode = strtoupper($this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'));

            $shippingAddressId = $basket->customerShippingAddressId;

            $addressValidation = false;
            if(!empty($shippingAddressId))
            {
                $shippingAddress = $this->addressRepository->findAddressById($shippingAddressId);
                $customerShippingIsoCode = strtoupper($this->countryRepository->findIsoCode($shippingAddress->countryId, 'iso_code_2'));

                // Billing address
                $billingAddress = ['street_address' => (($billingAddress->street) ? $billingAddress->street : $billingAddress->address1),
                                   'city'           => $billingAddress->town,
                                   'postcode'       => $billingAddress->postalCode,
                                   'country'        => $customerBillingIsoCode,
                                  ];
                // Shipping address
                $shippingAddress = ['street_address' => (($shippingAddress->street) ? $shippingAddress->street : $shippingAddress->address1),
                                    'city'           => $shippingAddress->town,
                                    'postcode'       => $shippingAddress->postalCode,
                                    'country'        => $customerShippingIsoCode,
                                   ];

             }
             else
             {
                 $addressValidation = true;
             }
            // Check guarantee payment
            if ((((int) $amount >= (int) $minimumAmount && in_array(
                $customerBillingIsoCode,
                [
                 'DE',
                 'AT',
                 'CH',
                ]
            ) && $basket->currency == 'EUR' && ($addressValidation || ($billingAddress === $shippingAddress)))
            )) {
                $processingType = 'guarantee';
            } elseif ($this->config->get('Novalnet.'.$paymentKeyLow.'_payment_guarantee_force_active') == 'true') {   
                $processingType = 'normal';
            } else {
                if ( ! in_array( $customerBillingIsoCode, array( 'AT', 'DE', 'CH' ), true ) ) {
                    $processingType = $this->paymentHelper->getTranslatedText('guarantee_country_error');                   
                } elseif ( $basket->currency !== 'EUR' ) {
                    $processingType = $this->paymentHelper->getTranslatedText('guarantee_currency_error');                  
                } elseif ( ! empty( array_diff( $billingAddress, $shippingAddress ) ) ) {
                    $processingType = $this->paymentHelper->getTranslatedText('guarantee_address_error');                   
                } elseif ( (int) $amount < (int) $minimumAmount ) {
                    $processingType = $this->paymentHelper->getTranslatedText('guarantee_minimum_amount_error'). ' ' . $minimumAmount/100 . ' ' . 'EUR)';                   
                }
            }
            return $processingType;
        }//end if
        return 'normal';
    }
    
   
    /**
     * Show payment for allowed countries
     *
     * @param string $allowed_country
     *
     * @return bool
     */
    public function allowedCountries(Basket $basket, $allowed_country) {
        $allowed_country = str_replace(' ', '', strtoupper($allowed_country));
        $allowed_country_array = explode(',', $allowed_country);    
        try {
            if (! is_null($basket) && $basket instanceof Basket && !empty($basket->customerInvoiceAddressId)) {         
                $billingAddressId = $basket->customerInvoiceAddressId;              
                $address = $this->addressRepository->findAddressById($billingAddressId);
                $country = $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2');
                if(!empty($address) && !empty($country) && in_array($country,$allowed_country_array)) {                             
                        return true;
                }
        
            }
        } catch(\Exception $e) {
            return false;
        }
        return false;
    }
    
    /**
     * Show payment for Minimum Order Amount
     *
     * @param object $basket
     * @param int $minimum_amount
     *
     * @return bool
     */
    public function getMinBasketAmount(Basket $basket, $minimum_amount) {   
        if (!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if (!empty($minimum_amount) && $minimum_amount<=$amount)    {
                return true;
            }
        } 
        return false;
    }
    
    /**
     * Show payment for Maximum Order Amount
     *
     * @param object $basket
     * @param int $maximum_amount
     *
     * @return bool
     */
    public function getMaxBasketAmount(Basket $basket, $maximum_amount) {   
        if (!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if (!empty($maximum_amount) && $maximum_amount>=$amount)    {
            
                return true;
            }
        } 
        return false;
    }
    
    /**
     * Get database values
     *
     * @param int $orderId
     *
     * @return array
     */
    public function getDatabaseValues($orderId) {
        $database = pluginApp(DataBase::class);
        $transaction_details = $database->query(TransactionLog::class)->where('orderNo', '=', $orderId)->get();
        if (!empty($transaction_details)) {
        //Typecasting object to array
        $transaction_details = (array)($transaction_details[0]);
        $transaction_details['order_no'] = $transaction_details['orderNo'];
        $transaction_details['amount'] = $transaction_details['amount'] / 100;
        //Decoding the json as array
        $transaction_details['additionalInfo'] = json_decode( $transaction_details['additionalInfo'], true );
        //Merging the array
        $transaction_details = array_merge($transaction_details, $transaction_details['additionalInfo']);
        //Unsetting the redundant key
        unset($transaction_details['additionalInfo']);
        return $transaction_details;
        }
    }
    
    /**
     * Send the payment call
     *
     */
	public function paymentCalltoNovalnetServer () {
		  
		$serverRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
		$serverRequestData['data']['order_no'] = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
		$response = $this->paymentHelper->executeCurl($serverRequestData['data'], $serverRequestData['url']);
		$responseData = $this->paymentHelper->convertStringToArray($response['response'], '&');
		$notificationMessage = $this->paymentHelper->getNovalnetStatusText($responseData);
		$responseData['payment_id'] = (!empty($responseData['payment_id'])) ? $responseData['payment_id'] : $responseData['key'];
		$isPaymentSuccess = isset($responseData['status']) && $responseData['status'] == '100';
		
		if($isPaymentSuccess)
		{           
			if(isset($serverRequestData['data']['pan_hash']))
			{
				unset($serverRequestData['data']['pan_hash']);
			}
			
			$this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($serverRequestData['data'], $responseData));
			$this->pushNotification($notificationMessage, 'success', 100);
			
		} else {
			$orderStatus = trim($this->config->get('Novalnet.novalnet_order_cancel_status'));
			$this->paymentHelper->updateOrderStatus((int)$responseData['order_no'], $orderStatus);
			$this->pushNotification($notificationMessage, 'error', 100);
		}
		  
	}

    /**
     * Build the additional params
     *
     * @param array $nnPaymentData
     *
     * @return array
     */
	public function additionalInfo ($nnPaymentData) {
		
	 $lang = strtolower((string)$nnPaymentData['lang']);
	 $additional_info = [
		'currency' => $nnPaymentData['currency'],
		'product_id' => !empty($nnPaymentData['product_id']) ? $nnPaymentData['product_id'] : $nnPaymentData['product'] ,
		'payment_id' => $nnPaymentData['payment_id'],
		'plugin_version' => $nnPaymentData['system_version'],
		'test_mode' => !empty($nnPaymentData['test_mode']) ? $this->paymentHelper->getTranslatedText('test_order',$lang) : '0',
		'invoice_type'      => !empty($nnPaymentData['invoice_type']) ? $nnPaymentData['invoice_type'] : '0' ,
		'invoice_account_holder' => !empty($nnPaymentData['invoice_account_holder']) ? $nnPaymentData['invoice_account_holder'] : '0' 
		];
	
	 return $additional_info;
	 
    }
    
}
