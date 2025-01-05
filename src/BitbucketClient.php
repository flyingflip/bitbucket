<?php

namespace Drupal\bitbucket;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;

use FlyingFlip\OAuth2\Client\Provider\Bitbucket;
use FlyingFlip\OAuth2\Client\Provider\BitbucketUser;

/**
 * Bitbucket client wrapper. Implement custom methods to retrieve specific Bitbucket
 * data using access_tokens stored in Drupal.
 */
class BitbucketClient extends Bitbucket {
  use StringTranslationTrait;

  /**
   * Header value to pass along for Accept-Languge, which toggles between the
   * allowed unit systems.
   *
   * @var string
   */
  protected $acceptLang;

  /**
   * BitbucketClient constructor.
   *
   * @param array $options
   * @param string $accept_lang
   */
  public function __construct(array $options, $accept_lang = NULL) {
    parent::__construct($options);
    $this->setAcceptLang($accept_lang);
  }

  /**
   * Setter for the value of the Accept-Language header in all Bitbucket profile
   * requests.
   *
   * @param string $accept_lang
   */
  public function setAcceptLang($accept_lang = NULL) {
    $this->acceptLang = $accept_lang;
  }

  /**
   * Get the currently logged in Bitbucket user. The access token is from
   * the currently logged in Drupal user.
   *
   * @param AccessToken $access_token
   *   Bitbucket AccessToken object.
   *
   * @return BitbucketUser|null
   */
  public function getResourceOwner(AccessToken $access_token) : BitbucketUser {
    if ($response = $this->request('/2.0/user', $access_token)) {
      return new BitbucketUser($response);
    }
  }

  /**
   * Request a resource on the Bitbucket API.
   *
   * @param string $resource
   *   Path to the resource on the API. Should include a leading /.
   * @param AccessToken $access_token
   *   Fitbit AccessToken object.
   *
   * @return mixed|null
   *   API response or null in the case of an exception, which can happen if the
   *   user did not authorize the resource being requested.
   */
  public function request($resource, AccessToken $access_token) : bool {
    $options = [];
    if ($this->acceptLang) {
      $options['headers'][Bitbucket::HEADER_ACCEPT_LANG] = $this->acceptLang;
    }
    $request = $this->getAuthenticatedRequest(
      Bitbucket::METHOD_GET,
      Bitbucket::BASE_FITBIT_API_URL . $resource,
      $access_token,
      $options
    );

    try {
      return $this->getResponse($request);
    }
    catch (IdentityProviderException $e) {
      $log_level = RfcLogLevel::ERROR;
      // Look through the errors reported in the response body. If the only
      // error was an insufficient_scope error, report as a notice.
      $parsed = $this->parseResponse($e->getResponseBody());
      if (!empty($parsed['errors'])) {
        $error_types = [];
        foreach ($parsed['errors'] as $error) {
          if (isset($error['errorType'])) {
            $error_types[] = $error['errorType'];
          }
        }
        $error_types = array_unique($error_types);
        if (count($error_types) === 1 && reset($error_types) === 'insufficient_scope') {
          $log_level = RfcLogLevel::NOTICE;
        }
      }

      $logger = \Drupal::logger('bitbucket');
      Error::logException($logger, $exception, NULL, [], $log_level);
      return FALSE;
    }
  }

  /**
   * Return an array of supported values for Accept-Language, which correspond
   * to the unit systems supported by the API.
   *
   * @return array
   *   Associative array keyed by Accept-Language header value. Each value is
   *   the name of the units system.
   */
  public function getAcceptLangOptions() {
    return [
      '' => $this->t('Metric'),
      'en_US' => $this->t('US'),
      'en_GB' => $this->t('UK'),
    ];
  }

  /**
   * Ensure that the redirectUri param is set. Way to get around inability to
   * use Url::toString() during a router rebuild.
   */
  protected function ensureRedirectUri() {
    if (!isset($this->redirectUri)) {
      $this->redirectUri = Url::fromRoute('bitbucket.authorization', [], ['absolute' => TRUE])->toString();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getAuthorizationParameters(array $options) {
    $this->ensureRedirectUri();
    return parent::getAuthorizationParameters($options);
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessToken($grant, array $options = []) {
    $this->ensureRedirectUri();
    return parent::getAccessToken($grant, $options);
  }
}
