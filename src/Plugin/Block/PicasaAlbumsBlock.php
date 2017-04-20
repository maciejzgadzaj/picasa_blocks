<?php

namespace Drupal\picasa_blocks\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an albums block.
 *
 * @Block(
 *   id = "picasa_blocks_albums",
 *   admin_label = @Translation("Picasa albums"),
 *   category = @Translation("Social")
 * )
 */
class PicasaAlbumsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
      'album_count' => 4,
      'cache_time_minutes' => 1440,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['album_count'] = array(
      '#type' => 'number',
      '#title' => $this->t('Number of albums to display'),
      '#default_value' => $this->configuration['album_count'],
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
      $this->configuration['album_count'] = $form_state->getValue('album_count');
      $this->configuration['cache_time_minutes'] = $form_state->getValue('cache_time_minutes');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('picasa_block.settings');

    // Build a render array to return the commits.
    $build = array();

    // If no configuration was saved, don't attempt to build block.
    if (!$user_id = $config->get('user_id')) {
      return $build;
    }

    $url = 'https://picasaweb.google.com/data/feed/api/user/' . $user_id . '?kind=album&access=public&thumbsize=400c';

    // http://www.a-basketful-of-papayas.net/2010/04/using-php-dom-with-xpath.html
    $feed = new \DOMDocument();
    $feed->load($url);

    $albums = [];

    $xpath = new \DOMXPath($feed);
    $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

    foreach ($xpath->evaluate('//atom:entry') as $entry) {
      $id = $xpath->evaluate('string(gphoto:id)', $entry);
      $albums[$id] = [
        'id' => $id,
        'title' => $xpath->evaluate('string(atom:title)', $entry),
        'summary' => $xpath->evaluate('string(atom:summary)', $entry),
        'published' => $xpath->evaluate('string(atom:published)', $entry),
        'published_timestamp' => strtotime($xpath->evaluate('string(atom:published)', $entry)),
        'updated' => $xpath->evaluate('string(atom:updated)', $entry),
        'updated_timestamp' => strtotime($xpath->evaluate('string(atom:updated)', $entry)),
        'taken_timestamp_ms' => strtotime($xpath->evaluate('string(gphoto:timestamp)', $entry)),
        'taken_timestamp' => (int) strtotime($xpath->evaluate('string(gphoto:timestamp)', $entry)) / 1000,
        'author' => [
          'id' => $xpath->evaluate('string(gphoto:user)', $entry),
          'name' => $xpath->evaluate('string(atom:author/atom:name)', $entry),
          'uri' => $xpath->evaluate('string(atom:author/atom:uri)', $entry),
        ],
        'numphotos' => $xpath->evaluate('number(gphoto:numphotos)', $entry),
        'url' => $xpath->evaluate('string(atom:link[@rel="alternate"]/@href)', $entry),
        'cover' => [
          'id' => $xpath->evaluate('string(gphoto:id)', $entry),
          'title' => $xpath->evaluate('string(media:group/media:title)', $entry),
          'location' => $xpath->evaluate('string(gphoto:location)', $entry),
          'thumbnail' => [
            'url' => $xpath->evaluate('string(media:group/media:thumbnail/@url)', $entry),
            'width' => $xpath->evaluate('string(media:group/media:thumbnail/@width)', $entry),
            'height' => $xpath->evaluate('string(media:group/media:thumbnail/@height)', $entry),
          ],
          'full' => [
            'url' => $xpath->evaluate('string(media:group/media:content/@url)', $entry),
          ],
        ],
      ];
    }

    if (empty($albums)) {
      return $build;
    }

    usort($albums, function ($a, $b) {
      return SortArray::sortByKeyInt($a, $b, 'published_timestamp');
    });
    $albums = array_reverse($albums);
    $albums = array_slice($albums, 0, $this->configuration['album_count']);

    foreach ($albums as $id => $album) {
      $build['albums'][$id] = array(
        '#theme' => 'picasa_albums_block_album',
        '#album' => $album,
      );
    }

    // Add css.
    if (!empty($build)) {
      $build['#attached']['library'][] = 'picasa_blocks/albums_block';
    }

    // Cache for a day.
    $build['#cache']['keys'] = [
      'block',
      'picasa_blocks_albums',
      $this->configuration['id'],
    ];
    $build['#cache']['context'][] = 'languages:language_content';
    $build['#cache']['max_age'] = $this->configuration['cache_time_minutes'] * 60;

    return $build;
  }

}
