#!/usr/local/bin/php
<?php
/**
 * Xray-core package management for the native os-xray package.
 *
 * Works ONLY with the OPNsense-managed xray-core package. No GitHub binary
 * downloads, no support for installer-managed /usr/local/bin/xray-core.
 * All operations have fixed arguments; no request data reaches a shell.
 */
declare(strict_types=1);

function core_pkg_reply(array $result): never
{
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
    exit(0);
}

function core_pkg_exec(array $argv): array
{
    $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    $output = [];
    $status = 0;
    exec($cmd, $output, $status);
    return [$status, trim(implode("\n", $output))];
}

function core_pkg_running(): bool
{
    // Refuse to touch a shared core while ANY Xray or tun2socks process runs.
    foreach (['xray', 'xray-core', 'tun2socks'] as $name) {
        [$status] = core_pkg_exec(['/usr/bin/pgrep', '-x', $name]);
        if ($status === 0) {
            return true;
        }
    }
    foreach (glob('/var/run/xray_core_*.pid') ?: [] as $file) {
        $pid = (int)trim((string)@file_get_contents($file));
        if ($pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return true;
        }
    }
    return false;
}

/**
 * Capture the exact set of running managed instances. Other process ownership
 * is checked separately; an unrecognized Xray process blocks the upgrade.
 */
function core_pkg_running_instances(): ?array
{
    [$status, $json] = core_pkg_exec(['/usr/local/sbin/configctl', 'xray', 'statusall']);
    $instances = json_decode($json, true);
    if ($status !== 0 || !is_array($instances)) {
        return null;
    }
    $running = [];
    foreach ($instances as $uuid => $info) {
        if (!is_string($uuid) || !is_array($info) ||
            !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $uuid)) {
            return null;
        }
        if (($info['xray_core'] ?? '') === 'running' ||
            ($info['tun2socks'] ?? '') === 'running') {
            $running[] = $uuid;
        }
    }
    return $running;
}

function core_pkg_control(string $action, string $uuid): bool
{
    [$status, $output] = core_pkg_exec(['/usr/local/sbin/configctl', 'xray', $action, $uuid]);
    return $status === 0 && !preg_match('/\\b(?:ERROR|failed)\\b/i', $output);
}

function core_pkg_safe_version(string $value): ?string
{
    $value = trim($value);
    return preg_match('/^[0-9A-Za-z._+-]{1,64}$/D', $value) ? $value : null;
}

function core_pkg_state(string $pkg): array
{
    [$status, $local] = core_pkg_exec([$pkg, 'query', '%v', 'xray-core']);
    if ($status !== 0 || !($local = core_pkg_safe_version($local))) {
        return [
            'result' => 'unmanaged',
            'message' => 'Xray-core is not installed as an OPNsense package. Native package installation required.',
            'running' => core_pkg_running(),
        ];
    }
    if (!is_executable('/usr/local/bin/xray')) {
        return ['result' => 'error', 'message' => 'Package xray-core is installed, but /usr/local/bin/xray is missing'];
    }
    [$status, $remote] = core_pkg_exec([$pkg, 'rquery', '-r', 'OPNsense', '%v', 'xray-core']);
    $available = $status === 0 ? core_pkg_safe_version($remote) : null;
    $update = false;
    if ($available !== null) {
        [$compareStatus, $compare] = core_pkg_exec([$pkg, 'version', '-t', $local, $available]);
        $update = $compareStatus === 0 && trim($compare) === '<';
    }
    return [
        'result' => 'ok',
        'installed' => $local,
        'available' => $available,
        'update_available' => $update,
        'running' => core_pkg_running(),
        'repository' => 'OPNsense',
    ];
}

$operation = $argv[1] ?? 'status';
if (!in_array($operation, ['status', 'check', 'upgrade'], true) || count($argv) > 2) {
    core_pkg_reply(['result' => 'error', 'message' => 'Invalid operation']);
}
$pkg = is_executable('/usr/local/sbin/pkg') ? '/usr/local/sbin/pkg' : '/usr/sbin/pkg';
if (!is_executable($pkg)) {
    core_pkg_reply(['result' => 'error', 'message' => 'FreeBSD pkg is unavailable']);
}

if ($operation !== 'status') {
    $lock = @fopen('/var/run/xray-core-package.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        core_pkg_reply(['result' => 'error', 'message' => 'Another Xray-core package action is running']);
    }
}

if ($operation === 'check' || $operation === 'upgrade') {
    [$status] = core_pkg_exec([$pkg, 'update', '-q', '-r', 'OPNsense']);
    if ($status !== 0) {
        core_pkg_reply(['result' => 'error', 'message' => 'Unable to update OPNsense package catalog']);
    }
}

$state = core_pkg_state($pkg);
if ($operation !== 'upgrade' || $state['result'] !== 'ok') {
    core_pkg_reply($state);
}
if (!$state['update_available']) {
    core_pkg_reply(['result' => 'ok', 'message' => 'Xray-core is already up to date'] + $state);
}

// Validate before interrupting existing connections.
$configs = glob('/usr/local/etc/xray-core/config-*.json') ?: [];
foreach ($configs as $file) {
    [$testStatus] = core_pkg_exec(['/usr/bin/env', 'XRAY_LOCATION_ASSET=/usr/local/share/xray-core', '/usr/local/bin/xray', 'run', '-test', '-c', $file]);
    if ($testStatus !== 0) {
        core_pkg_reply(['result' => 'error', 'message' => 'Existing applied configuration failed validation; nothing stopped']);
    }
}
$restart = core_pkg_running_instances();
if ($restart === null) {
    core_pkg_reply(['result' => 'error', 'message' => 'Cannot identify managed instances; no tunnels stopped']);
}
if (core_pkg_running() && count($restart) === 0) {
    core_pkg_reply(['result' => 'blocked', 'message' => 'An unmanaged Xray or tun2socks process is running']);
}

// Controlled downtime: stop the running instances only. Explicitly stopped
// profiles are not restarted. Administrative stop flags disable watchdog.
$stopped = [];
foreach ($restart as $uuid) {
    if (!core_pkg_control('stop', $uuid)) {
        // No package changes have occurred. Restore already-stopped profiles.
        foreach ($stopped as $id) {
            core_pkg_control('start', $id);
        }
        core_pkg_reply(['result' => 'error', 'message' => 'Could not safely stop all running instances; upgrade cancelled']);
    }
    $stopped[] = $uuid;
}
if (core_pkg_running()) {
    foreach ($stopped as $id) {
        core_pkg_control('start', $id);
    }
    core_pkg_reply(['result' => 'blocked', 'message' => 'Xray or tun2socks is still running; no package update attempted']);
}

[$upgradeStatus] = core_pkg_exec([$pkg, 'upgrade', '-y', '-r', 'OPNsense', 'xray-core']);
if ($upgradeStatus !== 0 || !is_executable('/usr/local/bin/xray')) {
    core_pkg_reply(['result' => 'error', 'message' => 'Package upgrade failed; tunnels remain stopped for manual recovery']);
}
foreach ($configs as $file) {
    [$testStatus] = core_pkg_exec(['/usr/bin/env', 'XRAY_LOCATION_ASSET=/usr/local/share/xray-core', '/usr/local/bin/xray', 'run', '-test', '-c', $file]);
    if ($testStatus !== 0) {
        core_pkg_reply(['result' => 'error', 'message' => 'New Xray-core failed configuration validation; tunnels remain stopped']);
    }
}
// Restart only instances that were running before the upgrade.
$failedStarts = [];
foreach ($stopped as $uuid) {
    if (!core_pkg_control('start', $uuid)) {
        $failedStarts[] = $uuid;
    }
}
$updated = core_pkg_state($pkg);
if ($failedStarts) {
    $updated['result'] = 'error';
    $updated['message'] = 'Core updated, but some previously running tunnels could not restart. Inspect Xray status and logs.';
} else {
    $updated['message'] = 'Core updated and previously running tunnels restarted. Stopped profiles remain stopped.';
}
$updated['restarted'] = count($stopped) - count($failedStarts);
core_pkg_reply($updated);
