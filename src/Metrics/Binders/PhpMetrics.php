<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Binders;

use Illuminate\Contracts\Foundation\Application;
use Nwidart\Actuator\Metrics\MeterBinder;
use Nwidart\Actuator\Metrics\MeterRegistry;

/**
 * The PHP runtime: versions, OPcache and the PHP-FPM pool. These are read
 * when the meters are collected, and describe the server that answers.
 *
 * There are no figures for the process itself (CPU, uptime, memory): each
 * scrape is answered by a different short-lived worker.
 *
 * @internal
 */
final readonly class PhpMetrics implements MeterBinder
{
    public function __construct(private Application $app) {}

    public function bind(MeterRegistry $registry): void
    {
        $registry->describe('php.info', 'PHP version and server API.');
        $registry->describe('laravel.info', 'Laravel framework version.');
        $registry->describe('php.opcache.memory.used', 'OPcache memory in use.');
        $registry->describe('php.opcache.memory.free', 'OPcache memory still free.');
        $registry->describe('php.opcache.memory.wasted', 'OPcache memory held by outdated scripts.');
        $registry->describe('php.opcache.scripts.cached', 'Scripts held in OPcache.');
        $registry->describe('php.opcache.hits', 'OPcache hits.');
        $registry->describe('php.opcache.misses', 'OPcache misses.');
        $registry->describe('php.fpm.processes', 'PHP-FPM worker processes, by state.');
        $registry->describe('php.fpm.listen.queue', 'Requests waiting for a free PHP-FPM worker.');
        $registry->describe('php.fpm.connections.accepted', 'Requests accepted by the PHP-FPM pool.');
        $registry->describe('php.fpm.max.children.reached', 'Times the PHP-FPM pool reached its process limit.');
        $registry->describe('php.fpm.slow.requests', 'Requests slower than the PHP-FPM slow log timeout.');

        $registry->collecting(function (MeterRegistry $registry): void {
            $registry->gauge('php.info', ['version' => PHP_VERSION, 'sapi' => PHP_SAPI], static fn (): int => 1);
            $registry->gauge('laravel.info', ['version' => $this->app->version()], static fn (): int => 1);

            if ($registry->enabled('php.opcache') && function_exists('opcache_get_status')) {
                $this->opcache($registry, opcache_get_status(false));
            }

            if ($registry->enabled('php.fpm') && function_exists('fpm_get_status')) {
                $this->fpm($registry, fpm_get_status());
            }
        });
    }

    private function opcache(MeterRegistry $registry, mixed $status): void
    {
        // The status is false when OPcache is off, as it is on the command line.
        if (! is_array($status)) {
            return;
        }

        $this->gauge($registry, 'php.opcache.memory.used', data_get($status, 'memory_usage.used_memory'), unit: 'bytes');
        $this->gauge($registry, 'php.opcache.memory.free', data_get($status, 'memory_usage.free_memory'), unit: 'bytes');
        $this->gauge($registry, 'php.opcache.memory.wasted', data_get($status, 'memory_usage.wasted_memory'), unit: 'bytes');
        $this->gauge($registry, 'php.opcache.scripts.cached', data_get($status, 'opcache_statistics.num_cached_scripts'));
        $this->counter($registry, 'php.opcache.hits', data_get($status, 'opcache_statistics.hits'));
        $this->counter($registry, 'php.opcache.misses', data_get($status, 'opcache_statistics.misses'));
    }

    private function fpm(MeterRegistry $registry, mixed $status): void
    {
        if (! is_array($status)) {
            return;
        }

        $this->gauge($registry, 'php.fpm.processes', $status['active-processes'] ?? null, ['state' => 'active']);
        $this->gauge($registry, 'php.fpm.processes', $status['idle-processes'] ?? null, ['state' => 'idle']);
        $this->gauge($registry, 'php.fpm.listen.queue', $status['listen-queue'] ?? null);
        $this->counter($registry, 'php.fpm.connections.accepted', $status['accepted-conn'] ?? null);
        $this->counter($registry, 'php.fpm.max.children.reached', $status['max-children-reached'] ?? null);
        $this->counter($registry, 'php.fpm.slow.requests', $status['slow-requests'] ?? null);
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function gauge(MeterRegistry $registry, string $name, mixed $value, array $tags = [], string $unit = ''): void
    {
        if (is_int($value) || is_float($value)) {
            $registry->gauge($name, $tags, static fn (): int|float => $value, $unit);
        }
    }

    private function counter(MeterRegistry $registry, string $name, mixed $value): void
    {
        if (is_int($value) || is_float($value)) {
            $registry->counter($name, [], static fn (): int|float => $value);
        }
    }
}
