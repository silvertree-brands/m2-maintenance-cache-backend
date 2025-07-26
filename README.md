# Maintenance Cache Backend Module

**Module:** `Silvertree_MaintenanceCacheBackend`  
**Version:** 1.0.1
**Compatibility:** Magento 2.4+ / PHP 8.1+

## Overview

This module replaces Magento's filesystem-based maintenance mode with a configurable cache backend system, provides
better scalability for distributed environments.

## Key Features

- **Persistent Maintenance Mode**: Survives `cache:flush` and `cache:clean` operations
- **Multiple Backend Support**: Redis, File, Memcached, or any Magento-supported cache backend
- **IP Whitelist Support**: Full compatibility with Magento's IP whitelist feature
- **Graceful Fallback**: Automatically falls back to filesystem when cache is unavailable
- **Zero Breaking Changes**: Maintains full compatibility with existing maintenance commands
- **Performance Optimized**: Dedicated cache backend

## Installation

1. **Install the module:**
   ```bash
   composer require silvertree/m2-maintenance-cache-backend
   php bin/magento module:enable Silvertree_MaintenanceCacheBackend
   php bin/magento setup:upgrade
   ```

2. **Configure cache backend** (see the Configuration section below)

3. **Test the configuration:**
   ```bash
   php bin/magento maintenance:enable
   php bin/magento maintenance:status
   php bin/magento cache:flush
   php bin/magento maintenance:status  # Should still show enabled
   php bin/magento maintenance:disable
   ```

## Configuration

Add a `cache/maintenance` section to your `app/etc/env.php` file. When not configured, the module gracefully falls back
to Magento's default filesystem behavior.

### Redis Backend (Recommended)

```php
'cache' => [
    'maintenance' => [
        'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
        'backend_options' => [
            'server' => '127.0.0.1',
            'database' => '14',
            'port' => '6379',
            'password' => '',
            'timeout' => '2.5',
            'compression_threshold' => '2048',
            'compression_library' => 'gzip',   
            'automatic_cleaning_factor' => 0
        ]
    ]
]
```

### Memcached Backend Example (untested).

```php
'cache' => [
    'maintenance' => [
        'backend' => 'Magento\\Framework\\Cache\\Backend\\Memcached',
        'backend_options' => [
            'servers' => [
                [
                    'host' => 'localhost',
                    'port' => 11211,
                    'weight' => 1
                ]
            ],
            'compression' => true,
            'compatibility' => false
        ]
    ]
]
```

## Usage

The module works transparently with all existing Magento maintenance commands:

```bash
# Enable maintenance mode
php bin/magento maintenance:enable

# Enable with IP whitelist
php bin/magento maintenance:enable --ip=192.168.1.1 --ip=10.0.0.1

# Check status
php bin/magento maintenance:status

# Allow additional IPs
php bin/magento maintenance:allow-ips 192.168.1.100

# Disable maintenance mode
php bin/magento maintenance:disable
```

## Architecture

### Plugin System

The module uses Magento's plugin system to intercept `MaintenanceMode` operations:

- `aroundIsOn()`: Checks cache for maintenance status and IP whitelist
- `aroundSet()`: Sets maintenance status in cache
- `aroundSetAddresses()`: Manages IP whitelist in cache

### Cache Key Structure

- **Maintenance Status**: `MAINTENANCE_MODE_STATUS`
- **IP Addresses**: `MAINTENANCE_MODE_ADDRESSES`

### Fallback Behavior

When cache is unavailable or not configured:

1. Service operations return `false` or empty arrays
2. Plugin methods call `$proceed()` to use original filesystem logic
3. System continues operating with filesystem-based maintenance mode
4. No exceptions thrown - graceful degradation

## Performance Considerations

### Redis Configuration

- **Dedicated Database**: Use a separate Redis database (14) to avoid conflicts
- **Persistence**: Configure Redis persistence to survive server restarts
- **Memory**: Maintenance data is minimal (~100 bytes per status)
- **Compression**: Enable compression for larger IP whitelists

### File Backend

- **Directory Permissions**: Ensure the web server has read/write access
- **Disk Space**: File backend uses minimal disk space
- **Performance**: Slower than Redis but suitable for single-server deployments

## Troubleshooting

### Maintenance Mode Not Persisting

**Problem**: Maintenance mode is disabled after `cache:flush`

**Solutions**:

1. Verify `cache/maintenance` configuration in `app/etc/env.php`
2. Check Redis connectivity and database configuration
3. Review system logs for cache backend errors
4. Test cache backend with: `php bin/magento cache:status`

### IP Whitelist Not Working

**Problem**: IP addresses are not properly whitelisted

**Solutions**:

1. Verify IP addresses are correctly stored: `php bin/magento maintenance:status`
2. Check cache backend connectivity
3. Review plugin logs for IP comparison issues
4. Test with filesystem fallback by removing cache configuration

### Cache Backend Errors

**Problem**: Cache backend connection failures

**Solutions**:

1. Check Redis/Memcached service status
2. Verify connection parameters (host, port, password)
3. Review system logs for specific error messages
4. Test connection manually with tools like `redis-cli`

### Performance Issues

**Problem**: Slow maintenance mode operations

**Solutions**:

1. Use Redis backend for better performance
2. Optimize cache backend configuration
3. Enable compression for large IP whitelists
4. Monitor cache backend performance metrics

## Development

### Custom Cache Backends

To implement a custom cache backend:

1. Extend `Magento\Framework\Cache\Backend\AbstractBackend`
2. Configure in `cache/maintenance/backend`
3. Ensure compatibility with cache frontend interface
4. Test fallback behavior when backend fails

## License

[Open Source License](LICENSE)
