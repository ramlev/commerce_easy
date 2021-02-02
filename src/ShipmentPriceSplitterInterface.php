<?php

namespace Drupal\commerce_easy;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;

/**
 * Splits the shipment amounts across tax rates.
 */
interface ShipmentPriceSplitterInterface {

  /**
   * Splits the shipment amounts across tax rates.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment.
   *
   * @return array
   *   An array containing the shipment amount, the promotions amount, the tax
   *   rate/amount and the adjusted shipment amount, keyed by tax percentage.
   */
  public function split(OrderInterface $order, ShipmentInterface $shipment);

}
