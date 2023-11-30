<?php

namespace Drupal\Tests\commerce_easy\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;

/**
 * Easy unit tests.
 *
 * @group commerce_easy
 */
class OrderBuilderTest extends OrderKernelTestBase {

  /**
   * The test order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_easy',
    'commerce_shipping',
    'physical',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // This is copy-pasted from commerce_shipping.
    // Drupal\Tests\commerce_shipping\Kernel\ShippingKernelTestBase.
    $this->installEntitySchema('commerce_shipping_method');
    $this->installEntitySchema('commerce_shipment');
    $this->installConfig([
      'profile',
      'commerce_product',
      'commerce_order',
      'commerce_shipping',
    ]);
    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $product_variation_type */
    $product_variation_type = ProductVariationType::load('default');
    $product_variation_type->setGenerateTitle(FALSE);
    $product_variation_type->save();
    // Install the variation trait.
    $trait_manager = $this->container->get('plugin.manager.commerce_entity_trait');
    $trait = $trait_manager->createInstance('purchasable_entity_shippable');
    $trait_manager->installTrait($trait, 'commerce_product_variation', 'default');

    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = OrderType::load('default');
    $order_type->setThirdPartySetting('commerce_shipping', 'shipment_type', 'default');
    $order_type->save();
    // Create the order field.
    $field_definition = commerce_shipping_build_shipment_field_definition($order_type->id());
    $this->container->get('commerce.configurable_field_manager')->createField($field_definition);

    $this->order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'mail' => 'test@example.com',
      'ip_address' => '127.0.0.1',
      'order_number' => '6',
      'uid' => $this->createUser(),
      'store_id' => $this->store,
      'order_items' => [],
    ]);
  }

  /**
   * Test that we can order things even if the order has no shipment.
   */
  public function testOrderNoShipment() {
    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => '2',
      'unit_price' => [
        'number' => '20.00',
        'currency_code' => 'USD',
      ],
    ]);
    $order_item->save();
    $this->order->addItem($order_item);
    $this->order->save();
    /** @var \Drupal\commerce_easy\OrderBuilder $builder */
    $builder = $this->container->get('commerce_easy.order_builder');
    $data = $builder->buildOrder($this->order);
    $this->assertEquals($data["order"]["amount"], '4000');
    $this->assertEquals($data["order"]["currency"], 'USD');
    $this->assertEquals($data["order"]["reference"], $this->order->id());
  }

}
