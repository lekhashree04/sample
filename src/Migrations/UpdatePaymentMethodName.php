<?php
/**
 * This file is used for creating Novalnet payment mehtods
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.Novalnet.de/payment-plugins/kostenlos/lizenz
 */
 
namespace Novalnet\Migrations;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Novalnet\Helper\PaymentHelper;

/**
 * Migration to create payment mehtods
 *
 * Class UpdatePaymentMethodName
 *
 * @package Novalnet\Migrations
 */
class UpdatePaymentMethodName
{
    /**
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepository;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * CreatePaymentMethod constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepository
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository,
                                PaymentHelper $paymentHelper)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Run on plugin build
     *
     * Create Method of Payment ID for Novalnet payment if they don't exist
     */
    public function run()
    {
        $this->createNovalnetPaymentMethodByPaymentKey('Novalnet_INVOICE', 'Novalnet Invoice');
    }


    /**
     * Create payment method with given parameters if it doesn't exist
     *
     * @param string $paymentKey
     * @param string $name
     */
    private function createNovalnetPaymentMethodByPaymentKey($paymentKey, $name)
    {
        $payment_data = $this->paymentHelper->getPaymentMethodByKey($paymentKey);
        if ($payment_data == 'no_paymentmethod_found')
        {
          $paymentMethodData = ['pluginKey'  => 'plenty_Novalnet',
                                'paymentKey' => $paymentKey,
                                'name'       => $name
                               ];
            $this->paymentMethodRepository->createPaymentMethod($paymentMethodData);
        } elseif ($payment_data[1] == $paymentKey && !in_array ($payment_data[2], ['Novalnet Invoice']) ) {
          $paymentMethodData = ['pluginKey'  => 'plenty_Novalnet',
                                'paymentKey' => $paymentKey,
                                'name'       => $name,
                                'id'         => $payment_data[0]
                               ];
            $this->paymentMethodRepository->updateName($paymentMethodData);
        }
    }
}
