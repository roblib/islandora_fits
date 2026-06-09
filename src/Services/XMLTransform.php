<?php

namespace Drupal\islandora_fits\Services;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\MediaInterface;

/**
 * Transforms FITS XML.
 */
class XMLTransform
{

  /**
   * The FITS technical metadata media bundle.
   */
  private const FITS_BUNDLE = 'fits_technical_metadata';

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Characters to normalize in generated field names.
   *
   * @var array
   */
  protected array $forbidden = ['-', ' '];

  /**
   * XMLTransform constructor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface  $entity_type_manager,
    MessengerInterface          $messenger,
  )
  {
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * Transforms FITS XML into a render array.
   *
   * @param string $input_xml
   *   XML to be transformed.
   *
   * @return array
   *   A render array.
   */
  public function transformFits(string $input_xml): array
  {
    if (!mb_check_encoding($input_xml, 'UTF-8')) {
      $input_xml = mb_convert_encoding($input_xml, 'UTF-8', mb_list_encodings());
    }

    try {
      $xml = new \SimpleXMLElement($input_xml);
    } catch (\Exception $e) {
      $this->messenger->addWarning(t('File does not contain valid XML.'));

      return [
        '#markup' => t('File does not contain valid XML.'),
      ];
    }

    $xml->registerXPathNamespace('fits', 'http://hul.harvard.edu/ois/xml/ns/fits/fits_output');

    $fits_metadata = $this->islandoraFitsChildXpath($xml);

    $puid = NULL;
    if (
      isset($xml->identification->identity->externalIdentifier)
      && (string)$xml->identification->identity->externalIdentifier['type'] === 'puid'
    ) {
      $puid = (string)$xml->identification->identity->externalIdentifier;
    }

    $headers = [
      'label' => t('Field'),
      'value' => t('Value'),
    ];

    $variables = [
      'islandora_fits_table' => [],
      'islandora_fits_fieldsets' => [],
      'islandora_fits_data' => [],
    ];

    if (count($fits_metadata) === 0) {
      $variables['islandora_fits_table']['empty'] = '';
      $variables['islandora_fits_fieldsets']['empty'] = [
        '#type' => 'markup',
        '#markup' => t('No technical metadata found.'),
      ];
    } else {
      if ($puid !== NULL) {
        $fits_metadata['Droid'] = ['PUID' => [$puid]];
      }

      foreach ($fits_metadata as $tool_name => $vals_array) {
        $variables['islandora_fits_data'][$tool_name] = [];
        $rows = &$variables['islandora_fits_data'][$tool_name];

        foreach ($vals_array as $field => $val_array) {
          if (array_key_exists($field, $rows) || $field === 'Filepath') {
            continue;
          }

          $rows[$field] = [
            ['data' => Xss::filter($field), 'class' => 'islandora_fits_table_labels'],
          ];

          foreach ($val_array as $value) {
            if (!isset($rows[$field]['value'])) {
              $rows[$field]['value'] = [
                'data' => Xss::filter((string)$value),
                'class' => 'islandora_fits_table_values',
              ];
            } else {
              $rows[$field]['value']['data'] .= ' - ' . Xss::filter((string)$value);
            }
          }
        }

        $table_attributes = ['class' => ['islandora_fits_table']];

        $variables['islandora_fits_table'][$tool_name] = [
          'header' => $headers,
          'rows' => $rows,
          'attributes' => $table_attributes,
        ];

        $variables['islandora_fits_fieldsets'][$tool_name] = [
          '#theme' => 'table',
          '#header' => $headers,
          '#rows' => $rows,
          '#attributes' => $table_attributes,
          '#header_columns' => 4,
        ];
      }
    }

    $output = [];
    foreach ($variables['islandora_fits_fieldsets'] as $title => $fieldset) {
      $output[] = [
        'title' => $title,
        'data' => $fieldset,
      ];
    }

    return [
      '#theme' => 'fits',
      '#output' => $output,
      '#attached' => [
        'library' => [
          'islandora_fits/islandora_fits',
        ],
      ],
    ];
  }

  /**
   * Finds the first set of children from the FITS XML.
   *
   * @param \SimpleXMLElement $xml
   *   The SimpleXMLElement to parse.
   *
   * @return array
   *   Parsed key/value pairs.
   */
  private function islandoraFitsChildXpath(\SimpleXMLElement $xml): array
  {
    $results = $xml->xpath('/*|/*/fits:metadata') ?: [];
    $output = [];

    foreach ($results as $result) {
      $this->islandoraFitsChildren($result, $output);
    }

    return $output;
  }

  /**
   * Recursively finds children for the FITS module.
   *
   * @param \SimpleXMLElement $child
   *   The current child.
   * @param array $output
   *   Parsed output.
   */
  private function islandoraFitsChildren(\SimpleXMLElement $child, array &$output): void
  {
    $grandchildren = $child->xpath('*/*') ?: [];

    if (count($grandchildren) > 0) {
      foreach ($grandchildren as $grandchild) {
        $this->islandoraFitsChildren($grandchild, $output);
      }
      return;
    }

    $text_results = $child->xpath('text()') ?: [];

    foreach ($text_results as $text) {
      $tool_name = FALSE;

      foreach ($text->attributes() as $key => $value) {
        if ($key === 'toolname') {
          $tool_name = trim((string)$value);
        }
      }

      $output_text = trim((string)$text);

      if ($output_text === '') {
        continue;
      }

      $fits_out = $this->islandoraFitsConstructOutput($child->getName(), $tool_name ?: '');
      $tool_label = $fits_out['tool'];
      $field_label = $fits_out['name'];

      if ($tool_label) {
        if (!isset($output[$tool_label][$field_label])) {
          $output[$tool_label][$field_label] = [];
        }

        if (!in_array($output_text, $output[$tool_label][$field_label], TRUE)) {
          $output[$tool_label][$field_label][] = $output_text;
        }
      } else {
        if (!isset($output['Unknown'][$field_label])) {
          $output['Unknown'][$field_label] = [];
        }

        if (!in_array($output_text, $output['Unknown'][$field_label], TRUE)) {
          $output['Unknown'][$field_label][] = $output_text;
        }
      }
    }
  }

  /**
   * Builds display labels by parsing strings.
   *
   * @param string $node_name
   *   The current node name.
   * @param string $tool_name
   *   The tool name.
   *
   * @return array
   *   Constructed output labels.
   */
  private function islandoraFitsConstructOutput(string $node_name, string $tool_name): array
  {
    $node_name = preg_replace('/(?<!^)([A-Z])/', ' $1', $node_name);
    $node_name = ucwords($node_name);

    return [
      'name' => $node_name,
      'tool' => $tool_name !== '' ? ucwords($tool_name) : '',
    ];
  }

  /**
   * Adds missing dynamic FITS fields to the FITS media bundle.
   *
   * @param string $input_xml
   *   Input XML.
   *
   * @return bool
   *   TRUE if fields were added.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function addMediaFields(string $input_xml): bool
  {
    if (!$this->fitsBundleExists()) {
      $this->messenger->addWarning(t('The FITS technical metadata media type does not exist. No FITS fields were added.'));
      return FALSE;
    }

    $fields_added = FALSE;
    $to_process = $this->extractNormalizedFields($input_xml);

    if (empty($to_process)) {
      return FALSE;
    }

    $bundle_fields = $this->entityFieldManager->getFieldDefinitions('media', self::FITS_BUNDLE);
    $bundle_keys = array_keys($bundle_fields);

    foreach ($to_process as $field) {
      $field_name = $field['field_name'];

      $field_storage = FieldStorageConfig::loadByName('media', $field_name);

      if (!$field_storage) {
        $field_storage = FieldStorageConfig::create([
          'entity_type' => 'media',
          'field_name' => $field_name,
          'type' => 'text',
          'cardinality' => 1,
        ]);
        $field_storage->save();
      }

      if (!in_array($field_name, $bundle_keys, TRUE)) {
        FieldConfig::create([
          'field_storage' => $field_storage,
          'entity_type' => 'media',
          'bundle' => self::FITS_BUNDLE,
          'field_name' => $field_name,
          'label' => $field['field_label'],
        ])->save();

        $fields_added = TRUE;
        $bundle_keys[] = $field_name;
      }
    }

    return $fields_added;
  }

  /**
   * Populates FITS media fields from XML.
   *
   * @param string $input_xml
   *   Input XML.
   * @param \Drupal\media\MediaInterface $media
   *   Media entity to populate.
   */
  public function populateMedia(string $input_xml, MediaInterface &$media): void
  {
    $to_add = $this->extractNormalizedFields($input_xml);

    foreach ($to_add as $field) {
      $field_name = $field['field_name'];

      if ($media->hasField($field_name)) {
        $media->set($field_name, $field['field_value']);
      }
    }
  }

  /**
   * Checks whether new dynamic FITS fields are required.
   *
   * @param string $input_xml
   *   XML to check.
   *
   * @return bool
   *   TRUE if new fields are needed.
   */
  public function checkNew(string $input_xml): bool
  {
    if (!$this->fitsBundleExists()) {
      return FALSE;
    }

    $to_process = $this->extractNormalizedFields($input_xml);

    if (empty($to_process)) {
      return FALSE;
    }

    $bundle_fields = $this->entityFieldManager->getFieldDefinitions('media', self::FITS_BUNDLE);
    $bundle_keys = array_keys($bundle_fields);

    foreach ($to_process as $field) {
      if (!in_array($field['field_name'], $bundle_keys, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extracts normalized field definitions from FITS XML.
   *
   * @param string $input_xml
   *   Input XML.
   *
   * @return array
   *   Normalized fields.
   */
  private function extractNormalizedFields(string $input_xml): array
  {
    $data = $this->transformFits($input_xml);

    if (empty($data['#output']) || !is_array($data['#output'])) {
      return [];
    }

    $all_fields = [];

    foreach ($data['#output'] as $datum) {
      $all_fields = array_merge($all_fields, $this->harvestValues($datum));
    }

    return $this->normalizeNames($all_fields);
  }

  /**
   * Extracts and labels table content.
   *
   * @param array $input
   *   Render array item.
   *
   * @return array
   *   Field values keyed by generated label.
   */
  private function harvestValues(array $input): array
  {
    $fields = [];

    if (
      empty($input['title'])
      || empty($input['data']['#rows'])
      || !is_array($input['data']['#rows'])
    ) {
      return $fields;
    }

    $label = str_replace(' ', '_', $input['title']);
    $rows = $input['data']['#rows'];

    foreach ($rows as $key => $value) {
      if (isset($value['value']['data'])) {
        $fields["{$label}_{$key}"] = $value['value']['data'];
      }
    }

    return $fields;
  }

  /**
   * Creates standardized machine-name fields.
   *
   * @param array $names
   *   Names to normalize.
   *
   * @return array
   *   Normalized field definitions.
   */
  private function normalizeNames(array $names): array
  {
    $normalized_names = [];

    foreach ($names as $label => $field_value) {
      $field_name = $this->buildFieldName($label);

      $normalized_names[] = [
        'field_label' => $label,
        'field_name' => $field_name,
        'field_value' => $field_value,
      ];
    }

    return $normalized_names;
  }

  /**
   * Builds a safe field machine name from a FITS label.
   *
   * @param string $label
   *   The FITS label.
   *
   * @return string
   *   Field machine name.
   */
  private function buildFieldName(string $label): string
  {
    $normalized = strtolower($label);
    $normalized = str_replace($this->forbidden, '_', $normalized);
    $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized);
    $normalized = preg_replace('/_+/', '_', $normalized);
    $normalized = trim($normalized, '_');

    // Drupal field names are limited to 32 chars. Add a short hash to avoid
    // collisions caused by truncating long FITS labels.
    $hash = substr(hash('crc32b', $label), 0, 6);
    $base = substr("fits_{$normalized}", 0, 25);

    return "{$base}_{$hash}";
  }

  /**
   * Checks whether the FITS media bundle exists.
   *
   * @return bool
   *   TRUE if the bundle exists.
   */
  private function fitsBundleExists(): bool
  {
    return (bool)$this->entityTypeManager
      ->getStorage('media_type')
      ->load(self::FITS_BUNDLE);
  }

}
