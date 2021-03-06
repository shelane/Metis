<?php
/**
 * @file
 * Apply memcache settings for Docksal.
 *
 * Settings are borrowed from BLT's memcache.settings.php.
 *
 * @see https://docs.docksal.io/tools/drupal/#memcache
 */

use Composer\Autoload\ClassLoader;
use Acquia\Blt\Robo\Common\EnvironmentDetector;
use Drupal\Core\Installer\InstallerKernel;

if (EnvironmentDetector::isLocalEnv() && getenv('DOCKSAL') && !InstallerKernel::installationAttempted()) {
  // Check for PHP Memcached libraries.
  $memcache_exists = class_exists('Memcache', FALSE);
  $memcached_exists = class_exists('Memcached', FALSE);
  $memcache_services_yml = DRUPAL_ROOT . '/modules/contrib/memcache/memcache.services.yml';
  $memcache_module_is_present = file_exists($memcache_services_yml);
  if ($memcache_module_is_present && ($memcache_exists || $memcached_exists)) {
    // Use Memcached extension if available.
    if ($memcached_exists) {
      $settings['memcache']['extension'] = 'Memcached';
    }
    if (class_exists(ClassLoader::class)) {
      $class_loader = new ClassLoader();
      $class_loader->addPsr4('Drupal\\memcache\\', DRUPAL_ROOT . '/modules/contrib/memcache/src');
      $class_loader->register();
      $settings['container_yamls'][] = $memcache_services_yml;

      // Set key_prefix to avoid drush cr flushing all bins on multisite.
      $settings['memcache']['key_prefix'] = $site_dir . '_';
      $settings['memcache']['bins'] = ['default' => 'default'];

      // Set the server.
      $settings['memcache']['servers'] = ['memcached:11211' => 'default'];

      // Bootstrap cache.container with memcache rather than database.
      $settings['bootstrap_container_definition'] = [
        'parameters' => [],
        'services' => [
          'database' => [
            'class' => 'Drupal\Core\Database\Connection',
            'factory' => 'Drupal\Core\Database\Database::getConnection',
            'arguments' => ['default'],
          ],
          'settings' => [
            'class' => 'Drupal\Core\Site\Settings',
            'factory' => 'Drupal\Core\Site\Settings::getInstance',
          ],
          'memcache.settings' => [
            'class' => 'Drupal\memcache\MemcacheSettings',
            'arguments' => ['@settings'],
          ],
          'memcache.factory' => [
            'class' => 'Drupal\memcache\Driver\MemcacheDriverFactory',
            'arguments' => ['@memcache.settings'],
          ],
          'memcache.timestamp.invalidator.bin' => [
            'class' => 'Drupal\memcache\Invalidator\MemcacheTimestampInvalidator',
            'arguments' => [
              '@memcache.factory',
              'memcache_bin_timestamps',
              0.001,
            ],
          ],
          'memcache.backend.cache.container' => [
            'class' => 'Drupal\memcache\DrupalMemcacheInterface',
            'factory' => ['@memcache.factory', 'get'],
            'arguments' => ['container'],
          ],
          'cache_tags_provider.container' => [
            'class' => 'Drupal\Core\Cache\DatabaseCacheTagsChecksum',
            'arguments' => ['@database'],
          ],
          'cache.container' => [
            'class' => 'Drupal\memcache\MemcacheBackend',
            'arguments' => [
              'container',
              '@memcache.backend.cache.container',
              '@cache_tags_provider.container',
              '@memcache.timestamp.invalidator.bin',
              '@memcache.settings',
            ],
          ],
        ],
      ];

      // Use memcache for bootstrap, discovery, config instead of fast chained
      // backend to properly invalidate caches on multiple webs.
      // See https://www.drupal.org/node/2754947
      $settings['cache']['bins']['bootstrap'] = 'cache.backend.memcache';
      $settings['cache']['bins']['discovery'] = 'cache.backend.memcache';
      $settings['cache']['bins']['config'] = 'cache.backend.memcache';

      // Use memcache as the default bin.
      $settings['cache']['default'] = 'cache.backend.memcache';
    }
  }
}
