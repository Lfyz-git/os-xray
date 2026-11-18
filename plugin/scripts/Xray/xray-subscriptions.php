<?php
// Subscription input is intentionally small: VLESS links (plain or base64),
// or an Xray JSON object with an outbounds array.
function xray_subscription_normalize_tag(string $name, array &$used, int $index): string
{
    $tag = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '');
    if ($tag === '') {
        $tag = 'node-' . $index;
    }
    if (strlen($tag) > 160) {
        throw new InvalidArgumentException('Node name is too long');
    }
    $candidate = $tag;
    $n = 2;
    while ($candidate === 'direct' || isset($used[$candidate])) {
        $candidate = $tag . '-' . $n++;
    }
    $used[$candidate] = true;
    return $candidate;
}

function xray_subscription_parse(string $input, callable $parseVless): array
{
    if (strlen($input) > 1048576) {
        throw new InvalidArgumentException('Subscription exceeds 1 MiB');
    }
    $input = trim($input);
    if ($input === '') {
        throw new InvalidArgumentException('Subscription is empty');
    }
    $used = [];
    $result = [];

    // PasarGuard's Xray JSON export can be used as well as VLESS share links.
    if ($input[0] === '{' || $input[0] === '[') {
        $decoded = json_decode($input, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid Xray JSON');
        }
        $entries = $decoded['outbounds'] ?? $decoded;
        if (!array_is_list($entries)) {
            throw new InvalidArgumentException('Expected an array of Xray outbounds');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['protocol'] ?? null)) {
                throw new InvalidArgumentException('Invalid outbound in JSON subscription');
            }
            if (($entry['protocol'] === 'freedom' && ($entry['tag'] ?? '') === 'direct') ||
                ($entry['protocol'] === 'blackhole' && ($entry['tag'] ?? '') === 'block')) {
                continue;
            }
            $entry['tag'] = xray_subscription_normalize_tag(
                (string)($entry['tag'] ?? ''), $used, count($result) + 1
            );
            $result[] = $entry;
        }
    } else {
        // A common subscription format is base64-encoded newline-separated URIs.
        if (!str_contains($input, '://') && preg_match('/^[A-Za-z0-9+\/=_\s-]+$/D', $input)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $input), true);
            if (is_string($decoded) && str_contains($decoded, '://')) {
                $input = $decoded;
            }
        }
        foreach (preg_split('/\r?\n/', $input) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_starts_with(strtolower($line), 'vless://')) {
                throw new InvalidArgumentException('Only VLESS links are supported in link subscriptions');
            }
            $parsed = $parseVless($line);
            if (isset($parsed['error'])) {
                throw new InvalidArgumentException('Invalid VLESS link at position ' . (count($result) + 1));
            }
            $entry = json_decode($parsed['outbound_config'] ?? '', true);
            if (!is_array($entry) || ($entry['protocol'] ?? '') !== 'vless') {
                throw new InvalidArgumentException('Invalid VLESS outbound');
            }
            $settings = $entry['settings'] ?? [];
            $fallback = (string)($settings['address'] ?? 'node-' . (count($result) + 1));
            $entry['tag'] = xray_subscription_normalize_tag(
                (string)($parsed['name'] ?: $fallback), $used, count($result) + 1
            );
            $result[] = $entry;
            if (count($result) > 256) {
                throw new InvalidArgumentException('Subscription exceeds 256 nodes');
            }
        }
    }

    if (!$result || count($result) > 256) {
        throw new InvalidArgumentException('Subscription must contain 1 to 256 proxy outbounds');
    }
    return $result;
}

function xray_subscription_node_summaries(array $outbounds): array
{
    $nodes = [];
    foreach ($outbounds as $ob) {
        $settings = $ob['settings'] ?? [];
        $vnext = $settings['vnext'][0] ?? [];
        $nodes[] = [
            'tag' => (string)($ob['tag'] ?? ''),
            'protocol' => (string)($ob['protocol'] ?? ''),
            'server' => (string)($settings['address'] ?? $vnext['address'] ?? ''),
            'port' => (string)($settings['port'] ?? $vnext['port'] ?? ''),
        ];
    }
    return $nodes;
}

/**
 * Each HTTPS source has a stable, random identifier; it is not derived from
 * hardware addresses, MAC addresses, or other machine-specific properties.
 */
function xray_subscription_hwid(string $value): string
{
    $value = trim($value);
    if ($value === '') return bin2hex(random_bytes(16));
    // Deliberately stricter than the provider's 256-char maximum: header-safe
    // ASCII identifiers are portable and cannot inject another HTTP header.
    if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $value)) {
        throw new InvalidArgumentException('HWID must contain 1-128 letters, digits, dots, underscores, colons or dashes');
    }
    return $value;
}

/** Identifying headers are identical for manual and scheduled refresh. */
function xray_subscription_headers(string $hwid, string $version = '3.2.1'): array
{
    if ($hwid === '') throw new InvalidArgumentException('HTTPS subscription HWID is missing');
    $hwid = xray_subscription_hwid($hwid);
    $osVersion = 'unknown';
    $versionFile = '/usr/local/opnsense/version/core';
    if (is_readable($versionFile)) {
        $read = trim((string)@file_get_contents($versionFile));
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $read)) $osVersion = $read;
    }
    return [
        'User-Agent: os-xray/' . $version,
        'X-HWID: ' . $hwid,
        'X-Device-OS: OPNsense',
        'X-Ver-OS: ' . $osVersion,
        'X-Device-Model: OPNsense Firewall',
        'Accept: text/plain, application/json',
    ];
}

/**
 * Validate every DNS result before choosing a public endpoint. The caller
 * must pin this address with CURLOPT_RESOLVE, so a second DNS lookup cannot
 * substitute a private or loopback host after validation.
 */
function xray_subscription_public_ip(array $records): string
{
    $addresses = [];
    foreach ($records as $record) {
        $address = $record['ip'] ?? $record['ipv6'] ?? null;
        if (!is_string($address) || !filter_var(
            $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new InvalidArgumentException('Subscription host resolves to an invalid or private address');
        }
        $addresses[] = $address;
    }
    if (!$addresses) throw new RuntimeException('Subscription host could not be resolved');
    // Prefer IPv4 when available; IPv6 remains supported if no A record exists.
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $address;
    }
    return $addresses[0];
}

/**
 * Download HTTPS subscriptions via the OPNsense core PHP cURL extension.
 * TLS verification, SSRF protections, DNS pinning, timeout, redirect refusal
 * and a strict response-size cap are mandatory; do not log tokenized URLs.
 */
function xray_subscription_download(string $url, string $hwid): string
{
    $parts = parse_url($url);
    $host = is_array($parts) ? ($parts['host'] ?? '') : '';
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !is_string($host) || !preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $host)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || !function_exists('curl_init')) {
        throw new InvalidArgumentException('Subscription URL requires HTTPS, a valid hostname and PHP cURL');
    }

    $port = $parts['port'] ?? 443;
    if (!is_int($port) || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Invalid HTTPS subscription port');
    }
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records)) throw new RuntimeException('Subscription host could not be resolved');
    $ip = xray_subscription_public_ip($records);
    $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

    $headers = xray_subscription_headers($hwid);
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Cannot initialize HTTPS subscription download');
    $body = '';
    $tooLarge = false;
    try {
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                $length = strlen($chunk);
                if ($length > 1048576 - strlen($body)) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return $length;
            },
        ];
        if (!curl_setopt_array($curl, $options)) {
            throw new RuntimeException('Cannot configure HTTPS subscription download');
        }
        $ok = @curl_exec($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($tooLarge) throw new RuntimeException('Subscription response exceeds 1 MiB');
        if (!$ok) {
            $errorCode = curl_errno($curl);
            $reason = match ($errorCode) {
                CURLE_OPERATION_TIMEDOUT => 'connection timeout',
                CURLE_COULDNT_RESOLVE_HOST => 'DNS lookup failed',
                CURLE_COULDNT_CONNECT => 'connection refused or unavailable',
                CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CONNECT_ERROR => 'TLS certificate or handshake error',
                default => 'network or TLS error',
            };
            throw new RuntimeException('Cannot download subscription (' . $reason . ')');
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            // Status is safe to report; URLs and response bodies are not.
            throw new RuntimeException('Cannot download subscription (HTTP ' . $httpStatus . ')');
        }
        if ($body === '') throw new RuntimeException('Subscription download is empty');
        return $body;
    } finally {
        curl_close($curl);
    }
}

function xray_subscription_import(
    string $type, string $url, string $links, callable $parseVless, string $hwid = ''
): array {
    if ($type === 'url') {
        $body = xray_subscription_download(trim($url), $hwid);
    } elseif ($type === 'links') {
        $body = $links;
    } else {
        throw new InvalidArgumentException('Unknown subscription source type');
    }
    return xray_subscription_parse($body, $parseVless);
}

/** Keep the selected node pinned when a provider refreshes its list. */
function xray_subscription_has_selected_node(array $outbounds, string $selected): bool
{
    if ($selected === '') return true;
    foreach ($outbounds as $outbound) {
        if (($outbound['tag'] ?? null) === $selected) return true;
    }
    return false;
}

/**
 * A manual refresh must not replace a working node catalog if it drops a
 * configured Manual node, LeastLoad pool member or fallback. Automatic
 * refresh already checks these references before updating its cache.
 */
function xray_subscription_assert_selected_nodes(array $nodes, iterable $instances, string $sourceId): void
{
    $sourceId = strtolower($sourceId);
    foreach ($instances as $instance) {
        $primary = strtolower((string)($instance->subscription_uuid ?? ''));
        if ((string)($instance->route_mode ?? 'manual') !== 'leastLoad') {
            if ($primary === $sourceId && !xray_subscription_has_selected_node(
                $nodes, (string)($instance->selected_outbound ?? '')
            )) {
                throw new \RuntimeException('Selected Manual node is missing; previous subscription retained');
            }
            continue;
        }
        $refs = json_decode((string)($instance->balancer_nodes ?? '[]'), true);
        if (!is_array($refs)) $refs = [];
        $refs[] = (string)($instance->balancer_fallback ?? '');
        foreach ($refs as $ref) {
            if (!is_string($ref) || $ref === '') continue;
            [$refSource, $tag] = xray_balancer_parse_ref($ref, $primary);
            if ($refSource === $sourceId && !xray_subscription_has_selected_node($nodes, $tag)) {
                throw new \RuntimeException('Selected LeastLoad node is missing; previous subscription retained');
            }
        }
    }
}

/** Schedule due checks against the last attempt, including failed attempts. */
function xray_subscription_due(int $interval, int $lastChecked, int $now): bool
{
    return $interval > 0 && ($lastChecked <= 0 || $now - $lastChecked >= $interval * 60);
}

/**
 * Display the stored UTC refresh timestamp in OPNsense's configured timezone.
 * Only the grid display is converted; persisted timestamps remain in UTC.
 */
function xray_subscription_display_time(string $updatedAt, string $timezoneName): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$/D', $updatedAt)) {
        return $updatedAt;
    }
    try {
        $timezone = new \DateTimeZone($timezoneName !== '' ? $timezoneName : 'Etc/UTC');
    } catch (\Throwable $e) {
        $timezone = new \DateTimeZone('Etc/UTC');
    }
    try {
        $instant = new \DateTimeImmutable($updatedAt);
        return $instant->setTimezone($timezone)->format('Y-m-d H:i:s T');
    } catch (\Throwable $e) {
        return $updatedAt;
    }
}
