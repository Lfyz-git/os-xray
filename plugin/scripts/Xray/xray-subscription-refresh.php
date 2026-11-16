#!/usr/local/bin/php
<?php

// OPNsense cron entry: refresh only due HTTPS sources, retain working nodes on
// failures, and apply only when a used source actually changes.
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once 'config.inc';
require_once __DIR__ . '/xray-subscriptions.php';
require_once __DIR__ . '/xray-config.php';
require_once '/usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/Api/ImportController.php';

use OPNsense\Core\Config;
use OPNsense\Xray\Api\ImportController;

function xray_subscription_instance_uses_source($instance, string $sourceId): bool
{
    $primary = strtolower((string)($instance->subscription_uuid ?? ''));
    if ((string)($instance->route_mode ?? 'manual') !== 'leastLoad') {
        return $primary === $sourceId;
    }
    $refs = json_decode((string)($instance->balancer_nodes ?? '[]'), true);
    if (!is_array($refs)) $refs = [];
    $refs[] = (string)($instance->balancer_fallback ?? '');
    foreach ($refs as $ref) {
        if (!is_string($ref) || $ref === '') continue;
        [$id, $name] = xray_balancer_parse_ref($ref, $primary);
        if ($id === $sourceId) return true;
    }
    return false;
}

function xray_subscription_validate_candidate(array $sources, $instance): void
{
    $primary = strtolower((string)($instance->subscription_uuid ?? ''));
    $mode = (string)($instance->route_mode ?? 'manual');
    $candidateSettings = [
        'outbound_config' => $sources[$primary] ?? '',
        'subscription_uuid' => $primary,
        'selected_outbound' => (string)($instance->selected_outbound ?? ''),
        'route_mode' => $mode,
        'balancer_nodes' => (string)($instance->balancer_nodes ?? '[]'),
        'balancer_fallback' => (string)($instance->balancer_fallback ?? ''),
        'balancer_expected' => (string)($instance->balancer_expected ?? '2'),
        'balancer_tolerance' => (string)($instance->balancer_tolerance ?? '0.2'),
        'sniffing_enabled' => (string)($instance->sniffing_enabled ?? '0'),
        'sniffing_route_only' => (string)($instance->sniffing_route_only ?? '1'),
        'routing_rules' => (string)($instance->routing_rules ?? '[]'),
        'routing_domain_strategy' => (string)($instance->routing_domain_strategy ?? 'IPIfNonMatch'),
        'socks5_listen' => (string)($instance->socks5_listen ?? '127.0.0.1'),
        'socks5_port' => (int)($instance->socks5_port ?? 10808),
        'bypass_networks' => (string)($instance->bypass_networks ?? ''),
        'loglevel' => (string)($instance->loglevel ?? 'warning'),
    ];
    if ($mode === 'leastLoad') {
        $candidateSettings = xray_prepare_balancer_sources($candidateSettings, $sources);
    } else {
        $nodes = json_decode((string)($sources[$primary] ?? ''), true);
        if (!is_array($nodes) || !xray_subscription_has_selected_node(
            $nodes, $candidateSettings['selected_outbound']
        )) {
            throw new RuntimeException('Selected manual node is missing; previous subscription retained');
        }
    }
    $candidate = xray_build_config($candidateSettings);
    $bin = '/usr/local/bin/xray-core';
    if (!is_executable($bin)) {
        throw new RuntimeException('Xray binary is unavailable');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xray-check-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create config validation file');
    }
    try {
        if (!chmod($tmp, 0600) ||
            file_put_contents($tmp, json_encode($candidate, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Unable to write config validation file');
        }
        $output = [];
        $status = 1;
        exec(escapeshellarg($bin) . ' run -test -c ' . escapeshellarg($tmp)
            . ' -format=json 2>&1', $output, $status);
        if ($status !== 0) {
            // Error output from the core can include node details; do not log it.
            throw new RuntimeException('Xray candidate validation failed');
        }
    } finally {
        @unlink($tmp);
    }
}

$mutex = fopen('/var/run/xray_subscription_refresh.lock', 'c');
if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) {
    exit(0); // another refresh is running
}

$config = Config::getInstance();
$config->lock();
$reload = false;
$saved = false;
try {
    $xml = $config->object();
    $sources = $xml->OPNsense->xray->subscriptions->subscription ?? [];
    $instances = $xml->OPNsense->xray->instances->instance ?? [];
    $availableSources = [];
    foreach ($sources as $source) {
        if ((string)($source->enabled ?? '1') !== '1') continue;
        $availableSources[strtolower((string)$source['uuid'])] = (string)($source->outbounds ?? '');
    }
    $now = time();

    foreach ($sources as $source) {
        if ((string)($source->enabled ?? '1') !== '1' ||
            (string)($source->source_type ?? 'url') !== 'url') {
            continue;
        }
        $interval = (int)(string)($source->refresh_interval ?? '360');
        $last = (int)(string)($source->last_checked ?? '0');
        if (!xray_subscription_due($interval, $last, $now)) {
            continue;
        }
        $id = (string)$source['uuid'];
        $source->last_checked = (string)$now;
        $saved = true;
        try {
            $list = xray_subscription_import(
                'url', (string)$source->url, '',
                [ImportController::class, 'parseVless']
            );
            $json = json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $old = (string)($source->outbounds ?? '');
            if ($json !== $old) {
                $prospective = $availableSources;
                $prospective[$id] = $json;
                foreach ($instances as $instance) {
                    if (xray_subscription_instance_uses_source($instance, $id)) {
                        xray_subscription_validate_candidate($prospective, $instance);
                        if ((string)($instance->enabled ?? '1') === '1') {
                            $reload = true;
                        }
                    }
                }
                $source->outbounds = $json;
                $source->node_count = (string)count($list);
                $availableSources[$id] = $json;
            }
            $source->updated_at = gmdate('Y-m-d H:i:s', $now) . ' UTC';
            $source->last_error = '';
        } catch (Throwable $e) {
            $source->last_error = 'Refresh failed; previous nodes retained';
            error_log('Xray subscription refresh failed for source ' . $id);
        }
    }
    if ($saved) {
        $config->save(['description' => 'Xray subscription auto-refresh']);
    }
} finally {
    $config->unlock();
    flock($mutex, LOCK_UN);
    fclose($mutex);
}

if ($reload && (string)($xml->OPNsense->xray->general->enabled ?? '0') === '1') {
    // Existing Apply path validates all candidates before changing traffic,
    // skips unchanged instances, and performs runtime rollback on failures.
    $output = [];
    $status = 1;
    exec('/usr/local/sbin/configctl -d xray reconfigure 2>&1', $output, $status);
    if ($status !== 0) {
        error_log('Xray subscription auto-refresh: config reconfigure failed');
        exit(1);
    }
}
