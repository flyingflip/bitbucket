<?php

namespace Drupal\bitbucket;

use Drupal\Core\Config\ConfigFactoryInterface;

class BitbucketClientFactory {

  /**
   * Create an instance of BitbucketClient.
   *
   * @param ConfigFactoryInterface $config_factory
   *
   * @return BitbucketClient
   */
  public static function create(ConfigFactoryInterface $config_factory) {
    $config = $config_factory->get('bitbucket.application_settings');
    $options = [
      'clientId' => $config->get('client_id'),
      'clientSecret' => $config->get('client_secret'),
    ];
    return new BitbucketClient($options);
  }
}
