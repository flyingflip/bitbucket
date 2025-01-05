<?php

namespace Drupal\bitbucket;

use Drupal\Core\Config\ConfigFactoryInterface;

class FitbitClientFactory {

  /**
   * Create an instance of FitbitClient.
   *
   * @param ConfigFactoryInterface $config_factory
   *
   * @return FitbitClient
   */
  public static function create(ConfigFactoryInterface $config_factory) {
    $config = $config_factory->get('bitbucket.application_settings');
    $options = [
      'clientId' => $config->get('client_id'),
      'clientSecret' => $config->get('client_secret'),
    ];
    return new FitbitClient($options);
  }
}
