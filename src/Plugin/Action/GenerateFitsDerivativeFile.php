<?php

namespace Drupal\islandora_fits\Plugin\Action;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\islandora\Plugin\Action\AbstractGenerateDerivativeMediaFile;

/**
 * Emits a Node for generating fits derivatives event.
 *
 * @Action(
 *   id = "generate_fits_derivative_file",
 *   label = @Translation("Generate a Technical metadata derivative file"),
 *   type = "media"
 * )
 */
class GenerateFitsDerivativeFile extends AbstractGenerateDerivativeMediaFile {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    $config['path'] = '[date:custom:Y]-[date:custom:m]/[media:mid]-TECHMD.xml';
    $config['mimetype'] = 'application/xml';
    $config['queue'] = 'islandora-connector-fits';
    $config['destination_media_type'] = 'file';
    $config['scheme'] = file_default_scheme();
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['mimetype']['#description'] = t('Mimetype to convert to (e.g. application/xml, etc...)');
    $form['mimetype']['#value'] = 'application/xml';
    $form['mimetype']['#type'] = 'hidden';
    $map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');
    $file_fields = $map['media'];
    $file_options = array_combine(array_keys($file_fields), array_keys($file_fields));
    $file_options = array_merge(['' => ''], $file_options);
    $form['destination_field_name'] = [
      '#required' => TRUE,
      '#type' => 'select',
      '#options' => $file_options,
      '#title' => $this->t('Destination File field Name'),
      '#default_value' => $this->configuration['destination_field_name'],
      '#description' => $this->t('File field on Media Type to hold generated FITS media.  Cannot be the same as source'),
    ];

    unset($form['args']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $exploded_mime = explode('/', $form_state->getValue('mimetype'));
    if ($exploded_mime[0] != 'application') {
      $form_state->setErrorByName(
        'mimetype',
        t('Please enter file mimetype (e.g. application/xml.)')
      );
    }
  }

  /**
   * Override this to return arbitrary data as an array to be json encoded.
   */
  protected function generateData(EntityInterface $entity) {
    $data = parent::generateData($entity);
    $route_params = [
      'media' => $entity->id(),
      'destination_field' => $this->configuration['destination_field_name'],
    ];
    $data['destination_uri'] = Url::fromRoute('islandora_fits.attach_file_to_media', $route_params)
      ->setAbsolute()
      ->toString();

    return $data;
  }

}
