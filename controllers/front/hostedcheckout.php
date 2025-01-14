<?php
/**
 * Copyright (c) On Tap Networks Limited.
 */

require_once dirname(__FILE__) . '/abstract.php';

class MastercardHostedCheckoutModuleFrontController extends MastercardAbstractModuleFrontController
{
    /**
     * @throws GatewayResponseException
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    protected function createSessionAndRedirect()
    {
        $orderId = $this->module->getNewOrderRef();

        $order = array(
            'id' => $orderId,
            'currency' => Context::getContext()->currency->iso_code,
            'amount' => GatewayService::numeric(
                Context::getContext()->cart->getOrderTotal()
            ),
            'item' => $this->module->getOrderItems(),
            'itemAmount' => $this->module->getItemAmount(),
            'shippingAndHandlingAmount' => $this->module->getShippingHandlingAmount(),
        );

        $interaction = array(
            'theme' => GatewayService::safe(Configuration::get('mpgs_hc_theme')),
            'displayControl' => array(
                'shipping' => 'HIDE',
                'billingAddress' => GatewayService::safe(Configuration::get('mpgs_hc_show_billing')),
                'customerEmail' => GatewayService::safe(Configuration::get('mpgs_hc_show_email')),
                'orderSummary' => GatewayService::safe(Configuration::get('mpgs_hc_show_summary')),
            ),
            'googleAnalytics' => array(
                'propertyId' => Configuration::get('mpgs_hc_ga_tracking_id')?:null,
            ),
            'merchant' => array(
                'name' => GatewayService::safe(Context::getContext()->shop->name, 40),
            )
        );

        $address = new Address(Context::getContext()->cart->id_address_invoice);
        $country = new Country($address->id_country);
        $billing = array(
            'address' => array(
                'city' => GatewayService::safe($address->city, 100),
                'country' => $this->module->iso2ToIso3($country->iso_code),
                'postcodeZip' => GatewayService::safe($address->postcode, 10),
                'street' => GatewayService::safe($address->address1, 100),
                'street2' => GatewayService::safe($address->address2, 100),
            )
        );

        $customer = array(
            'email' => GatewayService::safe(Context::getContext()->customer->email),
            'firstName' => GatewayService::safe(Context::getContext()->customer->firstname, 50),
            'lastName' => GatewayService::safe(Context::getContext()->customer->lastname, 50),
        );

        $response = $this->client->createCheckoutSession(
            $order,
            $interaction,
            $customer,
            $billing
        );

        $responseData = array(
            'session_id' => $response['session']['id'],
            'session_version' => $response['session']['version'],
            'success_indicator' => $response['successIndicator'],
        );

        if (ControllerCore::isXmlHttpRequest()) {
            header('Content-Type: application/json');
            exit(json_encode($responseData));
        }

        Tools::redirect(
            Context::getContext()->link->getModuleLink('mastercard', 'hostedcheckout', $responseData)
        );
    }

    /**
     * @throws PrestaShopException
     * @throws Exception
     */
    protected function showPaymentPage()
    {
        $this->context->smarty->assign(array(
            'mpgs_config' => array(
                'session_id' => Tools::getValue('session_id'),
                'session_version' => Tools::getValue('session_version'),
                'success_indicator' => Tools::getValue('success_indicator'),
                'merchant_id' => $this->module->getConfigValue('mpgs_merchant_id'),
                'order_id' => $this->module->getNewOrderRef(),
                'amount' => Context::getContext()->cart->getOrderTotal(),
                'currency' => Context::getContext()->currency->iso_code
            ),
            'hostedcheckout_component_url' => $this->module->getHostedCheckoutJsComponent(),
        ));
        $this->setTemplate('module:mastercard/views/templates/front/methods/hostedcheckout/js.tpl');
    }

    /**
     * @throws \Http\Client\Exception
     * @throws PrestaShopException
     * @throws Exception
     */
    protected function createOrderAndRedirect()
    {
        $orderId = Tools::getValue('order_id');
        $cart = Context::getContext()->cart;
        $currency = Context::getContext()->currency;

        if ($orderId !== $this->module->getNewOrderRef()) {
            $this->errors[] = $this->module->l('Invalid data (order)', 'hostedcheckout');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->errors[] = $this->module->l('Invalid data (customer)', 'hostedcheckout');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        $response = $this->client->retrieveOrder($orderId);

        $this->module->validateOrder(
            (int)$cart->id,
            Configuration::get('MPGS_OS_PAYMENT_WAITING'),
            $response['amount'],
            MasterCard::PAYMENT_CODE,
            null,
            array(),
            $currency->id,
            false,
            $customer->secure_key
        );

        $order = new Order((int)$this->module->currentOrder);

        $processor = new ResponseProcessor($this->module);

        try {
            $processor->handle($order, $response, array(
                new RiskResponseHandler(),
                new OrderPaymentResponseHandler(),
                new OrderStatusResponseHandler(),
            ));
        } catch (Exception $e) {
            $this->errors[] = $this->module->l('Payment Error', 'hostedcheckout');
            $this->errors[] = $e->getMessage();
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        Tools::redirect(
            'index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key
        );
    }

    /**
     * @throws GatewayResponseException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    public function postProcess()
    {
        if (parent::postProcess()) {
            return;
        }

        if (Tools::getValue('cancel')) {
            $this->warning[] = $this->module->l('Payment was cancelled.', 'hostedcheckout');
            $this->redirectWithNotifications(Context::getContext()->link->getPageLink('cart', null, null, array(
                'action' => 'show'
            )));
            exit;
        }

        if (!Tools::getValue('order_id')) {
            if (!Tools::getValue('success_indicator')) {
                $this->createSessionAndRedirect();
            } else {
                $this->showPaymentPage();
            }
        } else {
            $this->createOrderAndRedirect();
        }
    }
}
