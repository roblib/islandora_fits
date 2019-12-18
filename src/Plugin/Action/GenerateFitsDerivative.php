<?php

namespace Drupal\islandora_fits\Plugin\Action;

use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora\Plugin\Action\AbstractGenerateDerivative;

/**
 * Emits a Node for generating fits derivatives event.
 *
 * @Action(
 *   id = "generate_fits_derivative",
 *   label = @Translation("Generate a Technical metadata derivative"),
 *   type = "node"
 * )
 */
class GenerateFitsDerivative extends AbstractGenerateDerivative {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    $config['path'] = '[date:custom:Y]-[date:custom:m]/[node:nid]-[term:name].xml';
    $config['mimetype'] = 'application/xml';
    $config['queue'] = 'islandora-connector-fits';
    $config['destination_media_type'] = 'file';
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfigurationlo() {
    return [
      'event' => 'Generate Derivative',
      'source_term_uri' => '',
      'derivative_term_uri' => '',
      'mimetype' => '',
      'args' => '',
      'destination_media_type' => '',
      'scheme' => file_default_scheme(),

    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Create list of file fields.
    $map = $this->entityFieldManager->getFieldMapByFieldType('file');
    $file_fields = $map['media'];
    $file_options = array_combine(array_keys($file_fields), array_keys($file_fields));
    $file_options = array_merge(['-' => $this->t('Choose destination File Field')], $file_options);
    $states = [
      'visible' => [
        ':input[name="event"]' => ['value' => 'Generate Derivative'],
      ],
    ];
    $event_options = [
      'Attach' => t('Attach Derivative to existing Media'),
      'Generate Derivative' => t('Create Derivative as Media'),
    ];
    $uri = 'http://pcdm.org/use#OriginalFile';
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['mimetype']['#description'] = t('Mimetype to convert to (e.g. application/xml, etc...)');
    $form['event']['#disabled'] = FALSE;
    $form['event']['#options'] = $event_options;
    $form['mimetype']['#value'] = 'application/xml';
    $form['mimetype']['#type'] = 'hidden';
    $default_term = $this->utils->getTermForUri($this->configuration['derivative_term_uri']);
    if (!$default_term) {
      $default_term = $this->utils->getTermForUri($uri);
    }
    $form['source_term']['#default_value'] = $default_term;

    $form['field_name'] = [
      '#type' => 'select',
      '#options' => $file_options,
      '#title' => $this->t('File field Name'),
      '#description' => $this->t('File field on Media Type to hold derivative.'),
      '#states' => [
        'visible' => [
          ':input[name="event"]' => ['value' => 'Attach'],
        ],
      ],
    ];

    $altered = ['derivative_term', 'destination_media_type', 'scheme'];
    foreach ($altered as $element) {
      $form[$element]["#required"] = FALSE;
      $form[$element]['#states'] = $states;
    }

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
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $tid = $form_state->getValue('source_term');
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    $this->configuration['source_term_uri'] = $this->utils->getUriForTerm($term);

    $tid = $form_state->getValue('derivative_term');
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    $this->configuration['derivative_term_uri'] = $this->utils->getUriForTerm($term);

    $this->configuration['mimetype'] = $form_state->getValue('mimetype');
    $this->configuration['args'] = $form_state->getValue('args');
    $this->configuration['scheme'] = $form_state->getValue('scheme');
    $this->configuration['path'] = trim($form_state->getValue('path'), '\\/');
    $this->configuration['destination_media_type'] = $form_state->getValue('destination_media_type');
  }

}
