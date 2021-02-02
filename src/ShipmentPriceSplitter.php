<?php

namespace Drupal\commerce_easy;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class ShipmentPriceSplitter implements ShipmentPriceSplitterInterface {

  /**
   * The currency storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $currencyStorage;

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * Constructs a new ShipmentPriceSplitter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The rounder.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RounderInterface $rounder) {
    $this->currencyStorage = $entity_type_manager->getStorage('commerce_currency');
    $this->rounder = $rounder;
  }

  /**
   * {@inheritdoc}
   */
  public function split(OrderInterface $order, ShipmentInterface $shipment) {

    // Group order items by tax percentage.
    $groups = [];
    foreach ($order->getItems() as $order_item) {
      $order_item_total = $order_item->getTotalPrice();
      $order_item_tax_adjustments = $order_item->getAdjustments(['tax']);
      $order_item_tax_adjustment = reset($order_item_tax_adjustments);
      $percentage = $order_item_tax_adjustment->getPercentage();
      if (!isset($groups[$percentage])) {
        $groups[$percentage] = $order_item_total;
      }
      else {
        $previous_total = $groups[$percentage];
        $groups[$percentage] = $previous_total->add($order_item_total);
      }
    }
    // Sort by percentage descending.
    krsort($groups, SORT_NUMERIC);
    // Calculate the ratio of each group.
    $subtotal = $order->getSubtotalPrice()->getNumber();
    $ratios = [];
    foreach ($groups as $percentage => $order_item_total) {
      $ratios[$percentage] = $order_item_total->divide($subtotal)->getNumber();
    }
    $unit_price = $shipment->getAmount();
    $return = [];
    // The unit price should not include any discount.
    $unit_amounts = $this->allocate($unit_price, $ratios);
    $shipment_tax_adjustments = $shipment->getAdjustments(['tax']);
    foreach ($shipment_tax_adjustments as $adjustment) {
      $percentage = $adjustment->getPercentage();
      $return[$percentage] = [
        'unit_amount' => $unit_amounts[$percentage],
        'tax_amount' => $adjustment->getAmount(),
        'tax_rate' => $percentage,
      ];
    }

    return $return;
  }

  /**
   * Allocates the given amount according to a list of ratios.
   *
   * @param \Drupal\commerce_price\Price $amount
   *   The amount.
   * @param array $ratios
   *   An array of ratios, keyed by tax percentage.
   *
   * @return array
   *   An array of amounts keyed by tax percentage.
   */
  protected function allocate(Price $amount, array $ratios) {
    $amounts = [];
    $amount_to_allocate = $amount;
    foreach ($ratios as $percentage => $ratio) {
      $individual_amount = $amount_to_allocate->multiply($ratio);
      $individual_amount = $this->rounder->round($individual_amount, PHP_ROUND_HALF_DOWN);
      // Due to rounding it is possible for the last calculated
      // per-order-item amount to be larger than the total remaining amount.
      if ($individual_amount->greaterThan($amount)) {
        $individual_amount = $amount;
      }
      $amounts[$percentage] = $individual_amount;
      $amount = $amount->subtract($individual_amount);
    }
    // The individual amounts don't add up to the full amount, distribute
    // the reminder among them.
    if (!$amount->isZero()) {
      /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
      $currency = $this->currencyStorage->load($amount->getCurrencyCode());
      $precision = $currency->getFractionDigits();
      // Use the smallest rounded currency amount (e.g. '0.01' for USD).
      $smallest_number = Calculator::divide('1', pow(10, $precision), $precision);
      $smallest_amount = new Price($smallest_number, $amount->getCurrencyCode());
      while (!$amount->isZero()) {
        foreach ($amounts as $percentage => $individual_amount) {
          $amounts[$percentage] = $individual_amount->add($smallest_amount);
          $amount = $amount->subtract($smallest_amount);
          if ($amount->isZero()) {
            break 2;
          }
        }
      }
    }

    return $amounts;
  }

}
