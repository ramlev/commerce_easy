<?php

namespace Drupal\Tests\commerce_easy\Unit;

use Drupal\commerce_easy\OrderBuilder;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Easy unit tests.
 *
 * @group commerce_easy
 */
class EasyOrderBuilderTest extends UnitTestCase {

  /**
   * Event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcher
   */
  protected $eventDispatcher;

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $rounder;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $this->rounder = $this->createMock(RounderInterface::class);
  }

  /**
   * Test that order items are added as integers (represented as a string).
   */
  public function testOrderItemJsonEncoded() {
    $builder = new OrderBuilder($this->eventDispatcher, $this->rounder);
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getAdjustedUnitPrice')
      ->willReturn(new Price('4.4', 'USD'));
    $item->method('getAdjustedTotalPrice')
      ->willReturn(new Price('4.4', 'USD'));
    $item->method('getTotalPrice')
      ->willReturn(new Price('4.4', 'USD'));
    $item->method('getAdjustments')
      ->willReturn([]);
    // @todo: Remove after making the builder more DI.
    $container = new ContainerBuilder();
    $mock_string_translation = $this->createMock(TranslationManager::class);
    $container->set('string_translation', $mock_string_translation);
    \Drupal::setContainer($container);;
    $item_built = $builder->buildItem($item);
    $this->assertEquals(440, (int) $item_built["unitPrice"]);
    $this->assertEquals(440, (int) $item_built["grossTotalAmount"]);
    $this->assertEquals(440, (int) $item_built["netTotalAmount"]);
    // Now try to json_encode this, as the number should not change.
    $json = json_encode($item_built);
    $this->assertTrue(strpos($json, '"unitPrice":"440","'));
  }

}
