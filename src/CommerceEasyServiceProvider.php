<?php

namespace Drupal\commerce_easy;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers services for non-required modules.
 */
class CommerceEasyServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    // We cannot use the module handler as the container is not yet compiled.
    // @see \Drupal\Core\DrupalKernel::compileContainer()
    $modules = $container->getParameter('container.modules');

    if (isset($modules['commerce_shipping'])) {
      $container->register('commerce_easy.shipment_price_splitter', ShipmentPriceSplitter::class)
        ->addArgument(new Reference('entity_type.manager'))
        ->addArgument(new Reference('commerce_price.rounder'));
    }
  }

}
