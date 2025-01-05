<?php

namespace Drupal\bitbucket\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\butbucket\BitbucketAccessTokenManager;
use Drupal\butbucket\BitbucketClient;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Authorization extends ControllerBase {

  /**
   * Bitbucket client.
   *
   * @var \Drupal\butbucket\BitbucketClient
   */
  protected $butbucketClient;

  /**
   * Bitbucket Access Token Manager.
   *
   * @var \Drupal\butbucket\BitbucketAccessTokenManager
   */
  protected $butbucketAccessTokenManager;

  /**
   * Session storage.
   *
   * @var \Drupal\user\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Authorization constructor.
   *
   * @param BitbucketClient $butbucket_client
   * @param BitbucketAccessTokenManager $butbucket_access_token_manager
   * @param PrivateTempStoreFactory $private_temp_store_factory
   * @param Request $request
   * @param AccountInterface $current_user
   */
  public function __construct(BitbucketClient $butbucket_client, BitbucketAccessTokenManager $butbucket_access_token_manager, PrivateTempStoreFactory $private_temp_store_factory, Request $request, AccountInterface $current_user) {
    $this->butbucketClient = $butbucket_client;
    $this->butbucketAccessTokenManager = $butbucket_access_token_manager;
    $this->tempStore = $private_temp_store_factory->get('butbucket');
    $this->request = $request;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('butbucket.client'),
      $container->get('butbucket.access_token_manager'),
      $container->get('tempstore.private'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('current_user')
    );
  }

  /**
   * Receive the authorization code from a Fitibit Authorization Code Flow
   * redirect, and request an access token from Bitbucket.
   */
  public function authorize() {

    try {
      // Try to get an access token using the authorization code grant.
      $access_token = $this->butbucketClient->getAccessToken('authorization_code', [
        'code' => $this->request->get('code')]
      );

      // Save access token details.
      $this->butbucketAccessTokenManager->save($this->currentUser->id(), [
        'access_token' => $access_token->getToken(),
        'expires' => $access_token->getExpires(),
        'refresh_token' => $access_token->getRefreshToken(),
        'user_id' => $access_token->getResourceOwnerId(),
      ]);

      \Drupal::messenger()->addMessage($this->t('Your Bitbucket account is now connected.'));
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()])->toString());
      //return new RedirectResponse(Url::fromRoute('butbucket.user_settings', ['user' => $this->currentUser->id()])->toString());
    }
    catch (IdentityProviderException $e) {
      $logger = \Drupal::logger('butbucket');
      Error::logException($logger, $e);
      \Drupal::messenger()->addError($this->t('Your Bitbucket account could not be connected. ' . $e->getMessage()));
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()])->toString());
    }
  }

  /**
   * Check the state key from Bitbucket to protect against CSRF.
   */
  public function checkAccess() {
    return AccessResult::allowedIf($this->tempStore->get('state') == $this->request->get('state'));
  }
}
