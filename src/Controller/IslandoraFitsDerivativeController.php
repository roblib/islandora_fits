<?php

namespace Drupal\islandora_fits\Controller;

use Drupal\Core\Controller\ControllerBase;

use Drupal\media\Entity\Media;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\islandora_fits\Services\XMLTransform;

/**
 * Adds Derivative Media to existing media.
 */
class IslandoraFitsDerivativeController extends ControllerBase {

  /**
   * Service for business logic.
   *
   * @var \Drupal\islandora_fits\Services\XMLTransform
   */
  protected $transformer;

  /**
   * IslandoraFitsDerivativeController constructor.
   *
   * @param \Drupal\islandora_fits\Services\XMLTransform $transformer
   *   XMLTransformer.
   */
  public function __construct(XMLTransform $transformer) {
    $this->transformer = $transformer;
  }

  /**
   * Controller's create method for dependecy injection.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The App Container.
   *
   * @return \Drupal\islandora_fits\Controller\IslandoraFitsDerivativeController
   *   Controller instance.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('islandora_fits.transformxml')
    );
  }

  /**
   * Adds file to existing media.
   *
   * @param Drupal\media\Entity\Media $media
   *   The media to which file is added.
   * @param string $destination_field
   *   The name of the media field to add file reference.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   201 on success with a Location link header.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   */
  public function attachToMedia(
    Media $media,
    string $destination_field,
    Request $request) {
    $content_location = $request->headers->get('Content-Location', "");
    $contents = $request->getContent();
    if ($contents) {
      $has_new = $this->transformer->checkNew($contents, "fits_technical_metadata");
      if ($has_new) {
        $this->transformer->addMediaFields($contents, "fits_technical_metadata");
      }
      $fits_media_description = $media->get($destination_field);
      $target_id = $fits_media_description->getValue($destination_field)[0]['target_id'];
      if (!$target_id) {
        $fits_media = MEDIA::create([
          'bundle' => 'fits_technical_metadata',
          'name' => "Fits Metadata of - {$media->id()}",
        ]);
      }
      else {
        $fits_media = Media::load($target_id);
      }
      $this->transformer->populateMedia($contents, $fits_media);
      $file = file_save_data($contents, $content_location, FILE_EXISTS_REPLACE);
      $fits_media->{'field_media_file'}->setValue([
        'target_id' => $file->id(),
      ]);
      $fits_media->save();
      $media->set($destination_field, $fits_media);
      $media->save();
    }
    return new Response("<h1>Complete</h1>");
  }

}
