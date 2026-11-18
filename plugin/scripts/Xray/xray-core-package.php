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
    if ($operation === 'upgrade' && core_pkg_running()) {
        core_pkg_reply(['result' => 'blocked', 'message' => 'Stop all Xray and tun2socks instances before updating the core']);
    }
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
if ($state['running'] || core_pkg_running()) {
    core_pkg_reply(['result' => 'blocked', 'message' => 'Xray or tun2socks is still running']);
}

// Before updating, reject bad applied configurations. No Apply or auto restart.
$configs = glob('/usr/local/etc/xray-core/config-*.json') ?: [];
foreach ($configs as $file) {
    [$testStatus] = core_pkg_exec(['/usr/local/bin/xray', 'run', '-test', '-c', $file]);
    if ($testStatus !== 0) {
        core_pkg_reply(['result' => 'error', 'message' => 'Existing applied configuration failed validation; no update attempted']);
    }
}

[$upgradeStatus] = core_pkg_exec([$pkg, 'upgrade', '-y', '-r', 'OPNsense', 'xray-core']);
if ($upgradeStatus !== 0 || !is_executable('/usr/local/bin/xray')) {
    core_pkg_reply(['result' => 'error', 'message' => 'Package upgrade failed; inspect the pkg logs before starting Xray']);
}
foreach ($configs as $file) {
    [$testStatus] = core_pkg_exec(['/usr/local/bin/xray', 'run', '-test', '-c', $file]);
    if ($testStatus !== 0) {
        core_pkg_reply(['result' => 'error', 'message' => 'New Xray-core failed configuration validation; instances remain stopped']);
    }
}
$updated = core_pkg_state($pkg);
$updated['message'] = 'Xray-core package updated. Instances were not restarted; start them explicitly after checking.';
core_pkg_reply($updated);
