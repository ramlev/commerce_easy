<?php

namespace Drupal\commerce_easy\PluginForm\OffsiteRedirect;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class EasyHostedPaymentPage extends PaymentOffsiteForm {

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $payment_gateway = $payment->getPaymentGateway();
    $payment_gateway_config = $payment_gateway->getPluginConfiguration();
    $order = $payment->getOrder();

    /** @var \Drupal\commerce_easy\OrderBuilder $order_builder */
    $order_builder = \Drupal::service('commerce_easy.order_builder');
    $easy_order = $order_builder->buildOrder($order);
    $easy_order['checkout']['returnUrl'] = $form['#return_url'];
    $easy_order['checkout']['cancelUrl'] = $form['#cancel_url'];
    $easy_order['checkout']['integrationType'] = 'HostedPaymentPage';
    $easy_order['checkout']['merchantHandlesConsumerData'] = TRUE;

    if ($form['#capture']) {
      $easy_order['checkout']['charge'] = TRUE;
    }

    $terms_path = $payment_gateway_config['terms_path'] ?? '/';
    $terms_uri = UrlHelper::isExternal($terms_path) ? Url::fromUri($terms_path) : Url::fromUserInput('/' . ltrim($terms_path, '/'), ['absolute' => TRUE]);
    $easy_order['checkout']['termsUrl'] = $terms_uri->toString();

    if (!isset($payment_gateway_config['enable_notification_webhook']) || $payment_gateway_config['enable_notification_webhook'] === TRUE) {
      $order->setData('easy_authorization', uniqid());
      $easy_order['notifications']['webhooks'][] = [
        'eventName' => 'payment.checkout.completed',
        'url' => Url::fromRoute('commerce_payment.notify',
          ['commerce_payment_gateway' => $payment_gateway->id()])
          ->setAbsolute(TRUE)
          ->toString(),
        'authorization' => $order->getData('easy_authorization'),
      ];

      // Save authorization but skip the order processors.
      $order->setRefreshState(OrderInterface::REFRESH_SKIP);
      $order->save();
    }

    /** @var \Drupal\commerce_easy\ApiHelper $apiHelper */
    $apiHelper = \Drupal::service('commerce_easy.api_helper');
    $apiHelper->setEnvironment($payment_gateway_config['mode']);
    $apiHelper->setSecretKey($payment_gateway_config['secret_key']);

    try {
      $result = $apiHelper->createPayment($easy_order);
    }
    catch (\Exception $exception) {
      throw new PaymentGatewayException($exception->getMessage());
    }
    if (strpos($result['hostedPaymentPageUrl'],'?') !== FALSE) {
      $result['hostedPaymentPageUrl'] .= '&language=' . ($payment_gateway_config['langcode'] ?? 'en-GB');
    } else {
      $result['hostedPaymentPageUrl'] .= '?language=' . ($payment_gateway_config['langcode'] ?? 'en-GB');
    }
    return $this->buildRedirectForm($form, $form_state, $result['hostedPaymentPageUrl'], []);
  }

}
