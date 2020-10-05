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
 * All rights reserved. https://www.Novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

/*** Class NovalnetRouteServiceProvider*/
class NovalnetRouteServiceProvider extends RouteServiceProvider
{
    /*** Set route for success, failure and callback process*/
    public function map(Router $router)
    {
        // Get the Novalnet success, cancellation and callback URLs
        $router->get('payment/Novalnet/redirectPayment', 'Novalnet\Controllers\PaymentController@redirectPayment');
        $router->post('payment/Novalnet/processPayment', 'Novalnet\Controllers\PaymentController@processPayment');
        $router->post('payment/Novalnet/paymentResponse', 'Novalnet\Controllers\PaymentController@paymentResponse');
    }
}
