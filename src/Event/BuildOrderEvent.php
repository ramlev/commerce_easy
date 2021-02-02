<?php

namespace Drupal\commerce_easy\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\EventDispatcher\Event;

class BuildOrderEvent extends Event {

  /**
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * @var array
   */
  protected $easyOrder;

  /**
   * BuildOrderEvent constructor.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * @param array $easy_order
   */
  public function __construct(OrderInterface $order, array $easy_order) {
    $this->order = $order;
    $this->easyOrder = $easy_order;
  }

  /**
   * @param array $easyOrder
   *
   * @return $this
   */
  public function setEasyOrder($easyOrder) {
    $this->easyOrder = $easyOrder;
    return $this;
  }

  /**
   * @return array
   */
  public function getEasyOrder() {
    return $this->easyOrder;
  }

  /**
   * @return \Drupal\commerce_order\Entity\OrderInterface
   */
  public function getOrder() {
    return $this->order;
  }

}
