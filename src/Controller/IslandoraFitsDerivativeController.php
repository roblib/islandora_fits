<?php

namespace Drupal\islandora_fits\Controller;


use Drupal\Core\Controller\ControllerBase;

use Drupal\media\Entity\Media;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class IslandoraFitsDerivativeController extends ControllerBase {

  /**
   * Service for business logic.
   *
   * @var \Drupal\islandora_fits\Services\XMLTransform
   */
  protected $transformer;

  public function __construct($transformer) {
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
   *  Adds file to existing media.
   *
   * @param Media $media
   *  The media to which file is added
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
      $media_type = $media->bundle();
      $has_new = $this->transformer->checkNew($contents, $media_type);
      \Drupal::logger('alan_dev')->warning("Has new - $has_new");
      if ($has_new) {
        $media->save();
        $this->transformer->addMediaFields($contents, $media_type);
        \Drupal::logger('alan_dev')->warning("Media fields added");

        $media = Media::load($media->id());
      }
      $this->transformer->populateMedia($contents, $media);
      $file = file_save_data($contents, $content_location, FILE_EXISTS_REPLACE);
      $media->{$destination_field}->setValue([
        'target_id' => $file->id(),
      ]);

      $media->save();
    }
    return new Response("<h1>Complete</h1>");
  }

}
