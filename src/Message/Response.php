<?php

namespace Omnipay\SagePay\Message;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RedirectResponseInterface;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\SagePay\Traits\ResponseFieldsTrait;
use Omnipay\SagePay\ConstantsInterface;

/**
 * Sage Pay Response
 */
class Response extends AbstractResponse implements RedirectResponseInterface, ConstantsInterface
{
    use ResponseFieldsTrait;

    /**
     * FIXME: The response should never be directly passed the raw HTTP
     * body like this. The body should be parsed to data before instantiation.
     * However, the tests do not do that. I believe it is the tests that are broken,
     * but the tests are how the interface has been implemented so we cannot break
     * that for people who may rely on it.
     */
    public function __construct(RequestInterface $request, $data)
    {
        $this->request = $request;

        if (!is_array($data)) {
            // Split the data (string or guzzle body object) into lines.
            $lines = preg_split('/[\n\r]+/', (string)$data);

            $data = array();

            foreach ($lines as $line) {
                $line = explode('=', $line, 2);
                if (!empty($line[0])) {
                    $data[trim($line[0])] = isset($line[1]) ? trim($line[1]) : '';
                }
            }
        }

        $this->data = $data;
    }

    /**
     * CHECKME: should we include "OK REPEATED" as a successful status too?
     *
     * @return bool True if the transaction is successful and complete.
     */
    public function isSuccessful()
    {
        return $this->getStatus() === static::SAGEPAY_STATUS_OK;
    }

    /**
     * Gateway Reference
     *
     * Sage Pay requires the original VendorTxCode as well as 3 separate
     * fields from the response object to capture or refund transactions at a later date.
     *
     * Active Merchant solves this dilemma by returning the gateway reference in the following
     * custom format: VendorTxCode;VPSTxId;TxAuthNo;SecurityKey
     *
     * We have opted to return this reference as JSON, as the keys are much more explicit.
     *
     * @return string JSON formatted data.
     */
    public function getTransactionReference()
    {
        $reference = array();
        $reference['VendorTxCode'] = $this->getRequest()->getTransactionId();

        foreach (['SecurityKey', 'TxAuthNo', 'VPSTxId'] as $key) {
            $value = $this->{'get' . $key}();
            if ($value !== null) {
                $reference[$key] = $value;
            }
        }

        ksort($reference);

        return json_encode($reference);
    }

    /**
     * The only reason supported for a redirect from a Server transaction
     * will be 3D Secure. PayPal may come into this at some point.
     *
     * @return bool True if a 3DSecure Redirect needs to be performed.
     */
    public function isRedirect()
    {
        return $this->getStatus() === static::SAGEPAY_STATUS_3DAUTH;
    }

    /**
     * @return string URL to 3D Secure endpoint.
     */
    public function getRedirectUrl()
    {
        if ($this->isRedirect()) {
            return $this->getDataItem('ACSURL');
        }
    }

    /**
     * @return string The redirect method.
     */
    public function getRedirectMethod()
    {
        return 'POST';
    }

    /**
     * The usual reason for a redirect is for a 3D Secure check.
     * Note: when PayPal is supported, a different set of data will be returned.
     *
     * @return array Collected 3D Secure POST data.
     */
    public function getRedirectData()
    {
        if ($this->isRedirect()) {
            return array(
                'PaReq' => $this->getDataItem('PAReq'),
                'TermUrl' => $this->getRequest()->getReturnUrl(),
                'MD' => $this->getDataItem('MD'),
            );
        }
    }

    /**
     * The Sage Pay ID to uniquely identify the transaction on their system.
     * Only present if Status is OK or OK REPEATED.
     *
     * @return string
     */
    public function getVPSTxId()
    {
        return $this->getDataItem('VPSTxId');
    }

    /**
     * A secret used to sign the notification request sent direct to your
     * application.
     */
    public function getSecurityKey()
    {
        return $this->getDataItem('SecurityKey');
    }
}
