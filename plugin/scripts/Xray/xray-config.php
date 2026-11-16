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
    $tags = ['direct' => true];  // The local bypass outbound is always reserved.
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
            throw new InvalidArgumentException("Outbound tags must be non-empty, unique and not 'direct'");
        }
        $tags[$tag] = true;
    }

    $mode = trim((string)($c['route_mode'] ?? 'manual')) ?: 'manual';
    if (!in_array($mode, ['manual', 'leastLoad'], true)) {
        throw new InvalidArgumentException('Unknown Xray routing mode');
    }
    $routing = xray_build_routing($c['bypass_networks'] ?? '');
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
        // Direct bypass must remain first; all other SOCKS traffic enters the
        // balancer through an explicit catch-all rule.
        $routing['rules'][] = [
            'type' => 'field',
            'inboundTag' => ['socks-in'],
            'network' => 'tcp,udp',
            'balancerTag' => 'proxy-balance',
        ];
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
    }

    $outbounds[] = ['tag' => 'direct', 'protocol' => 'freedom'];
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
