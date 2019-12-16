<?php

namespace Drupal\islandora_fits\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AdminForm.
 */
class AdminForm extends ConfigFormBase {

  /**
   * Configuration service.
   *
   * @var Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;
  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config Factory.
   * @param \Drupal\Core\Entity\EntityFieldManager $entityFieldManager
   *   The EntityFieldManager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityFieldManager $entityFieldManager) {
    parent::__construct($config_factory);
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_field.manager')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandoira_fits_admin_form';
  }

  protected function getEditableConfigNames() {
    return [
      'islandora_fits.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('islandora_fits.settings');
    $map = $this->entityFieldManager->getFieldMapByFieldType('file');
    $file_fields = $map['media'];
    $file_options = array_combine(array_keys($file_fields), array_keys($file_fields));
    $form['fits_file_field'] = [
      '#required' => TRUE,
      '#type' => 'select',
      '#options' => $file_options,
      '#title' => $this->t('Destination File field Name'),
      '#default_value' => $config->get('fits_file_field'),
      '#description' => $this->t('File field on Media Type for FITS derivative.'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('islandora_fits.settings')
      ->set('fits_file_field', $values['fits_file_field'])
      ->save();
  }
}
