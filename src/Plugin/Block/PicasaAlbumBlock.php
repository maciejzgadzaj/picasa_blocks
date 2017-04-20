<?php

namespace Drupal\picasa_blocks\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an album block.
 *
 * @Block(
 *   id = "picasa_blocks_album",
 *   admin_label = @Translation("Picasa album"),
 *   category = @Translation("Social")
 * )
 */
class PicasaAlbumBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Constructs a PicasaBlockBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\Client $http_client
   *   The Guzzle HTTP client.
   * @param ConfigFactory $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, Client $http_client, ConfigFactory $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'photo_count' => NULL,
      'thumbnail_size' => 100,
      'thumbnail_cropped' => FALSE,
      'cache_time_minutes' => 1440,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['photo_count'] = array(
      '#type' => 'number',
      '#title' => $this->t('Number of albums to display'),
      '#description' => $this->t('Leave empty to display all photos from the album.'),
      '#default_value' => $this->configuration['photo_count'],
    );

    $form['thumbnail_size'] = array(
      '#type' => 'number',
      '#title' => $this->t('Thumbnail size'),
      '#default_value' => $this->configuration['thumbnail_size'],
    );

    $form['thumbnail_cropped'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Crop thumbnail'),
      '#default_value' => $this->configuration['thumbnail_cropped'],
    );

    $form['cache_time_minutes'] = array(
      '#type' => 'number',
      '#title' => $this->t('Cache time'),
      '#field_suffix' => $this->t('minutes'),
      '#default_value' => $this->configuration['cache_time_minutes'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      return;
    }
    else {
      $this->configuration['photo_count'] = $form_state->getValue('photo_count');
      $this->configuration['thumbnail_size'] = $form_state->getValue('thumbnail_size');
      $this->configuration['thumbnail_cropped'] = $form_state->getValue('thumbnail_cropped');
      $this->configuration['cache_time_minutes'] = $form_state->getValue('cache_time_minutes');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('picasa_blocks.settings');

    // Build a render array to return the commits.
    $build = array();

    // If no configuration was saved, don't attempt to build block.
    if (!$user_id = $config->get('user_id')) {
      return $build;
    }

    $node = \Drupal::routeMatch()->getParameter('node');
    if (!isset($node->field_picasa_album_id) || !$album_id = $node->field_picasa_album_id->value) {
      return $build;
    }

    $cropped = $this->configuration['thumbnail_cropped'] ? 'c' : 'u';
    $url = "https://picasaweb.google.com/data/feed/api/user/$user_id/albumid/$album_id?kind=photo&access=public&thumbsize=" . $this->configuration['thumbnail_size'] . $cropped;

    // http://www.a-basketful-of-papayas.net/2010/04/using-php-dom-with-xpath.html
    $feed = new \DOMDocument();
    $feed->load($url);

    $photos = [];

    $xpath = new \DOMXPath($feed);
    $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

    foreach ($xpath->evaluate('//atom:entry') as $entry) {
      $id = $xpath->evaluate('string(gphoto:id)', $entry);
      $photos[$id] = [
        'id' => $id,
        'title' => $xpath->evaluate('string(atom:title)', $entry),
        'summary' => $xpath->evaluate('string(atom:summary)', $entry),
        'published' => $xpath->evaluate('string(atom:published)', $entry),
        'published_timestamp' => strtotime($xpath->evaluate('string(atom:published)', $entry)),
        'updated' => $xpath->evaluate('string(atom:updated)', $entry),
        'updated_timestamp' => strtotime($xpath->evaluate('string(atom:updated)', $entry)),
        'taken_timestamp_ms' => $xpath->evaluate('string(gphoto:timestamp)', $entry),
        'taken_timestamp' => (int) $xpath->evaluate('string(gphoto:timestamp)', $entry) / 1000,
        'original' => [
          'url' => $xpath->evaluate('string(atom:content/@src)', $entry),
          'width' => $xpath->evaluate('string(gphoto:width)', $entry),
          'height' => $xpath->evaluate('string(gphoto:height)', $entry),
          'size' => $xpath->evaluate('string(gphoto:size)', $entry),
        ],
        'thumbnail' => [
          'url' => $xpath->evaluate('string(media:group/media:thumbnail/@url)', $entry),
          'width' => $xpath->evaluate('string(media:group/media:thumbnail/@width)', $entry),
          'height' => $xpath->evaluate('string(media:group/media:thumbnail/@height)', $entry),
        ],
        'page' => [
          'url' => $xpath->evaluate('string(atom:link[@rel="alternate"]/@href)', $entry),
        ],
        'album' => [
          'id' => $xpath->evaluate('string(gphoto:albumid)', $entry),
          'title' => $xpath->evaluate('string(atom:title)'),
          'url' => $xpath->evaluate('string(atom:link[@rel="alternate"]/@href)', $entry),
        ],
      ];
    }

    if (empty($photos)) {
      return $build;
    }

    usort($photos, function ($a, $b) {
      return SortArray::sortByKeyInt($a, $b, 'published_timestamp');
    });
    $photos = array_reverse($photos);
    $photos = array_slice($photos, 0, $this->configuration['photo_count']);

    foreach ($photos as $id => $photo) {
      $build['albums'][$id] = array(
        '#theme' => 'picasa_album_block_photo',
        '#photo' => $photo,
      );
    }

    // Add css.
    if (!empty($build)) {
      $build['#attached']['library'][] = 'picasa_blocks/album_block';
    }

    // Cache for a day.
    $build['#cache']['keys'] = [
      'block',
      'picasa_blocks_album',
      'picasa_blocks_album_' . $node->id(),
      'picasa_blocks_album_' . $album_id,
      $this->configuration['id'],
    ];
    $build['#cache']['context'][] = 'languages:language_content';
    $build['#cache']['context'][] = 'route';
    $build['#cache']['max_age'] = $this->configuration['cache_time_minutes'] * 60;

    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * @see https://drupal.stackexchange.com/a/199541/1757
   */
  public function getCacheTags() {
    // Rebuild the block if the node changes.
    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      return Cache::mergeTags(parent::getCacheTags(), array('node:' . $node->id()));
    }
    else {
      return parent::getCacheTags();
    }
  }

  /**
   * {@inheritdoc}
   *
   * @see https://drupal.stackexchange.com/a/199541/1757
   */
  public function getCacheContexts() {
    // Rebuild the block on every new route.
    return Cache::mergeContexts(parent::getCacheContexts(), array('route'));
  }

}
