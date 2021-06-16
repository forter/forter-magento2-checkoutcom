<?php

namespace Forter\Checkoutcom\Plugin\Model\RequestBuilder;

use CheckoutCom\Magento2\Model\ResourceModel\WebhookEntity\Collection as checkoutComCollection;
use Forter\Forter\Model\AbstractApi;
use Forter\Forter\Model\Config;
use Forter\Forter\Model\RequestBuilder\Order as RequestBuilderOrder;

class Order
{

  /**
   * @var Collection
   */
    public $checkoutComCollection;

    /**
      * @var AbstractApi
      */
    protected $abstractApi;

    /**
     * Order Plugin constructor.
     * @param Config $forterConfig
     */
    public function __construct(
        Config $forterConfig,
        AbstractApi $abstractApi,
        checkoutComCollection $checkoutComCollection
    ) {
        $this->_checkoutComCollection = $checkoutComCollection;
        $this->forterConfig = $forterConfig;
        $this->abstractApi = $abstractApi;
    }

    /**
     * @param RequestBuilderOrder $subject
     * @param callable $proceed
     * @param $order
     * @param $orderStage
     * @return string
     */
    public function aroundBuildTransaction(RequestBuilderOrder $subject, callable $proceed, $order, $orderStage)
    {
        try {
            if (!$this->forterConfig->isEnabled()) {
                $result = $proceed($order, $orderStage);
                return $result;
            }

            $result = $proceed($order, $orderStage);

            if ($result['payment'][0]['paymentMethodNickname'] != 'checkoutcom_card_payment') {
                return $result;
            }

            $collection = $this->_checkoutComCollection;
            $collection->addFilter('order_id', $result['additionalIdentifiers']['additionalOrderId'], 'eq');
            $collection->addFilter('event_type', 'payment_approved', 'eq');

            if ($collection->getSize() < 1) {
                return $result;
            }

            $paymentCheckoutCom = $collection->getLastItem();
            $paymentCheckoutCom = $paymentCheckoutCom->getEventData();
            $paymentCheckoutCom = json_decode($paymentCheckoutCom, true);
            $expiryMonth = strval($paymentCheckoutCom['data']['source']['expiry_month']);
            $result['payment'][0]['creditCard'] = [
                                      "nameOnCard" => $paymentCheckoutCom['data']['source']['name'] ? $paymentCheckoutCom['data']['source']['name'] : "",
                                      "cardBrand" => $paymentCheckoutCom['data']['source']['scheme'] ? $paymentCheckoutCom['data']['source']['scheme'] : "",
                                      "bin" => $paymentCheckoutCom['data']['source']['bin'] ? $paymentCheckoutCom['data']['source']['bin'] : "",
                                      "countryOfIssuance" => $paymentCheckoutCom['data']['source']['issuer_country'] ? $paymentCheckoutCom['data']['source']['issuer_country'] : "",
                                      "cardBank" => $paymentCheckoutCom['data']['source']['issuer'] ? $paymentCheckoutCom['data']['source']['issuer'] : "",
                                      "verificationResults" => [
                                        "cvvResult" => $paymentCheckoutCom['data']['source']['cvv_check'] ? $paymentCheckoutCom['data']['source']['cvv_check'] : "",
                                        "authorizationCode" => $paymentCheckoutCom['data']['auth_code'] ? $paymentCheckoutCom['data']['auth_code'] : "",
                                        "processorResponseCode" => $paymentCheckoutCom['data']['response_code'] ? $paymentCheckoutCom['data']['auth_code'] : "" ,
                                        "processorResponseText" => $paymentCheckoutCom['data']['response_summary'] ? $paymentCheckoutCom['data']['response_summary'] : "",
                                        "avsStreetResult" => '',
                                        "avsZipResult" => '',
                                        "avsFullResult" => $paymentCheckoutCom['data']['source']['avs_check'] ? $paymentCheckoutCom['data']['source']['avs_check'] : ""
                                      ],
                                      "paymentGatewayData" => [
                                        "gatewayName" => $paymentCheckoutCom['data']['metadata']['methodId'] ? $paymentCheckoutCom['data']['metadata']['methodId'] : "",
                                        "gatewayTransactionId" => $paymentCheckoutCom['data']['processing']['acquirer_transaction_id'] ? $paymentCheckoutCom['data']['processing']['acquirer_transaction_id'] : ""
                                      ],
                                      "fullResponsePayload" => $paymentCheckoutCom ? $paymentCheckoutCom : "",
                                      "expirationMonth" => strlen($expiryMonth) == 1 ? '0' . $expiryMonth : $expiryMonth,
                                      "expirationYear" => strval($paymentCheckoutCom['data']['source']['expiry_year']),
                                      "lastFourDigits" => $paymentCheckoutCom['data']['source']['last_4']
                                    ];

            return $result;
        } catch (Exception $e) {
            $this->abstractApi->reportToForterOnCatch($e);
        }

        return;
    }
}
