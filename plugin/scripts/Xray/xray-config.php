<?php

// Pure legacy configuration builders: no OPNsense, filesystem or process access.

// ─── Build routing block from comma-separated CIDR string ────────────────────
function xray_build_routing(string $bypassRaw): array
{
    $bypassNets = array_values(array_filter(array_map('trim', explode(',', $bypassRaw))));
    if (empty($bypassNets)) {
        $bypassNets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    }
    return [
        'domainStrategy' => 'IPIfNonMatch',
        'rules' => [[
            'type'        => 'field',
            'ip'          => $bypassNets,
            'outboundTag' => 'direct',
        ]],
    ];
}

/**
 * Canonical GUI reference: source UUID + "::" + original node tag.
 * Original tags can repeat across subscription sources.
 */
function xray_balancer_parse_ref(string $reference, string $primarySource): array
{
    if (preg_match('/^([0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12})::(.+)$/isD', $reference, $m)) {
        return [strtolower($m[1]), $m[2]];
    }
    // Existing single-source selections stay valid after upgrading PR2.
    return [$primarySource, $reference];
}

/**
 * Resolve the selected pool and fallback against all enabled subscriptions.
 * Only selected outbounds enter the generated Xray config; unrelated nodes
 * and credentials from other sources never need to be included.
 */
function xray_prepare_balancer_sources(array $c, array $sources): array
{
    if (($c['route_mode'] ?? 'manual') !== 'leastLoad') {
        return $c;
    }
    $pool = json_decode((string)($c['balancer_nodes'] ?? '[]'), true);
    if (!is_array($pool) || !array_is_list($pool) || count($pool) < 2) {
        throw new InvalidArgumentException('LeastLoad requires at least two pool nodes');
    }
    $fallback = (string)($c['balancer_fallback'] ?? '');
    if ($fallback === '') {
        throw new InvalidArgumentException('LeastLoad fallback is required');
    }
    $primary = strtolower((string)($c['subscription_uuid'] ?? ''));
    // Leave already-deployed single-source LeastLoad settings untouched until
    // the user explicitly saves source-qualified selections in the GUI.
    $qualified = false;
    foreach (array_merge($pool, [$fallback]) as $reference) {
        if (is_string($reference) &&
            preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}::/i', $reference)) {
            $qualified = true;
            break;
        }
    }
    if (!$qualified) {
        return $c;
    }
    $newPool = [];
    $newFallback = '';
    $outbounds = [];
    $seen = [];

    foreach (array_merge($pool, [$fallback]) as $index => $reference) {
        if (!is_string($reference) || $reference === '') {
            throw new InvalidArgumentException('Invalid LeastLoad node reference');
        }
        [$sourceId, $originalTag] = xray_balancer_parse_ref($reference, $primary);
        if ($sourceId === '' || $originalTag === '' || $originalTag === 'direct' ||
            !array_key_exists($sourceId, $sources)) {
            throw new InvalidArgumentException('Selected subscription or node is unavailable');
        }
        $list = json_decode((string)$sources[$sourceId], true);
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException('Selected subscription has no valid node cache');
        }
        $matches = array_values(array_filter($list, static function ($node) use ($originalTag) {
            return is_array($node) && ($node['tag'] ?? null) === $originalTag;
        }));
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('Selected node is missing or ambiguous in its subscription');
        }
        $key = $sourceId . "::" . $originalTag;
        // Hash first: Xray's selector uses a prefix, so raw display tags are
        // unsafe when names in different feeds collide or prefix one another.
        $display = trim(substr(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $originalTag), 0, 36), '_');
        $tag = 'node-' . substr(hash('sha256', $key), 0, 20) . '-' . ($display ?: 'proxy');
        if (!isset($seen[$key])) {
            $outbound = $matches[0];
            $outbound['tag'] = $tag;
            $outbounds[] = $outbound;
            $seen[$key] = true;
        } elseif ($index < count($pool)) {
            throw new InvalidArgumentException('Duplicate node in LeastLoad pool');
        }
        if ($index < count($pool)) {
            $newPool[] = $tag;
        } else {
            $newFallback = $tag;
        }
    }
    $c['outbound_config'] = json_encode($outbounds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $c['balancer_nodes'] = json_encode($newPool, JSON_THROW_ON_ERROR);
    $c['balancer_fallback'] = $newFallback;
    return $c;
}

/**
 * Compile a short ordered GUI ruleset, never a raw arbitrary Xray config.
 * Each entry is {kind, match, target}, where target is proxy/direct/block.
 */
function xray_custom_rules(string $raw, bool $sniffingEnabled): array
{
    try {
        $rules = json_decode(trim($raw) === '' ? '[]' : $raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Custom routing rules must be a JSON array');
    }
    if (!is_array($rules) || !array_is_list($rules) || count($rules) > 50) {
        throw new InvalidArgumentException('Custom routing requires an array of at most 50 rules');
    }
    $parsed = [];
    foreach ($rules as $position => $rule) {
        if (!is_array($rule) || array_is_list($rule) ||
            array_diff(array_keys($rule), ['kind', 'match', 'target'])) {
            throw new InvalidArgumentException('Invalid custom routing rule #' . ($position + 1));
        }
        $kind = $rule['kind'] ?? null;
        $match = $rule['match'] ?? null;
        $target = $rule['target'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['protocol', 'domain', 'ip', 'port'], true) ||
            !is_string($match) || trim($match) === '' ||
            !is_string($target) || !in_array($target, ['proxy', 'direct', 'block'], true)) {
            throw new InvalidArgumentException('Invalid condition or action in routing rule #' . ($position + 1));
        }
        $match = trim($match);
        $field = ['type' => 'field', 'inboundTag' => ['socks-in']];
        if ($kind === 'protocol') {
            if (!$sniffingEnabled) {
                throw new InvalidArgumentException('Protocol routing requires Sniffing enabled');
            }
            $values = array_values(array_filter(array_map('trim', explode(',', strtolower($match)))));
            if (!$values || array_diff($values, ['http', 'tls', 'quic', 'bittorrent'])) {
                throw new InvalidArgumentException('Unsupported sniffed protocol in routing rule #' . ($position + 1));
            }
            $field['protocol'] = $values;
        } elseif ($kind === 'domain' || $kind === 'ip') {
            $values = array_values(array_filter(array_map('trim', explode(',', $match)), 'strlen'));
            if (!$values || count($values) > 100) {
                throw new InvalidArgumentException('Invalid domain/IP list in routing rule #' . ($position + 1));
            }
            $field[$kind] = $values;
        } else {
            if (!preg_match('/^[0-9]{1,5}(?:-[0-9]{1,5})?(?:\s*,\s*[0-9]{1,5}(?:-[0-9]{1,5})?)*$/D', $match)) {
                throw new InvalidArgumentException('Invalid port list in routing rule #' . ($position + 1));
            }
            foreach (explode(',', $match) as $portRange) {
                $endpoints = array_map('intval', explode('-', trim($portRange)));
                if (min($endpoints) < 1 || max($endpoints) > 65535 || $endpoints[0] > end($endpoints)) {
                    throw new InvalidArgumentException('Port must be between 1 and 65535');
                }
            }
            $field['port'] = $match;
        }
        $parsed[] = ['field' => $field, 'target' => $target];
    }
    return $parsed;
}

function xray_build_config(array $c): array
{
    $raw = trim($c['outbound_config'] ?? '');
    if ($raw === '') {
        throw new InvalidArgumentException("outbound_config is empty");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException("outbound_config must be a JSON outbound object or array");
    }
    $outbounds = array_is_list($decoded) ? $decoded : [$decoded];
    if (!$outbounds) {
        throw new InvalidArgumentException("At least one Xray outbound is required");
    }
    $tags = ['direct' => true, 'block' => true];  // Reserved local routing outbounds.
    foreach ($outbounds as $outbound) {
        if (!is_array($outbound) || !is_string($outbound['protocol'] ?? null)
            || trim($outbound['protocol']) === '') {
            throw new InvalidArgumentException("Each outbound must define a protocol");
        }
        $tag = $outbound['tag'] ?? null;
        if ($tag === null && count($outbounds) === 1) {
            continue; // Xray accepts an untagged default outbound.
        }
        if (!is_string($tag) || trim($tag) === '' || isset($tags[$tag])) {
            throw new InvalidArgumentException("Outbound tags must be unique, non-empty and not reserved (direct/block)");
        }
        $tags[$tag] = true;
    }

    $mode = trim((string)($c['route_mode'] ?? 'manual')) ?: 'manual';
    if (!in_array($mode, ['manual', 'leastLoad'], true)) {
        throw new InvalidArgumentException('Unknown Xray routing mode');
    }
    $routing = xray_build_routing($c['bypass_networks'] ?? '');
    $domainStrategy = (string)($c['routing_domain_strategy'] ?? 'IPIfNonMatch');
    if (!in_array($domainStrategy, ['AsIs', 'IPIfNonMatch', 'IPOnDemand'], true)) {
        throw new InvalidArgumentException('Invalid routing domain strategy');
    }
    $routing['domainStrategy'] = $domainStrategy;
    $burst = null;

    if ($mode === 'leastLoad') {
        // A balancer selector is a *prefix* match in Xray, not an exact match.
        // Reject ambiguous tags instead of quietly balancing unselected nodes.
        $pool = json_decode((string)($c['balancer_nodes'] ?? '[]'), true);
        if (!is_array($pool) || !array_is_list($pool) || count($pool) < 2) {
            throw new InvalidArgumentException('LeastLoad requires at least two pool nodes');
        }
        $chosen = [];
        foreach ($pool as $tag) {
            if (!is_string($tag) || $tag === '' || !isset($tags[$tag])
                || $tag === 'direct' || isset($chosen[$tag])) {
                throw new InvalidArgumentException('LeastLoad pool contains an invalid or duplicate node');
            }
            foreach (array_keys($tags) as $existing) {
                if ($existing !== $tag && str_starts_with($existing, $tag)) {
                    throw new InvalidArgumentException(
                        'Balancer tag prefix matches an unselected node: ' . $tag
                    );
                }
            }
            $chosen[$tag] = true;
        }
        $fallback = trim((string)($c['balancer_fallback'] ?? ''));
        if ($fallback === '' || $fallback === 'direct' || !isset($tags[$fallback])) {
            throw new InvalidArgumentException('LeastLoad requires a valid fallback outbound');
        }
        $expectedRaw = (string)($c['balancer_expected'] ?? '2');
        $toleranceRaw = (string)($c['balancer_tolerance'] ?? '0.2');
        if (!ctype_digit($expectedRaw) || (int)$expectedRaw < 1 || (int)$expectedRaw > 16) {
            throw new InvalidArgumentException('LeastLoad expected must be from 1 to 16');
        }
        if (!is_numeric($toleranceRaw) || !is_finite((float)$toleranceRaw)
            || (float)$toleranceRaw < 0 || (float)$toleranceRaw > 1) {
            throw new InvalidArgumentException('LeastLoad tolerance must be between 0 and 1');
        }

        // The first outbound is the implicit last resort if routing cannot pick
        // a balancer target. Keep that consistent with the explicit fallback.
        foreach ($outbounds as $index => $outbound) {
            if (($outbound['tag'] ?? '') === $fallback) {
                if ($index > 0) {
                    array_unshift($outbounds, array_splice($outbounds, $index, 1)[0]);
                }
                break;
            }
        }
        // A subscription is a source catalog, not an Xray runtime pool.
        // Emit only observed pool nodes and the explicit fallback.
        $outbounds = array_values(array_filter($outbounds, static function ($outbound) use ($chosen, $fallback) {
            $tag = $outbound['tag'] ?? '';
            return isset($chosen[$tag]) || $tag === $fallback;
        }));
        $routing['balancers'] = [[
            'tag' => 'proxy-balance',
            'selector' => array_keys($chosen),
            'fallbackTag' => $fallback,
            'strategy' => [
                'type' => 'leastLoad',
                'settings' => [
                    'expected' => (int)$expectedRaw,
                    'tolerance' => (float)$toleranceRaw,
                ],
            ],
        ]];
        $burst = [
            'subjectSelector' => array_keys($chosen),
            'pingConfig' => [
                'destination' => 'https://www.google.com/generate_204',
                'interval' => '1m',
                'sampling' => 10,
                'timeout' => '5s',
                'httpMethod' => 'GET',
            ],
        ];
    } else {
        $selected = trim((string)($c['selected_outbound'] ?? ''));
        if ($selected !== '') {
            $selectedIndex = null;
            foreach ($outbounds as $i => $outbound) {
                if (($outbound['tag'] ?? '') === $selected) {
                    $selectedIndex = $i;
                    break;
                }
            }
            if ($selectedIndex === null) {
                throw new InvalidArgumentException('Selected outbound is not present in the source');
            }
            if ($selectedIndex !== 0) {
                $preferred = $outbounds[$selectedIndex];
                array_splice($outbounds, $selectedIndex, 1);
                array_unshift($outbounds, $preferred);
            }
        }
        // Manual mode must launch exactly one proxy, not the entire source.
        // The separate 'direct' outbound is added below for private bypass.
        $outbounds = [$outbounds[0]];
    }

    // Protected private-net bypass stays first. User rules are ordered next,
    // and the LeastLoad catch-all is always last.
    $sniffingEnabled = (string)($c['sniffing_enabled'] ?? '0') === '1';
    $customRules = xray_custom_rules((string)($c['routing_rules'] ?? '[]'), $sniffingEnabled);
    $usesBlock = false;
    foreach ($customRules as $rule) {
        $field = $rule['field'];
        if ($rule['target'] === 'direct') {
            $field['outboundTag'] = 'direct';
        } elseif ($rule['target'] === 'block') {
            $field['outboundTag'] = 'block';
            $usesBlock = true;
        } elseif ($mode === 'leastLoad') {
            $field['balancerTag'] = 'proxy-balance';
        } else {
            // Single untagged manual outbounds are legal when no routing rule
            // references them. Give one a stable tag when explicitly targeted.
            if (!isset($outbounds[0]['tag'])) $outbounds[0]['tag'] = 'proxy-main';
            $field['outboundTag'] = $outbounds[0]['tag'];
        }
        $routing['rules'][] = $field;
    }
    if ($mode === 'leastLoad') {
        $routing['rules'][] = [
            'type' => 'field',
            'inboundTag' => ['socks-in'],
            'network' => 'tcp,udp',
            'balancerTag' => 'proxy-balance',
        ];
    }

    $outbounds[] = ['tag' => 'direct', 'protocol' => 'freedom'];
    if ($usesBlock) {
        $outbounds[] = ['tag' => 'block', 'protocol' => 'blackhole'];
    }
    $result = [
        'log'      => ['loglevel' => $c['loglevel'] ?? 'warning'],
        'inbounds' => [[
            'tag'      => 'socks-in',
            'port'     => (int)($c['socks5_port'] ?? 10808),
            'listen'   => $c['socks5_listen'] ?? '127.0.0.1',
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => $c['socks5_listen'] ?? '127.0.0.1'],
        ]],
        'outbounds' => $outbounds,
        'routing'   => $routing,
    ];
    if ($sniffingEnabled) {
        $result['inbounds'][0]['sniffing'] = [
            'enabled' => true,
            'destOverride' => ['http', 'tls'],
            'routeOnly' => (string)($c['sniffing_route_only'] ?? '1') !== '0',
        ];
    }
    if ($burst !== null) {
        $result['burstObservatory'] = $burst;
    }
    return $result;
}

function yaml_scalar(string $value): string
{
    // Keep simple scalars unchanged; quote everything with YAML-significant
    // characters. json_encode() produces a valid double-quoted YAML scalar.
    if ($value !== '' && preg_match('/^[A-Za-z0-9._\/@+\-]+$/D', $value)) {
        return $value;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $encoded !== false ? $encoded : '""';
}

function xray_build_t2s_config(array $c): string
{
    $proxyUri  = 'socks5://' . $c['socks5_listen'] . ':' . $c['socks5_port'];
    return 'proxy: ' . yaml_scalar($proxyUri) . "\n"
          . 'device: ' . yaml_scalar((string)$c['tun_iface']) . "\n"
          . 'mtu: ' . (int)$c['mtu'] . "\n"
          . "loglevel: info\n";
}
