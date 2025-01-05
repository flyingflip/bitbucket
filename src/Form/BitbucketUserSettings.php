<?php

namespace Drupal\bitbucket\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\bitbucket\BitbucketAccessTokenManager;
use Drupal\bitbucket\BitbucketClient;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Utility\Error;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BitbucketUserSettings extends FormBase {

  /**
   * bitbucket client.
   *
   * @var \Drupal\bitbucket\bitbucketClient
   */
  protected $bitbucketClient;

  /**
   * bitbucket access token manager.
   *
   * @var \Drupal\bitbucket\bitbucketAccessTokenManager
   */
  protected $bitbucketAccessTokenManager;

  /**
   * Session storage.
   *
   * @var \Drupal\user\PrivateTempStoreFactory
   */
  protected $tempStore;

  /**
   * UserSettings constructor.
   *
   * @param BitbucketClient $bitbucket_client
   * @param BitbucketAccessTokenManager $bitbucket_access_token_manager
   * @param PrivateTempStoreFactory $private_temp_store_factory
   */
  public function __construct(BitbucketClient $bitbucket_client, BitbucketAccessTokenManager $bitbucket_access_token_manager, PrivateTempStoreFactory $private_temp_store_factory) {
    $this->bitbucketClient = $bitbucket_client;
    $this->bitbucketAccessTokenManager = $bitbucket_access_token_manager;
    $this->tempStore = $private_temp_store_factory->get('bitbucket');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('bitbucket.client'),
      $container->get('bitbucket.access_token_manager'),
      $container->get('tempstore.private'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bitbucket_user_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, AccountInterface $user = NULL) {
    // Store the uid on the form object.
    $form['uid'] = [
      '#type' => 'value',
      '#value' => $user->id(),
    ];

    // Attempt to get the Fitibit account. If the account is properly linked,
    // this will return a result which we'll use to present some of the users
    // stats.
    if ($access_token = $this->bitbucketAccessTokenManager->loadAccessToken($user->id())) {

      if ($bitbucket_user = $this->bitbucketClient->getResourceOwner($access_token)) {
        $user_data = $bitbucket_user->toArray();

        $form['authenticated'] = [
          '#markup' => $this->t('<p>You\'re authenticated. Welcome @name.</p>', ['@name' => $bitbucket_user->getDisplayName()]),
        ];
        if (!empty($user_data['avatar150'])) {
          $form['avatar'] = [
            '#theme' => 'image',
            '#uri' => $user_data['avatar150'],
          ];
        }
        if (!empty($user_data['averageDailySteps'])) {
          $form['avg_steps'] = [
            '#markup' => $this->t('<p><strong>Average daily steps:</strong> @steps</p>', ['@steps' => $user_data['averageDailySteps']]),
          ];
        }
      }
      else {
        $form['authenticated'] = [
          '#markup' => $this->t('<p>You\'re authenticated.</p>'),
        ];
      }

      $form['revoke'] = [
        '#type' => 'submit',
        '#value' => $this->t('Revoke access to my bitbucket account'),
        '#submit' => [
          [$this, 'revokeAccess'],
        ],
      ];
    }
    else {
      $form['connect'] = [
        '#type' => 'submit',
        '#value' => $this->t('Connect to bitbucket'),
        '#submit' => [
          [$this, 'submitForm']
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $authorization_url = $this->bitbucketClient->getAuthorizationUrl();
    $this->tempStore->set('state', $this->bitbucketClient->getState());
    $form_state->setResponse(new TrustedRedirectResponse($authorization_url, 302));
  }

  /**
   * Form submission handler for revoke access to the users bitbucket account.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function revokeAccess(array &$form, FormStateInterface $form_state) {
    $uid = $form_state->getValue('uid');

    if ($access_token = $this->bitbucketAccessTokenManager->loadAccessToken($uid)) {
      try {
        $this->bitbucketClient->revoke($access_token);
        $this->bitbucketAccessTokenManager->delete($uid);
        \Drupal::messenger()->addMessage($this->t('Access to your bitbucket account has been revoked.'));
      }
      catch (\Exception $e) {
        $logger = \Drupal::logger('bitbucket');
        Error::logException($logger, $e);
        \Drupal::messenger()->addError($this->t('There was an error revoking access to your account: @message. Please try again. If the error persists, please contact the site administrator.', ['@message' => $e->getMessage()]));
      }
    }
  }

  /**
   * Checks access for a users bitbucket settings page.
   *
   * @param AccountInterface $account
   *   Current user.
   * @param UserInterface $user
   *   User being accessed.
   *
   * @return AccessResult
   */
  public function checkAccess(AccountInterface $account, UserInterface $user = NULL) {
    // Only allow access if user has authorize bitbucket account and it's their
    // own page.
    return AccessResult::allowedIf($account->hasPermission('authorize bitbucket account') && $account->id() === $user->id());
  }
}
