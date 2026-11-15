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
