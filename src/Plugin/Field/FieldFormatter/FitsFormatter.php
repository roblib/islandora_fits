<?php

namespace Drupal\islandora_fits\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Plugin implementation of the 'fits_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "fits_formatter",
 *   label = @Translation("FITS formatter"),
 *   field_types = {
 *     "file"
 *   }
 * )
 */
class FitsFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The FITS XML transformer service.
   *
   * @var object
   */
  protected object $transformer;

  /**
   * Constructs a FitsFormatter object.
   *
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Third-party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param object $transformer
   *   The FITS XML transformer service.
   */
  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    EntityTypeManagerInterface $entity_type_manager,
    FileUrlGeneratorInterface $file_url_generator,
    object $transformer,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);

    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
    $this->transformer = $transformer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
                       $plugin_id,
                       $plugin_definition,
  ): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('islandora_fits.transformxml'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    return parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = $this->viewValue($item);
    }

    return $elements;
  }

  /**
   * Generates the output for one field item.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   One field item.
   *
   * @return array
   *   A render array.
   */
  protected function viewValue(FieldItemInterface $item): array {
    $target_id = $item->get('target_id')->getValue();

    if (empty($target_id)) {
      return [
        '#markup' => $this->t('No FITS file is attached.'),
      ];
    }

    $file = $this->entityTypeManager
      ->getStorage('file')
      ->load($target_id);

    if (!$file instanceof FileInterface) {
      return [
        '#markup' => $this->t('The FITS file could not be loaded.'),
      ];
    }

    $uri = $file->getFileUri();

    if (empty($uri) || !is_readable($uri)) {
      return [
        '#markup' => $this->t('The FITS file is not readable.'),
        '#cache' => [
          'tags' => $file->getCacheTags(),
        ],
      ];
    }

    $contents = file_get_contents($uri);

    if ($contents === FALSE) {
      return [
        '#markup' => $this->t('The FITS file could not be read.'),
        '#cache' => [
          'tags' => $file->getCacheTags(),
        ],
      ];
    }

    if (!mb_check_encoding($contents, 'UTF-8')) {
      $contents = mb_convert_encoding($contents, 'UTF-8', mb_list_encodings());
    }

    $url = $this->fileUrlGenerator->generate($uri);

    $link = Link::fromTextAndUrl($this->t('Link to XML'), $url)->toRenderable();

    $output = $this->transformer->transformFits($contents);

    $output['#link'] = $link;
    $output['#title'] = $this->t('FITS Metadata');

    $output['#cache']['tags'] = array_merge(
      $output['#cache']['tags'] ?? [],
      $file->getCacheTags()
    );

    return $output;
  }

}
