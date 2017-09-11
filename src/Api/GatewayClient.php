<?php

namespace Webgriffe\LibMonetaWebDue\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ServerRequestInterface;
use Webgriffe\LibMonetaWebDue\PaymentInit\UrlGenerator;
use Webgriffe\LibMonetaWebDue\PaymentNotification\Mapper;
use Webgriffe\LibMonetaWebDue\PaymentNotification\Result\PaymentResultInfo;
use Webgriffe\LibMonetaWebDue\PaymentNotification\Result\PaymentResultInterface;

class GatewayClient
{
    /** @var ClientInterface $client */
    private $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param string $gatewayBaseUrl
     * @param string $terminalId
     * @param string $terminalPassword
     * @param float $amount
     * @param string $currencyCode
     * @param string $language
     * @param string $responseToMerchantUrl
     * @param string|null $recoveryUrl
     * @param string $orderId
     * @param string|null $paymentDescription
     * @param string|null $cardHolderName
     * @param string|null $cardholderEmail
     * @param string|null $customField
     * @return GatewayPageInfo
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     */
    public function getPaymentPageInfo(
        $gatewayBaseUrl,
        $terminalId,
        $terminalPassword,
        $amount,
        $currencyCode,
        $language,
        $responseToMerchantUrl,
        $recoveryUrl,
        $orderId,
        $paymentDescription = null,
        $cardHolderName = null,
        $cardholderEmail = null,
        $customField = null
    ) {
        $urlGenerator = new UrlGenerator();
        $paymentInitUrl = $urlGenerator->generate(
            $gatewayBaseUrl,
            $terminalId,
            $terminalPassword,
            $amount,
            $currencyCode,
            $language,
            $responseToMerchantUrl,
            $recoveryUrl,
            $orderId,
            $paymentDescription,
            $cardHolderName,
            $cardholderEmail,
            $customField
        );

        $request = new Request('POST', $paymentInitUrl);
        $response = $this->client->send($request);

        $parsedResponseBody = simplexml_load_string($response->getBody());
        // todo: log request and response
        // todo: it could throw a custom exception that encapsulate the error code and message
        if (isset($parsedResponseBody->errorcode) || isset($parsedResponseBody->errormessage)) {
            throw new \RuntimeException(
                sprintf(
                    'The request sent to MonetaWebDue gateway generated an error with code "%s" and message: %s',
                    $parsedResponseBody->errorCode,
                    $parsedResponseBody->errorMessage
                )
            );
        }

        $hostedPageUrl = (string)$parsedResponseBody->hostedpageurl;
        $paymentId = (string)$parsedResponseBody->paymentid;
        $securityToken = (string)$parsedResponseBody->securitytoken;
        $hostedPageUrl .= (parse_url($hostedPageUrl, PHP_URL_QUERY) ? '&' : '?') . 'paymentid=' . $paymentId;

        return new GatewayPageInfo($hostedPageUrl, $securityToken, $paymentId);
    }

    /**
     * @param ServerRequestInterface $request
     * @return PaymentResultInterface
     */
    public function handleNotify(ServerRequestInterface $request)
    {
        $mapper = new Mapper();
        return $mapper->map($request);
    }

    /**
     * @param string $storedSecurityToken
     * @param PaymentResultInfo $paymentResult
     * @return bool
     */
    public function verifySecurityToken($storedSecurityToken, PaymentResultInfo $paymentResult)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($storedSecurityToken, $paymentResult->getSecurityToken());
        }
        return strcmp($storedSecurityToken, $paymentResult->getSecurityToken()) === 0;
    }
}
