<?php

namespace Drupal\islandora_fits\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
Use Drupal\Component\Utility\Xss;
use Drupal\Core\Link;
use Drupal\Core\Url;


/**
 * Plugin implementation of the 'fits_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "fits_formatter",
 *   label = @Translation("Fits formatter"),
 *   field_types = {
 *     "file"
 *   }
 * )
 */
class FitsFormatter extends FormatterBase {

    /**
     * {@inheritdoc}
     */
    public static function defaultSettings() {
        return [
                // Implement default settings.
            ] + parent::defaultSettings();
    }

    /**
     * {@inheritdoc}
     */
    public function settingsForm(array $form, FormStateInterface $form_state) {
        return [
                // Implement settings form.
            ] + parent::settingsForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function settingsSummary() {
        $summary = [];
        // Implement settings summary.

        return $summary;
    }

    /**
     * {@inheritdoc}
     */
    public function viewElements(FieldItemListInterface $items, $langcode) {
        $elements = [];

        foreach ($items as $delta => $item) {
            $elements[$delta] = ['#markup' => $this->viewValue($item)];
        }

        return $elements;
    }

    /**
     * Generate the output appropriate for one field item.
     *
     * @param \Drupal\Core\Field\FieldItemInterface $item
     *   One field item.
     *
     * @return string
     *   The textual output generated.
     */
    protected function viewValue(FieldItemInterface $item) {
        $fileItem = $item->getValue();
        $file = File::load($fileItem['target_id']);
        $url = Url::fromUri($file->url());
        $link = Link::fromTextAndUrl("Link to XML", $url);
        $link = $link->toRenderable();
        $contents = file_get_contents($file->getFileUri());
        if (mb_detect_encoding($contents) != 'UTF-8') {
            $contents = utf8_encode($contents);
        }
        $xml = new \SimpleXMLElement($contents);
        $xml->registerXPathNamespace('fits', 'http://hul.harvard.edu/ois/xml/ns/fits/fits_output');
        $fits_metadata = $this->islandora_fits_child_xpath($xml);
        $headers = array(
            'label' => t('Field'),
            'value' => t('Value'),
        );
        if (count($fits_metadata) == 0) {
            $variables['islandora_fits_table']['empty'] = '';
            $variables['islandora_fits_fieldsets']['empty'] = array(
                '#type' => 'markup',
                '#markup' => t('No technical metadata found.'),
            );
        } else {
            foreach ($fits_metadata as $tool_name => $vals_array) {
                $variables['islandora_fits_data'][$tool_name] = array();
                $rows = &$variables['islandora_fits_data'][$tool_name];
                foreach ($vals_array as $field => $val_array) {
                    if (!array_key_exists($field, $rows)) {
                        $rows[$field] = array(
                            array('data' => Xss::filter($field), 'class' => 'islandora_fits_table_labels'),
                        );
                        foreach ($val_array as $value) {
                            if (!isset($rows[$field]['value'])) {
                                $rows[$field]['value'] = array('data' => Xss::filter($value), 'class' => 'islandora_fits_table_values');
                            } else {
                                $data = $rows[$field]['value']['data'] .= ' - ' . Xss::filter($value);
                                $rows[$field]['value'] = array('data' => $data, 'class' => 'islandora_fits_table_values');
                            }
                        }
                    }
                    $table_attributes = array('class' => array('islandora_fits_table'));

                    $table = array(
                        'header' => $headers,
                        'rows' => $rows,
                        'attributes' => $table_attributes,
                    );

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

        $renderable =  [
            '#theme' => 'fits',
            '#title'  => $this->t("FITS metadata"),
            '#link' => $link,
            '#data' => $variables['islandora_fits_fieldsets'],

        ];
        return \Drupal::service('renderer')->render($renderable);
    }

    /**
     * Finds the the first set of children from the FITS xml.
     *
     * Once it has these it passes them off recursively.
     *
     * @param SimpleXMLElement $xml
     *   The SimpleXMLElement to parse.
     *
     * @return array
     *   An array containing key/value pairs of fields and data.
     */
    public function islandora_fits_child_xpath($xml) {
        $results = $xml->xpath('/*|/*/fits:metadata');
        $output = array();
        foreach ($results as $result) {
            $this->islandora_fits_children($result, $output);
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
     * @param SimpleXMLElement $child
     *   The current child that we are searching through.
     *
     * @param array $output
     *   An array containing key/value pairs of fields and data.
     */
    public function islandora_fits_children($child, &$output) {
        $grandchildren = $child->xpath('*/*');

        if (count($grandchildren) > 0) {
            foreach ($grandchildren as $grandchild) {
                $this->islandora_fits_children($grandchild, $output);
            }
        } else {
            $text_results = $child->xpath('text()');
            $tool_name = FALSE;
            if ($text_results) {
                foreach ($text_results as $text) {
                    foreach ($text->attributes() as $key => $value) {
                        if ($key === 'toolname') {
                            $tool_name = trim((string)$value);
                        }
                    }
                    $output_text = trim((string)$text);
                    if (!empty($output_text)) {
                        $fits_out = $this->islandora_fits_construct_output($child->getName(), $tool_name);
                        $tool_label = $fits_out['tool'];
                        $field_label = $fits_out['name'];
                        // Need to check if the label already exists in our output
                        // such that we do not duplicate entries.
                        if ($tool_label) {
                            if (isset($output[$tool_label])) {
                                if (!array_key_exists($field_label, $output[$tool_label])) {
                                    $output[$tool_label][$field_label][] = $output_text;
                                } else {
                                    if (!in_array($output_text, $output[$tool_label][$field_label])) {
                                        $output[$tool_label][$field_label][] = $output_text;
                                    }
                                }
                            } else {
                                $output[$tool_label][$field_label][] = $output_text;
                            }
                        } // No tool attribute.
                        else {
                            if (isset($output['Unknown'][$field_label])) {
                                if (!in_array($output_text, $output['Unknown'][$field_label])) {
                                    $output['Unknown'][$field_label][] = $output_text;
                                }
                            } else {
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
    public function islandora_fits_construct_output($node_name, $tool_name) {
        // Construct an arbitrary string with all capitals in it.
        $capitals = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $name_array = str_split($node_name);
        $space_position = array();

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

        return array('name' => $node_name, 'tool' => ucwords($tool_name));
    }


}
