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
 * Read HTTPS subscription content without PHP allow_url_fopen.
 * Only HTTPS URLs are accepted; do not log raw fetch output containing tokens.
 */
function xray_subscription_download(string $url): string
{
    $parts = parse_url($url);
    $host = is_array($parts) ? ($parts['host'] ?? '') : '';
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !is_string($host) || !preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $host)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new \InvalidArgumentException('Subscription URL must be HTTPS without credentials or fragment');
    }
    // Block literal and DNS-resolved private/reserved addresses.
    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    if (empty($records)) {
        throw new \RuntimeException('Subscription host could not be resolved');
    }
    foreach ($records as $record) {
        $address = $record['ip'] ?? $record['ipv6'] ?? null;
        if ($address !== null && !filter_var(
            $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new \InvalidArgumentException('Subscription host resolves to a private or reserved address');
        }
    }
    // OPNsense's WebGui php.ini deliberately disables allow_url_fopen.
    // Use the system HTTPS fetch utility instead of fopen(https://...).
    // Keep TLS verification enabled and disable automatic 302 redirects.
    $temp = tempnam(sys_get_temp_dir(), 'xray-sub-');
    if ($temp === false || !chmod($temp, 0600)) {
        throw new \RuntimeException('Cannot create private subscription download file');
    }

    try {
        $command = '/usr/bin/fetch -A -d -q -T 12 -o '
            . escapeshellarg($temp) . ' ' . escapeshellarg($url) . ' 2>&1';
        $output = [];
        $status = 1;
        exec($command, $output, $status);
        if ($status !== 0) {
            // fetch diagnostic messages may contain the tokenized URL;
            // classify the failure without returning or logging raw output.
            $details = strtolower(implode("\n", $output));
            if (str_contains($details, 'certificate') || str_contains($details, 'ssl')
                || str_contains($details, 'tls')) {
                $reason = 'TLS certificate or handshake error';
            } elseif (str_contains($details, 'refused')) {
                $reason = 'connection refused';
            } elseif (str_contains($details, 'timed out') || str_contains($details, 'timeout')) {
                $reason = 'connection timeout';
            } elseif (str_contains($details, 'resolve') || str_contains($details, 'no address')
                || str_contains($details, 'name lookup')) {
                $reason = 'DNS lookup failed';
            } elseif (preg_match('/\b(30[0-9]|40[0-9]|50[0-9])\b/', $details, $matches)) {
                $reason = 'HTTP ' . $matches[1];
            } else {
                $reason = 'network or HTTP error';
            }
            throw new \RuntimeException('Cannot download subscription (' . $reason . ')');
        }
        $size = filesize($temp);
        if ($size === false || $size > 1048576) {
            throw new \RuntimeException('Subscription response exceeds 1 MiB');
        }
        $body = file_get_contents($temp);
        if ($body === false || $body === '') {
            throw new \RuntimeException('Subscription download is empty or unreadable');
        }
        return $body;
    } finally {
        @unlink($temp);
    }
}

function xray_subscription_import(string $type, string $url, string $links, callable $parseVless): array
{
    if ($type === 'url') {
        $body = xray_subscription_download(trim($url));
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
