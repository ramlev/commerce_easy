<?php

namespace Drupal\commerce_easy;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

class ApiHelper implements ApiHelperInterface {

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * @var string
   */
  protected $environment;

  /**
   * @var string
   */
  protected $secret_key;

  const TEST_API_URL = 'https://test.api.dibspayment.eu/';
  const PROD_API_URL = 'https://api.dibspayment.eu/';

  public function __construct(ClientInterface $client) {
    $this->httpClient = $client;
    $this->environment = 'test';
  }

  /**
   * {@inheritdoc}
   */
  public function setEnvironment($environment) {
    $this->environment = $environment;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setSecretKey($secret_key) {
    $this->secret_key = $secret_key;
    return $this;
  }

  /**
   * Default client options.
   *
   * @return array
   */
  protected function getDefaultClientOptions() {
    return [
      'base_uri' => $this->environment == 'test' ? static::TEST_API_URL : static::PROD_API_URL,
      RequestOptions::HEADERS => [
        'Authorization' => $this->secret_key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'commercePlatformTag' => 'DrupalCommerce',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPayment($payment_id) {
    $response = $this->httpClient->get('/v1/payments/' . $payment_id, [
      RequestOptions::HTTP_ERRORS => FALSE,
    ] + $this->getDefaultClientOptions());
    $content = $response->getBody()->getContents();
    return Json::decode($content);
  }

  /**
   * {@inheritdoc}
   */
  public function updatePaymentItems($payment_id, array $payload) {
    $response = $this->httpClient->put('/v1/payments/' . $payment_id . '/orderitems', [
      RequestOptions::JSON => $payload,
    ] + $this->getDefaultClientOptions());
    $content = $response->getBody()->getContents();
    return Json::decode($content);
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(array $payload) {
    $response = $this->httpClient->post('/v1/payments', [
      RequestOptions::JSON => $payload,
    ] + $this->getDefaultClientOptions());
    $content = $response->getBody()->getContents();
    return Json::decode($content);
  }

  /**
   * {@inheritdoc}
   */
  public function chargesPayment($payment_id, array $payload) {
    $response = $this->httpClient->post('/v1/payments/' . $payment_id . '/charges', [
      RequestOptions::JSON => $payload,
    ] + $this->getDefaultClientOptions());
    $content = $response->getBody()->getContents();
    return Json::decode($content);
  }

  /**
   * {@inheritdoc}
   */
  public function cancelPayment($payment_id, array $payload) {
    $response = $this->httpClient->post('/v1/payments/' . $payment_id . '/cancels', [
      RequestOptions::JSON => $payload,
    ] + $this->getDefaultClientOptions());
    $content = $response->getBody()->getContents();
    return Json::decode($content);
  }

}
