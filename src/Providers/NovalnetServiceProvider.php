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

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Order\Pdf\Events\OrderPdfGenerationEvent;
use Plenty\Modules\Order\Pdf\Models\OrderPdfGeneration;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Document\Models\Document;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Methods\NovalnetInvoicePaymentMethod;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Novalnet\Controllers\PaymentController;
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }
    public function boot( Dispatcher $eventDispatcher,
                          PaymentHelper $paymentHelper,
                          AddressRepositoryContract $addressRepository,
                          PaymentService $paymentService,
                          BasketRepositoryContract $basketRepository,
                          PaymentMethodContainer $payContainer,
                          PaymentMethodRepositoryContract $paymentMethodService,
                          FrontendSessionStorageFactoryContract $sessionStorage,
                          TransactionService $transactionLogData,
                          Twig $twig,
                          ConfigRepository $config,
                          PaymentRepositoryContract $paymentRepository,
                          DataBase $dataBase,
                          EventProceduresService $eventProceduresService)
{

$payContainer->register('plenty_novalnet::NOVALNET_INVOICE', NovalnetInvoicePaymentMethod::class,
    [
         AfterBasketChanged::class,
         AfterBasketItemAdd::class,
         AfterBasketCreate::class
    ]);
$eventDispatcher->listen(GetPaymentMethodContent::class,
function(GetPaymentMethodContent $event) use($config, $paymentHelper, $addressRepository, $paymentService, $basketRepository, $paymentMethodService, $sessionStorage, $twig)
{
  if($paymentHelper->getPaymentKeyByMop($event->getMop()))
    {   
      $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop()); 
      $guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);
      $basket = $basketRepository->load();            
      $billingAddressId = $basket->customerInvoiceAddressId;
      $address = $addressRepository->findAddressById($billingAddressId);
      foreach ($address->options as $option) 
        {
          if ($option->typeId == 12) 
            {
              $name = $option->value;
            }
          if ($option->typeId == 9) 
            {
              $birthday = $option->value;
            }
        }
        $customerName = explode(' ', $name);
        $firstname = $customerName[0];
        if( count( $customerName ) > 1 ) 
         {
            unset($customerName[0]);
            $lastname = implode(' ', $customerName);
         } 
         else 
         {
           $lastname = $firstname;
         }
           $firstName = empty ($firstname) ? $lastname : $firstname;
           $lastName = empty ($lastname) ? $firstname : $lastname;
           $endCustomerName = $firstName .' '. $lastName;
           $endUserName = $address->firstName .' '. $address->lastName;
           $name = trim($config->get('Novalnet.' . strtolower($paymentKey) . '_payment_name'));
           $paymentName = ($name ? $name : $paymentHelper->getTranslatedText(strtolower($paymentKey)));
            if(in_array($paymentKey, ['NOVALNET_INVOICE']))
             {
                  $processDirect = true;
                  $B2B_customer   = false;
                  if($paymentKey == 'NOVALNET_INVOICE')
                  {
                     $guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);
                     if($guaranteeStatus != 'normal' && $guaranteeStatus != 'guarantee')
                      {
                      $processDirect = false;
                      $contentType = 'errorCode';
                      $content = $guaranteeStatus;
                      }
                    else if($guaranteeStatus == 'guarantee')
                      {
                         $processDirect = false;
                         $paymentProcessUrl = $paymentService->getProcessPaymentUrl();
                         if (empty($address->companyName) &&  empty($birthday) )
                          {
                            $content = $twig->render('Novalnet::PaymentForm.NOVALNET_INVOICE', 
                            ['nnPaymentProcessUrl' => $paymentProcessUrl,
                             'paymentName' => $paymentName,  
                             'paymentMopKey'     =>  $paymentKey,
                             'guarantee_force' => trim($config->get('Novalnet.' .strtolower($paymentKey).'_payment_guarantee_force_active'))
                            ]);                                                 
                            $contentType = 'htmlContent';
                          } 
                          else 
                          {
                              $processDirect = true;
                              $B2B_customer  = true;
                          }
                      }
                  }
                }
 
if ($processDirect) 
   {
     $content = '';
     $contentType = 'continue';
     $serverRequestData = $paymentService->getRequestParameters($basketRepository->load(), $paymentKey);
     if (empty($serverRequestData['data']['first_name']) && empty($serverRequestData['data']['last_name'])) {
            $content = $paymentHelper->getTranslatedText('nn_first_last_name_error');
            $contentType = 'errorCode';   
        } 
    else 
        {   
          if( $B2B_customer) 
            {
               $serverRequestData['data']['payment_type'] = 'GUARANTEED_INVOICE';
               $serverRequestData['data']['key'] = '41';
               $serverRequestData['data']['birth_date'] = !empty($birthday) ? $birthday : '';
              if (empty($address->companyName) && time() < strtotime('+18 years', strtotime($birthday))) 
                {
                  $content = $paymentHelper->getTranslatedText('dobinvalid');
                  $contentType = 'errorCode';   
                } 
                elseif (!empty ($address->companyName) ) 
                  {
                    unset($serverRequestData['data']['birth_date']);
                  }

            }
           $sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData);
        }
   }
                             
$event->setValue($content);
$event->setType($contentType);
 }
 });

      
$eventDispatcher->listen(OrderPdfGenerationEvent::class,
function (OrderPdfGenerationEvent $event)use($dataBase,$paymentHelper,$paymentService,$paymentRepository, $transactionLogData) 
{
            
   $order = $event->getOrder();
   $payments = $paymentRepository->getPaymentsByOrderId($order->id);
   foreach ($payments as $payment)
      {
        $properties = $payment->properties;
        foreach($properties as $property)
        {
          if ($property->typeId == 21) 
          {
            $invoiceDetails = $property->value;
          }
          if ($property->typeId == 22)
          {
            $cashpayment_comments = $property->value;
          }
          if($property->typeId == 30)
          {
            $tid_status = $property->value;
          }
        }
      }
$paymentKey = $paymentHelper->getPaymentKeyByMop($payments[0]->mopId);
$db_details = $paymentService->getDatabaseValues($order->id);
$get_transaction_details = $transactionLogData->getTransactionData('orderNo', $order->id);
$totalCallbackAmount = 0;
foreach ($get_transaction_details as $transaction_details) 
{
  $totalCallbackAmount += $transaction_details->callbackAmount;
}
if(in_array($paymentKey, ['NOVALNET_INVOICE']) && !empty($db_details['plugin_version'])
        ) 
{
   try {
         $bank_details = array_merge($db_details, json_decode($invoiceDetails, true));
            
          $comments = '';
          $comments .= PHP_EOL . $paymentHelper->getTranslatedText('nn_tid') . $db_details['tid'];
          if(!empty($db_details['test_mode'])) 
          {
                    $comments .= PHP_EOL . $paymentHelper->getTranslatedText('test_order');
          }
          if(in_array($tid_status, ['91', '100']) && ($db_details['payment_id'] == '27' && ($transaction_details->amount > $totalCallbackAmount) || $db_details['payment_id'] == '41') ) 
          {
                $comments .= PHP_EOL . $paymentService->getInvoicePrepaymentComments($bank_details);
                
          }
          if($db_details['payment_id'] == '59' && ($transaction_details->amount > $totalCallbackAmount) && $tid_status == '100' ) 
          {
                $comments .= PHP_EOL . $cashpayment_comments;   
          }
          $orderPdfGenerationModel = pluginApp(OrderPdfGeneration::class);
          $orderPdfGenerationModel->advice = $paymentHelper->getTranslatedText('novalnet_details'). PHP_EOL . $comments;
          if ($event->getDocType() == Document::INVOICE) 
          {
                    $event->addOrderPdfGeneration($orderPdfGenerationModel); 
          }
        } 
      catch (\Exception $e) 
      {
          $this->getLogger(__METHOD__)->error('Adding PDF comment failed for order' . $order->id , $e);
      } 
   }
 });
 


    }
}
