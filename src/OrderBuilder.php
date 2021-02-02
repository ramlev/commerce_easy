<?php

namespace Drupal\commerce_easy;

use CommerceGuys\Intl\Calculator;
use Drupal\commerce_easy\Event\BuildOrderEvent;
use Drupal\commerce_easy\Event\EasyEvents;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class OrderBuilder {

  use StringTranslationTrait;

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcher
   */
  protected $eventDispatcher;

  /**
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $priceRounder;

  public function __construct(EventDispatcherInterface $eventDispatcher, RounderInterface $priceRounder) {
    $this->eventDispatcher = $eventDispatcher;
    $this->priceRounder = $priceRounder;
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *
   * @return array
   */
  public function buildOrder(OrderInterface $order) {
    $easy_order = [
      'order' => [
        'items' => [],
        'amount' => $this->toMinorUnits($order->getTotalPrice()),
        'currency' => $order->getTotalPrice()->getCurrencyCode(),
        'reference' => $order->id(),
      ],
    ];

    if ($order->getEmail()) {
      $easy_order['checkout']['consumer']['email'] = $order->getEmail();
    }

    if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
      $shipments = $order->get('shipments');
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      $shipment = $shipments->first()->entity;
      $profile = $shipment->getShippingProfile();
      $easy_order['checkout']['consumer']['reference'] = $profile->id();
      $easy_order['checkout']['consumer']['shippingAddress']['addressLine1'] = $profile->address->address_line1;
      $easy_order['checkout']['consumer']['shippingAddress']['addressLine2'] = $profile->address->address_line2;
      $easy_order['checkout']['consumer']['shippingAddress']['postalCode'] = $profile->address->postal_code;
      $easy_order['checkout']['consumer']['shippingAddress']['city'] = $profile->address->locality;
      /** @var \CommerceGuys\Addressing\Country\CountryRepository $country_repository */
      $country_repository = \Drupal::service('address.country_repository');
      $country = $country_repository->get($profile->address->country_code);
      $easy_order['checkout']['consumer']['shippingAddress']['country'] = $country->getThreeLetterCode();
      if (!empty($profile->address->organization)) {
        $easy_order['checkout']['consumer']['company']['name'] = $profile->address->organization;
        $easy_order['checkout']['consumer']['company']['contact']['firstName'] = $profile->address->given_name;
        $easy_order['checkout']['consumer']['company']['contact']['lastName'] = $profile->address->family_name;
      }
      else {
        $easy_order['checkout']['consumer']['privatePerson']['firstName'] = $profile->address->given_name;
        $easy_order['checkout']['consumer']['privatePerson']['lastName'] = $profile->address->family_name;
      }
    }

    foreach ($order->getItems() as $item) {
      $easy_order['order']['items'][] = $this->buildItem($item);
    }

    // Shipping is handled separately.
    // @todo: Shipping promotion must be handled separately as well.
    $exclude_adjustment_types = ['shipping', 'promotion'];
    $adjustments = $order->collectAdjustments();
    $adjustments = $adjustments ? \Drupal::service('commerce_order.adjustment_transformer')->processAdjustments($adjustments) : [];
    foreach ($adjustments as $adjustment) {
      // Skip included adjustments and the ones we don't handle.
      if ($adjustment->isIncluded() ||
        in_array($adjustment->getType(), $exclude_adjustment_types)) {
        continue;
      }
      $easy_order['order']['items'][] = $this->buildAdjustment($adjustment);
    }

    // Order level promotions should be added here.
    $adjustments = $order->getAdjustments(['promotion']);
    $adjustments = $adjustments ? \Drupal::service('commerce_order.adjustment_transformer')->processAdjustments($adjustments) : [];
    foreach ($adjustments as $adjustment) {
      // Skip included adjustments and the ones we don't handle.
      if ($adjustment->isIncluded()) {
        continue;
      }
      $easy_order['order']['items'][] = $this->buildAdjustment($adjustment);
    }

    if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
      $shipments = $order->get('shipments')->referencedEntities();
      foreach ($shipments as $shipment) {
        $shipment_amount = $shipment->getAmount();
        if (empty($shipment_amount)) {
          continue;
        }
        // Check if there are included tax adjustments.
        $tax_adjustments = array_filter($shipment->getAdjustments(['tax']), function ($adjustment) {
          return $adjustment->isIncluded();
        });
        $shipping_line = [
          'reference' => 'shipping_fee',
          'name' => (string) t('Shipping'),
          'quantity' => 1,
          'unit' => $this->t('Shipment'),
        ];
        // If there are no included tax adjustments, there's no need to split
        // the shipment amounts across multiple VAT rates.
        if (!$tax_adjustments) {
          $shipping_line += [
            'unitPrice' => $this->toMinorUnits($shipment->getAmount()),
            'netTotalAmount' => $this->toMinorUnits($shipment->getAmount()),
            'grossTotalAmount' => $this->toMinorUnits($shipment->getAmount()),
            'taxTate' => 0,
            'taxAmount' => 0,
          ];
          $easy_order['order']['items'][] = $shipping_line;
        }
        elseif (count($tax_adjustments) === 1) {
          /** @var Adjustment $tax_adjustment */
          $tax_adjustment = reset($tax_adjustments);
          $shipping_line += [
            'unitPrice' => $this->toMinorUnits($shipment->getAmount()->subtract($tax_adjustment->getAmount())),
            'netTotalAmount' => $this->toMinorUnits($shipment->getAmount()->subtract($tax_adjustment->getAmount())),
            'grossTotalAmount' => $this->toMinorUnits($shipment->getAmount()),
            'taxRate' => (int) Calculator::multiply($tax_adjustment->getPercentage(), '10000'),
            'taxAmount' => $this->toMinorUnits($tax_adjustment->getAmount()),
          ];
          $easy_order['order']['items'][] = $shipping_line;
        }
        else {
          /** @var \Drupal\commerce_easy\ShipmentPriceSplitterInterface $shipment_price_splitter */
          $shipment_price_splitter = \Drupal::service('commerce_easy.shipment_price_splitter');
          $shipment_amounts = $shipment_price_splitter->split($order, $shipment);
          foreach ($shipment_amounts as $amounts) {
            $easy_order['order']['items'][] = $shipping_line + [
              'unitPrice' => $this->toMinorUnits($amounts['unit_amount']->subtract($amounts['tax_amount'])),
              'netTotalAmount' => $this->toMinorUnits($amounts['unit_amount']->subtract($amounts['tax_amount'])),
              'grossTotalAmount' => $this->toMinorUnits($amounts['unit_amount']),
              'taxRate' => (int) Calculator::multiply($amounts['tax_rate'], '10000'),
              'taxAmount' => $this->toMinorUnits($amounts['tax_amount']),
            ];
          }
        }
      }
    }


    $event = new BuildOrderEvent($order, $easy_order);
    $this->eventDispatcher->dispatch(EasyEvents::EASY_BUILD_ORDER, $event);
    return $event->getEasyOrder();
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *
   * @return array
   */
  public function buildItem(OrderItemInterface $item) {
    // Fallback to the order item ID.
    $reference = $item->id();
    $purchased_entity = $item->getPurchasedEntity();

    // Send the "SKU" as the "reference" for product variations.
    if ($purchased_entity instanceof ProductVariationInterface) {
      $reference = $purchased_entity->getSku();
    }

    $order_line = [
      // The reference is limited to 128 characters.
      'reference' => mb_substr($reference, 0, 128),
      'name' => $item->label(),
      'quantity' => (int) $item->getQuantity(),
      'unit' => $this->t('Unit'),
      'unitPrice' => $this->toMinorUnits($item->getAdjustedUnitPrice(['promotion'])),
      'netTotalAmount' => $this->toMinorUnits($item->getAdjustedTotalPrice(['promotion'])),
      'grossTotalAmount' => $this->toMinorUnits($item->getAdjustedTotalPrice(['promotion'])),
      'taxRate' => 0,
      'taxAmount' => 0,
    ];

    // Only pass included tax adjustments (i.e "VAT"), non included tax
    // adjustments are passed separately (i.e "Sales tax").
    $tax_adjustments = $item->getAdjustments(['tax']);
    if ($tax_adjustments && $tax_adjustments[0]->isIncluded()) {
      $tax_rate = $tax_adjustments[0]->getPercentage();
      $tax_amount = $tax_adjustments[0]->getAmount();
      $net_unit_price = $item->getAdjustedUnitPrice(['promotion'])->subtract($tax_amount->divide((int) $item->getQuantity()));
      $net_unit_price = $this->priceRounder->round($net_unit_price);
      $order_line = array_merge($order_line, [
        'taxRate' => $tax_rate ? (string) Calculator::multiply($tax_rate, '10000') : 0,
        'taxAmount' => $this->toMinorUnits($tax_adjustments[0]->getAmount()),
        'unitPrice' => $this->toMinorUnits($net_unit_price),
        'netTotalAmount' => $this->toMinorUnits($item->getAdjustedTotalPrice(['promotion'])->subtract($tax_amount)),
      ]);
    }

    return $order_line;
  }

  /**
   * @param \Drupal\commerce_order\Adjustment $adjustment
   *
   * @return array
   */
  public function buildAdjustment(Adjustment $adjustment) {
    $order_line = [
      // The reference is limited to 64 characters.
      'reference' => $adjustment->getSourceId() ? mb_substr($adjustment->getSourceId(), 0, 128) : $adjustment->getType(),
      'name' => $adjustment->getLabel(),
      'quantity' => 1,
      'unit' => $this->t('Unit'),
      'taxRate' => 0,
      'taxAmount' => 0,
      'unitPrice' => $this->toMinorUnits($adjustment->getAmount()),
      'grossTotalAmount' => $this->toMinorUnits($adjustment->getAmount()),
      'netTotalAmount' => $this->toMinorUnits($adjustment->getAmount()),
    ];
    return $order_line;
  }

  /**
   * Calculates the total for the given adjustments.
   *
   * @param \Drupal\commerce_order\Adjustment[] $adjustments
   *   The adjustments.
   * @param string[] $adjustment_types
   *   The adjustment types to include in the calculation.
   *   Examples: fee, promotion, tax. Defaults to all adjustment types.
   * @param bool $skip_included
   *   Whether to skip included adjustments (Defaults to FALSE).
   *
   * @return \Drupal\commerce_price\Price|null
   *   The adjustments total, or NULL if no matching adjustments were found.
   */
  protected function getAdjustmentsTotal(array $adjustments, array $adjustment_types = [], $skip_included = FALSE) {
    $adjustments_total = NULL;
    $matching_adjustments = [];

    foreach ($adjustments as $adjustment) {
      if ($skip_included && $adjustment->isIncluded()) {
        continue;
      }
      if ($adjustment_types && !in_array($adjustment->getType(), $adjustment_types)) {
        continue;
      }
      $matching_adjustments[] = $adjustment;
    }
    if ($matching_adjustments) {
      $matching_adjustments = \Drupal::service('commerce_order.adjustment_transformer')->processAdjustments($matching_adjustments);
      foreach ($matching_adjustments as $adjustment) {
        $adjustments_total = $adjustments_total ? $adjustments_total->add($adjustment->getAmount()) : $adjustment->getAmount();
      }
    }

    return $adjustments_total;
  }

  /**
   * Converts the given amount to its minor units.
   *
   * @todo: To be replaced with
   *   https://www.drupal.org/project/commerce/issues/3150917 when that gets in.
   *
   * @param \Drupal\commerce_price\Price $amount
   *   The amount.
   *
   * @return int
   *   The amount in minor units, as an integer.
   */
  protected function toMinorUnits(Price $amount) {
    return $amount->multiply(100)->getNumber();
  }
}
