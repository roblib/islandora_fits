<?php

namespace Drupal\islandora_fits\Services;

use Drupal\Component\Utility\Xss;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\media\MediaInterface;
use DrupalCodeGenerator\Command\Drupal_8\Form\Simple;

/**
 * Class XMLTransform.
 */
class XMLTransform extends ServiceProviderBase {
  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  private $renderer;
  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  private $entityManager;
  /**
   * List of characters to switch.
   *
   * @var array
   */
  private $forbidden;
  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private $messenger;

  /**
   * XMLTransform constructor.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entityManager
   *   The entity manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(RendererInterface $renderer, EntityFieldManager $entityManager, MessengerInterface $messenger) {
    $this->renderer = $renderer;
    $this->entityManager = $entityManager;
    $this->messenger = $messenger;
    $this->forbidden = ['-', ' '];
  }

  /**
   * Transforms FITS xml into renderable array.
   *
   * @param string $input_xml
   *   Xml to be transformed.
   *
   * @return array|null
   *   Array of parsed results.
   */
  public function transformFits($input_xml) {
    $utf8 = mb_detect_encoding($input_xml, 'UTF-8', TRUE);
    if (!$utf8) {
      $input_xml = utf8_encode($input_xml);
    }
    try {
      $xml = new \SimpleXMLElement($input_xml);
    }
    catch (\Exception $e) {
      $this->messenger->addWarning(t('File does not contain valid xml.'));
      return;
    }
    $xml->registerXPathNamespace('fits', 'http://hul.harvard.edu/ois/xml/ns/fits/fits_output');
    $fits_metadata = $this->islandoraFitsChildXpath($xml);
    $headers = [
      'label' => t('Field'),
      'value' => t('Value'),
    ];
    if (count($fits_metadata) == 0) {
      $variables['islandora_fits_table']['empty'] = '';
      $variables['islandora_fits_fieldsets']['empty'] = [
        '#type' => 'markup',
        '#markup' => t('No technical metadata found.'),
      ];
    }
    else {
      foreach ($fits_metadata as $tool_name => $vals_array) {
        $variables['islandora_fits_data'][$tool_name] = [];
        $rows = &$variables['islandora_fits_data'][$tool_name];
        foreach ($vals_array as $field => $val_array) {
          if (!array_key_exists($field, $rows) && $field != 'Filepath') {
            $rows[$field] = [
              ['data' => Xss::filter($field), 'class' => 'islandora_fits_table_labels'],
            ];
            foreach ($val_array as $value) {
              if (!isset($rows[$field]['value'])) {
                $rows[$field]['value'] = ['data' => Xss::filter($value), 'class' => 'islandora_fits_table_values'];
              }
              else {
                $data = $rows[$field]['value']['data'] .= ' - ' . Xss::filter($value);
                $rows[$field]['value'] = ['data' => $data, 'class' => 'islandora_fits_table_values'];
              }
            }
          }
          $table_attributes = ['class' => ['islandora_fits_table']];
          $table = [
            'header' => $headers,
            'rows' => $rows,
            'attributes' => $table_attributes,
          ];
          $variables['islandora_fits_table'][$tool_name] = $table;
          $variables['islandora_fits_fieldsets'][$tool_name] = [
            '#theme' => 'table',
            '#header' => $headers,
            '#rows' => $rows,
            '#attributes' => $table_attributes,
            '#header_columns' => 4,
          ];
        }
      }
    }
    $fieldsets = $variables['islandora_fits_fieldsets'];
    $output = [];
    foreach ($fieldsets as $title => $fieldset) {
      $output[] = [
        'title' => $title,
        'data' => $fieldset,
      ];
    }
    $renderable = [
      '#theme' => 'fits',
      '#output' => $output,
      '#attached' => [
        'library' => [
          'islandora_fits/islandora_fits',
        ],
      ],
    ];
    return $renderable;
  }

  /**
   * Finds the the first set of children from the FITS xml.
   *
   * Once it has these it passes them off recursively.
   *
   * @param  \SimpleXMLElement
   *   The SimpleXMLElement to parse.
   *
   * @return array
   *   An array containing key/value pairs of fields and data.
   */
  private function islandoraFitsChildXpath(\SimpleXMLElement $xml) {
    $results = $xml->xpath('/*|/*/fits:metadata');
    $output = [];
    foreach ($results as $result) {
      $this->islandoraFitsChildren($result, $output);
    }
    return $output;
  }

  /**
   * Finds children for fits module.
   *
   * Recursive function that searches continuously until
   * we grab the node's text value and add to
   * the output array.
   *
   * @param \SimpleXMLElement $child
   *   The current child that we are searching through.
   * @param array $output
   *   An array containing key/value pairs of fields and data.
   */
  private function islandoraFitsChildren(\SimpleXMLElement $child, array &$output) {
    $grandchildren = $child->xpath('*/*');

    if (count($grandchildren) > 0) {
      foreach ($grandchildren as $grandchild) {
        $this->islandoraFitsChildren($grandchild, $output);
      }
    }
    else {
      $text_results = $child->xpath('text()');
      $tool_name = FALSE;
      if ($text_results) {
        foreach ($text_results as $text) {
          foreach ($text->attributes() as $key => $value) {
            if ($key === 'toolname') {
              $tool_name = trim((string) $value);
            }
          }
          $output_text = trim((string) $text);
          if (!empty($output_text)) {
            $fits_out = $this->islandoraFitsContructOutput($child->getName(), $tool_name);
            $tool_label = $fits_out['tool'];
            $field_label = $fits_out['name'];
            // Need to check if the label already exists in our output
            // such that we do not duplicate entries.
            if ($tool_label) {
              if (isset($output[$tool_label])) {
                if (!array_key_exists($field_label, $output[$tool_label])) {
                  $output[$tool_label][$field_label][] = $output_text;
                }
                else {
                  if (!in_array($output_text, $output[$tool_label][$field_label])) {
                    $output[$tool_label][$field_label][] = $output_text;
                  }
                }
              }
              else {
                $output[$tool_label][$field_label][] = $output_text;
              }
            }
            // No tool attribute.
            else {
              if (isset($output['Unknown'][$field_label])) {
                if (!in_array($output_text, $output['Unknown'][$field_label])) {
                  $output['Unknown'][$field_label][] = $output_text;
                }
              }
              else {
                $output['Unknown'][$field_label][] = $output_text;
              }
            }
          }
        }
      }
    }
  }

  /**
   * Builds display by parsing strings.
   *
   * @param string $node_name
   *   Name of the current node that we will display.
   * @param string $tool_name
   *   Name of the tool used to generate the metadata.
   *
   * @return array
   *   Constructed node name for output.
   */
  private function islandoraFitsContructOutput(string $node_name, string $tool_name) {
    // Construct an arbitrary string with all capitals in it.
    $capitals = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $name_array = str_split($node_name);
    $space_position = [];

    // Check to see which characters are capitals so we can split
    // them up for cleaner display.
    foreach ($name_array as $key => $value) {
      if (strpos($capitals, $value) !== FALSE && $key !== 0) {
        $space_position[] = $key;
      }
    }
    if (count($space_position)) {
      // Needed in event we add multiple spaces so need to keep track.
      $pos_offset = 0;
      foreach ($space_position as $pos) {
        $node_name = substr_replace($node_name, ' ', $pos + $pos_offset, 0);
        $pos_offset++;
      }
    }
    $node_name = ucwords($node_name);

    return ['name' => $node_name, 'tool' => ucwords($tool_name)];
  }

  /**
   * Adds fields to content type.
   *
   * @param string $input_xml
   *   Input to be transformed.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *
   * @return bool
   *   Fields to be formatted.
   */
  public function addMediaFields(string $input_xml) {
    $fields_added = FALSE;
    $data = $this->transformFits($input_xml);
    $all_fields = [];
    foreach ($data['#output'] as $datum) {
      $all_fields = array_merge($all_fields, $this->harvestValues($datum));
    }
    $to_process = $this->normalizeNames($all_fields);
    foreach ($to_process as $field) {
      $exists = FieldStorageConfig::loadByName('media', $field['field_name']);
      if (!$exists) {
        $field_storage = FieldStorageConfig::create([
          'entity_type' => 'media',
          'field_name' => $field['field_name'],
          'type' => 'text',
        ]);
        $field_storage->save();
      }
      $bundle_fields = $this->entityManager->getFieldDefinitions('media', 'fits_technical_metadata');
      $bundle_keys = array_keys($bundle_fields);
      if (!in_array($field['field_name'], $bundle_keys)) {
        $field_storage = FieldStorageConfig::loadByName('media', $field['field_name']);
        FieldConfig::create([
          'field_storage' => $field_storage,
          'bundle' => 'fits_technical_metadata',
          'label' => $field['field_label'],
        ])->save();
        $fields_added = TRUE;
      }
    }
    return $fields_added;
  }

  /**
   * Populates media.
   *
   * @param string $input_xml
   *   String to be transformed.
   * @param \Drupal\media\MediaInterface $media
   *   Media to which transformed xml is to be added.
   */
  public function populateMedia(string $input_xml, MediaInterface &$media) {
    $data = $this->transformFits($input_xml);
    $all_fields = [];
    foreach ($data['#output'] as $datum) {
      $all_fields = array_merge($all_fields, $this->harvestValues($datum));
    }
    $to_add = [];
    foreach ($all_fields as $label => $field_value) {
      $lower = strtolower($label);
      $normalized = str_replace($this->forbidden, '_', $lower);
      $field_name = substr("fits_$normalized", 0, 32);
      $to_add[$field_name] = $field_value;
    }
    foreach ($to_add as $field_name => $field_value) {
      $media->set($field_name, $field_value);
    }
  }

  /**
   * Extracts and labels content.
   *
   * @param array $input
   *   Values to be harvested and prepped.
   *
   * @return array
   *   Fields prepped for display.
   */
  private function harvestValues(array $input) {
    $fields = [];
    $label = str_replace(' ', '_', $input['title']);
    $rows = $input['data']['#rows'];
    foreach ($rows as $key => $value) {
      $fields["{$label}_{$key}"] = $value['value']['data'];
    }
    return $fields;

  }

  /**
   * Create standardized machine name fields.
   *
   * @param array $names
   *   Names to be normalized.
   *
   * @return array
   *   Normalized names.
   */
  private function normalizeNames(array $names) {
    $normalized_names = [];
    foreach ($names as $label => $field_value) {
      $lower = strtolower($label);
      $normalized = str_replace($this->forbidden, '_', $lower);
      $field_name = substr("fits_$normalized", 0, 32);

      $normalized_names[] = [
        'field_label' => $label,
        'field_name' => $field_name,
        'field_value' => $field_value,
      ];
    }
    return $normalized_names;
  }

  /**
   * Checks to see if new fields are required.
   *
   * @param string $input_xml
   *   Xml to be checked.
   *
   * @return bool
   *   Whether fields havbe been added.
   */
  public function checkNew(string $input_xml) {
    $fields_added = FALSE;
    $data = $this->transformFits($input_xml);
    $all_fields = [];
    foreach ($data['#output'] as $datum) {
      $all_fields = array_merge($all_fields, $this->harvestValues($datum));
    }
    $to_process = $this->normalizeNames($all_fields);
    foreach ($to_process as $field) {
      $bundle_fields = $this->entityManager->getFieldDefinitions('media', 'fits_technical_metadata');
      $bundle_keys = array_keys($bundle_fields);
      if (!in_array($field['field_name'], $bundle_keys)) {
        $fields_added = TRUE;
      }
    }
    return $fields_added;
  }

}
