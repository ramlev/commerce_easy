<?php

namespace Drupal\commerce_easy;

interface ApiHelperInterface {

  /**
   * Set environment.
   *
   * @param string $environment
   *   Environment.
   */
  public function setEnvironment($environment);

  /**
   * Set secret key.
   *
   * @param string $secret_key
   *   Secret key.
   */
  public function setSecretKey($secret_key);

  /**
   * Retrieves payment.
   *
   * @param string $payment_id
   *   Payment ID.
   */
  public function getPayment($payment_id);

  /**
   * Updates payment.
   *
   * @param string $payment_id
   *   Payment ID.
   * @param array $payload
   *   Request payload.
   */
  public function updatePaymentItems($payment_id, array $payload);

  /**
   * Creates payment.
   *
   * @param array $payload
   *   Request payload.
   */
  public function createPayment(array $payload);

  /**
   * Capture payment.
   *
   * @param string $payment_id
   *   Payment ID.
   * @param array $payload
   *   Request payload.
   */
  public function chargesPayment($payment_id, array $payload);

  /**
   * Cancel payment.
   *
   * @param string $payment_id
   *   Payment ID.
   * @param array $payload
   *   Request payload.
   */
  public function cancelPayment($payment_id, array $payload);

}
