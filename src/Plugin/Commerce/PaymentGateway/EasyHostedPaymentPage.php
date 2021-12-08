<?php

namespace Drupal\commerce_easy\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_easy\ApiHelperInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Exception\SoftDeclineException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_easy_hosted_payment_page",
 *   label = "Nets Easy - Hosted Payment Page",
 *   display_label = @Translation("Pay with Nets Easy"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_easy\PluginForm\OffsiteRedirect\EasyHostedPaymentPage",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class EasyHostedPaymentPage extends OffsitePaymentGatewayBase implements SupportsAuthorizationsInterface, SupportsNotificationsInterface {

  /**
   * The lock service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The channel logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The API helper.
   *
   * @var \Drupal\commerce_easy\ApiHelperInterface
   */
  protected $apiHelper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $object = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $object->setLock($container->get('lock'));
    $object->setLogger($container->get('logger.channel.commerce_easy'));
    $object->setApiHelper($container->get('commerce_easy.api_helper'));
    return $object;
  }

  /**
   * Sets the lock service.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock service.
   *
   * @return $this
   *   Instance of this class.
   */
  public function setLock(LockBackendInterface $lock) {
    $this->lock = $lock;
    return $this;
  }

  /**
   * Sets the logger service.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The channel logger.
   *
   * @return $this
   *   Instance of this class.
   */
  public function setLogger(LoggerInterface $logger) {
    $this->logger = $logger;
    return $this;
  }

  /**
   * Sets the API helper.
   *
   * @param \Drupal\commerce_easy\ApiHelperInterface $apiHelper
   *   The API helper.
   *
   * @return $this
   */
  public function setApiHelper(ApiHelperInterface $apiHelper) {
    $this->apiHelper = $apiHelper;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'secret_key' => '',
      'terms_path' => '',
      'langcode' => 'en-GB',
      'enable_notification_webhook' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret Key'),
      '#description' => $this->t('You can find the secretKey and checkoutKey for the test and live environment in the Easy administration'),
      '#default_value' => $this->configuration['secret_key'],
      '#required' => TRUE,
    ];
    $form['terms_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to terms and conditions page'),
      '#default_value' => $this->configuration['terms_path'],
      '#required' => TRUE,
    ];
    $form['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#default_value' => $this->configuration['langcode'],
      '#options' => [
        'en-GB' => $this->t('English'),
        'da-DK' => $this->t('Danish'),
        'sv-SE' => $this->t('Swedish'),
        'nb-NO' => $this->t('Norwegian'),
        'de-DE' => $this->t('German'),
        'pl-PL' => $this->t('Polish'),
        'fr-FR' => $this->t('French'),
        'es-ES' => $this->t('Spanish'),
        'nl-NL' => $this->t('Dutch'),
        'fi-FI' => $this->t('Finnish'),
      ],
      '#required' => TRUE,
    ];
    $form['enable_notification_webhook'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable notification webhook'),
      '#descripton' => $this->t('It is recommended to keep it enabled in production environment, as some payment options available in Easy may cause that user never returns to this website (ex. Vipps, Swish, MobilePay). For development environment it is recommended to keep this option disabled.'),
      '#default_value' => $this->configuration['enable_notification_webhook'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['secret_key'] = $values['secret_key'];
      $this->configuration['terms_path'] = $values['terms_path'];
      $this->configuration['langcode'] = $values['langcode'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $webhook = Json::decode($request->getContent());
    if (empty($webhook['data']['paymentId'])) {
      $this->logger->error('Missing payment ID', ['@context' => $webhook]);
      return new Response('Payment ID is missing', Response::HTTP_FORBIDDEN);
    }
    $remoteId = $webhook['data']['paymentId'];
    if (empty($webhook['event'])) {
      $this->logger->error('Missing event type', ['@context' => $webhook]);
      return new Response('Event type is missing', Response::HTTP_FORBIDDEN);
    }
    $paymentGatewayId = $this->parentEntity->id();
    $lockId = $paymentGatewayId . '__' . $remoteId;
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $paymentStorage */
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

    if (!$order_id = $request->query->get('order')) {
      $this->logger->error('Missing order ID context @context', ['@context' => $request->getUri()]);
      return new Response('Order ID is missing', Response::HTTP_FORBIDDEN);
    }

    // Keep checking if lock could be acquired for 5 seconds, then start over.
    while ($this->lock->wait($lockId, 5) || !$this->lock->acquire($lockId)) {
      // Looks like lock cannot be acquired, hold for one second and retry.
      sleep(1);
      $this->logger->notice('Waiting for lock @lock to be released', ['@lock' => $lockId]);
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    if (!$order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id)) {
      $this->lock->release($lockId);
      $this->logger->error('Order reference is incorrect; could not locate order @order', ['@order' => $order_id]);
      return new Response('Order reference is incorrect', Response::HTTP_FORBIDDEN);
    }

    // Compare authorization stored on order with authorization passed over.
    $authorization = $request->headers->get('Authorization');
    $expectedAuthorization = $order->getData('easy_authorization');
    if ($expectedAuthorization !== $authorization) {
      $this->lock->release($lockId);
      $this->logger->error('Authorization header mismatch for order @order: expected @expected provided @provided', [
        '@order' => $order->id(),
        '@expected' => $expectedAuthorization,
        '@provided' => $authorization,
      ]);
      return new Response('Authorization header mismatch', Response::HTTP_FORBIDDEN);
    }

    // Stop if payment already exists.
    $payment = $paymentStorage->loadByRemoteId($remoteId);

    switch ($webhook['event']) {
      case 'payment.reservation.created.v2':
        if ($payment instanceof PaymentInterface) {
          $this->lock->release($lockId);
          $this->logger->notice('Payment @remote_id already exists, skipping.',
            ['@remote_id' => $remoteId]);
          return new Response('OK', Response::HTTP_OK);
        }
        $state = 'authorization';
        $time = $webhook['timestamp'] ? strtotime($webhook['timestamp']) : time();
        $amount = $this->minorUnitsConverter->fromMinorUnits($webhook['data']['amount']['amount'], $webhook['data']['amount']['currency']);
        break;

      case 'payment.charge.created.v2':
        if ($payment instanceof PaymentInterface && $payment->isCompleted()) {
          $this->lock->release($lockId);
          $this->logger->notice('Payment @remote_id exists, and it has already been completed, skipping.',
            ['@remote_id' => $remoteId]);
          return new Response('OK', Response::HTTP_OK);
        }
        $state = 'completed';
        $time = $webhook['timestamp'] ? strtotime($webhook['timestamp']) : time();
        $amount = $this->minorUnitsConverter->fromMinorUnits($webhook['data']['amount']['amount'], $webhook['data']['amount']['currency']);
        break;

      default:
        $this->lock->release($lockId);
        $this->logger->error('Unsupported event @event',
          ['@event' => $webhook['event']]);
        return new Response(sprintf('Unsupported event %s', $webhook['event']), Response::HTTP_FORBIDDEN);

    }

    if ($payment instanceof PaymentInterface) {
      $payment->setAmount($amount);
      $payment->setState($state);
    }
    else {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = $paymentStorage->create([
        'state' => $state,
        'amount' => $amount,
        'payment_gateway' => $paymentGatewayId,
        'order_id' => $order->id(),
        'remote_state' => 'OK',
        'remote_id' => $remoteId,
      ]);
    }
    if ($state === 'authorization') {
      $payment->setAuthorizedTime($time);
    }
    elseif ($state === 'completed') {
      $payment->setAuthorizedTime($time);
      $payment->setCompletedTime($time);
    }
    $payment->save();
    $this->lock->release($lockId);
    return new Response('OK', Response::HTTP_OK);
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $remoteId = $request->query->get('paymentid');
    $paymentGatewayId = $this->parentEntity->id();
    $lockId = $paymentGatewayId . '__' . $remoteId;

    // Keep checking if lock could be acquired for 5 seconds, then start over.
    while ($this->lock->wait($lockId, 5) || !$this->lock->acquire($lockId)) {
      // Looks like lock cannot be acquired, hold for one second and retry.
      sleep(1);
      $this->logger->notice('Waiting for lock @lock to be released', ['@lock' => $lockId]);
    }

    $this->apiHelper->setEnvironment($this->configuration['mode']);
    $this->apiHelper->setSecretKey($this->configuration['secret_key']);
    $easy_payment = $this->apiHelper->getPayment($remoteId);
    if (!isset($easy_payment['payment']['summary']['reservedAmount'])) {
      $this->lock->release($lockId);
      $this->logger->error('Reserved amount property is missing', ['@context' => $easy_payment]);
      throw new PaymentGatewayException('Reserved amount property is missing');
    }

    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

    // Stop if payment already exists.
    if (!$paymentStorage->loadByRemoteId($remoteId)) {
      $state = 'authorization';
      $amount = (new Price($easy_payment['payment']['summary']['reservedAmount'], $order->getTotalPrice()->getCurrencyCode()))->divide(100);

      // The payment was directly captured.
      if (isset($easy_payment['payment']['summary']['chargedAmount'])) {
        $state = 'completed';
        $amount = (new Price($easy_payment['payment']['summary']['chargedAmount'], $order->getTotalPrice()->getCurrencyCode()))->divide(100);
      }

      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = $paymentStorage->create([
        'state' => $state,
        'amount' => $amount,
        'payment_gateway' => $paymentGatewayId,
        'order_id' => $order->id(),
        'remote_state' => 'OK',
        'remote_id' => $remoteId,
      ]);
      if ($state === 'authorization') {
        $payment->setAuthorizedTime(time());
      }
      elseif ($state === 'completed') {
        $payment->setAuthorizedTime(time());
        $payment->setCompletedTime(time());
      }
      $payment->save();
    }
    else {
      $this->logger->notice('Payment @remote_id already exists, skipping.', ['@remote_id' => $remoteId]);
    }

    $this->lock->release($lockId);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {

    $this->assertPaymentState($payment, ['authorization']);

    if ($amount->lessThan($payment->getAmount())) {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $parent_payment */
      $parent_payment = $payment;
      $payment = $parent_payment->createDuplicate();
    }

    $this->apiHelper->setEnvironment($this->configuration['mode']);
    $this->apiHelper->setSecretKey($this->configuration['secret_key']);

    $payload = ['amount' => $amount->multiply(100)->getNumber()];
    if (isset($amount) && $payment->getBalance()->compareTo($amount) !== 0) {
      $payload['orderItems'][] = [
        'reference' => 'partial_capture_workaround_reference',
        'name' => 'partial_capture_workaround_reference',
        'quantity' => 1,
        'unit' => '1',
        'unitPrice' => 0,
        'taxRate' => 0,
        'taxAmount' => 0,
        'grossTotalAmount' => $payload['amount'],
        'netTotalAmount' => $payload['amount'],
      ];
    }
    try {
      $this->apiHelper->chargesPayment($payment->getRemoteId(), $payload);
    }
    catch (\Exception $exception) {
      throw new SoftDeclineException($exception->getMessage());
    }
    $payment->setAmount($amount);
    $payment->setCompletedTime(\Drupal::service('datetime.time')->getCurrentTime());
    $payment->setState('completed');
    $payment->save();

    // Update parent payment if one exists.
    if (isset($parent_payment)) {
      $parent_payment->setAmount($parent_payment->getAmount()->subtract($amount));
      if ($parent_payment->getAmount()->isZero()) {
        $parent_payment->setState('authorization_voided');
      }
      $parent_payment->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {

    $this->assertPaymentState($payment, ['authorization']);

    $this->apiHelper->setEnvironment($this->configuration['mode']);
    $this->apiHelper->setSecretKey($this->configuration['secret_key']);

    $payload = ['amount' => $payment->getAmount()->multiply(100)->getNumber()];
    try {
      $this->apiHelper->cancelPayment($payment->getRemoteId(), $payload);
    }
    catch (\Exception $exception) {
      throw new SoftDeclineException($exception->getMessage());
    }
    $payment->setState('authorization_voided');
    $payment->save();
  }

}
