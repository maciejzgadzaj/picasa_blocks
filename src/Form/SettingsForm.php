<?php

namespace Drupal\picasa_blocks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure example settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'picasa_blocks_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'picasa_blocks.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('picasa_blocks.settings');

    $form['user_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google user id'),
      '#required' => TRUE,
      '#default_value' => $config->get('user_id'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('picasa_blocks.settings')
      ->set('user_id', $form_state->getValue('user_id'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
