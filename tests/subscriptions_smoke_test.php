<?php
// One small smoke test: subscription names, base64 feed and explicit active node.
namespace OPNsense\Base {
    class ApiControllerBase {}
}
namespace {
    require __DIR__ . '/../plugin/mvc/app/controllers/OPNsense/Xray/Api/ImportController.php';
    require __DIR__ . '/../plugin/scripts/Xray/xray-subscriptions.php';
    require __DIR__ . '/../plugin/scripts/Xray/xray-config.php';

    $id = '00000000-0000-4000-8000-000000000001';
    $links = implode("\n", [
        "vless://{$id}@us.example:443?type=tcp&security=tls&sni=us.example#Kalamar-US",
        "vless://{$id}@cz.example:443?type=grpc&security=reality&sni=cz.example&pbk=abc#Kalamar-CZ",
    ]);
    $parse = [\OPNsense\Xray\Api\ImportController::class, 'parseVless'];
    $nodes = xray_subscription_parse(base64_encode($links), $parse);
    if (count($nodes) !== 2 ||
        $nodes[0]['tag'] !== 'Kalamar-US' ||
        $nodes[1]['tag'] !== 'Kalamar-CZ') {
        throw new \RuntimeException('Subscription name extraction failed');
    }
    $config = xray_build_config([
        'outbound_config' => json_encode($nodes),
        'selected_outbound' => 'Kalamar-CZ',
    ]);
    if (count($config['outbounds']) !== 2 ||
        $config['outbounds'][0]['tag'] !== 'Kalamar-CZ' ||
        $config['outbounds'][1]['tag'] !== 'direct' ||
        (isset($config['routing']['balancers']) || isset($config['burstObservatory']))) {
        throw new \RuntimeException('Manual mode emitted unselected subscription nodes');
    }
    $summaries = xray_subscription_node_summaries($nodes);
    if ($summaries[0]['server'] !== 'us.example' ||
        isset($summaries[0]['settings']) || isset($summaries[0]['id'])) {
        throw new \RuntimeException('Node list leaks private config');
    }
    if (!xray_subscription_has_selected_node($nodes, 'Kalamar-US') ||
        xray_subscription_has_selected_node($nodes, 'Missing-node')) {
        throw new \RuntimeException('Selected-node protection failed');
    }
    $now = 1794762000;
    if (!xray_subscription_due(360, $now - 360 * 60, $now) ||
        xray_subscription_due(360, $now - 359 * 60, $now) ||
        xray_subscription_due(0, 0, $now)) {
        throw new \RuntimeException('Refresh schedule failed');
    }
    // Two observed nodes, one reserved fallback, and private bypass first.
    $poolNodes = array_merge($nodes, [[
        'tag' => 'Kalamar-BY', 'protocol' => 'vless',
        'settings' => ['address' => 'by.example', 'port' => 443],
    ], [
        'tag' => 'Unused', 'protocol' => 'vless',
        'settings' => ['address' => 'unused.example', 'port' => 443],
    ]]);
    $balanced = xray_build_config([
        'outbound_config' => json_encode($poolNodes),
        'route_mode' => 'leastLoad',
        'balancer_nodes' => json_encode(['Kalamar-US', 'Kalamar-CZ']),
        'balancer_fallback' => 'Kalamar-BY',
        'balancer_expected' => 2,
        'balancer_tolerance' => '0.2',
    ]);
    $rules = $balanced['routing']['rules'];
    if (($rules[0]['outboundTag'] ?? '') !== 'direct'
        || ($rules[1]['balancerTag'] ?? '') !== 'proxy-balance'
        || ($balanced['routing']['balancers'][0]['fallbackTag'] ?? '') !== 'Kalamar-BY'
        || ($balanced['routing']['balancers'][0]['strategy']['type'] ?? '') !== 'leastLoad'
        || ($balanced['burstObservatory']['pingConfig']['sampling'] ?? 0) !== 10
        || ($balanced['outbounds'][0]['tag'] ?? '') !== 'Kalamar-BY'
        || count($balanced['outbounds']) !== 4
        || in_array('Unused', array_column($balanced['outbounds'], 'tag'), true)) {
        throw new \RuntimeException('LeastLoad config contains an unused source node');
    }
    try {
        xray_build_config([
            'outbound_config' => json_encode($poolNodes),
            'route_mode' => 'leastLoad',
            'balancer_nodes' => json_encode(['Kalamar-US', 'Kalamar-CZ']),
            'balancer_fallback' => 'missing',
        ]);
        throw new \RuntimeException('Missing fallback was accepted');
    } catch (\InvalidArgumentException $e) { /* expected */ }

    // Same visible tag in two subscriptions must produce distinct Xray tags.
    $firstSource = '11111111-1111-4111-8111-111111111111';
    $secondSource = '22222222-2222-4222-8222-222222222222';
    $sources = [
        $firstSource => json_encode([
            ['tag' => 'Common', 'protocol' => 'freedom', 'settings' => ['domainStrategy' => 'AsIs']],
            ['tag' => 'Unused', 'protocol' => 'freedom'],
        ]),
        $secondSource => json_encode([
            ['tag' => 'Common', 'protocol' => 'freedom'],
            ['tag' => 'Reserve', 'protocol' => 'freedom'],
        ]),
    ];
    $mixed = xray_prepare_balancer_sources([
        'subscription_uuid' => $firstSource,
        'route_mode' => 'leastLoad',
        'balancer_nodes' => json_encode([$firstSource . '::Common', $secondSource . '::Common']),
        'balancer_fallback' => $secondSource . '::Reserve',
    ], $sources);
    $balancedMixed = xray_build_config($mixed);
    $selector = $balancedMixed['routing']['balancers'][0]['selector'];
    if (count($selector) !== 2 || $selector[0] === $selector[1]
        || count($balancedMixed['outbounds']) !== 4
        || ($balancedMixed['routing']['balancers'][0]['fallbackTag'] ?? '')
            === $selector[0]
        || $balancedMixed['routing']['balancers'][0]['fallbackTag']
            !== $balancedMixed['outbounds'][0]['tag']) {
        throw new \RuntimeException('Cross-source LeastLoad selection failed');
    }
    if (str_contains(json_encode($balancedMixed), 'Unused')) {
        throw new \RuntimeException('Unselected source nodes leaked into the config');
    }
    // Manual refresh preserves the previous cache if any selected node
    // disappears, in both Manual and cross-subscription LeastLoad.
    $manualUse = (object)[
        'subscription_uuid' => $firstSource,
        'route_mode' => 'manual',
        'selected_outbound' => 'Common',
    ];
    $balanceUse = (object)[
        'subscription_uuid' => $firstSource,
        'route_mode' => 'leastLoad',
        'balancer_nodes' => json_encode([$firstSource . '::Common', $secondSource . '::Common']),
        'balancer_fallback' => $secondSource . '::Reserve',
    ];
    xray_subscription_assert_selected_nodes(
        json_decode($sources[$firstSource], true), [$manualUse, $balanceUse], $firstSource
    );
    xray_subscription_assert_selected_nodes(
        json_decode($sources[$secondSource], true), [$balanceUse], $secondSource
    );
    foreach ([
        [
            [['tag' => 'Unused', 'protocol' => 'freedom']],
            [$manualUse], $firstSource,
        ],
        [
            [['tag' => 'Reserve', 'protocol' => 'freedom']],
            [$balanceUse], $secondSource,
        ],
    ] as [$available, $instances, $sourceId]) {
        try {
            xray_subscription_assert_selected_nodes($available, $instances, $sourceId);
            throw new \RuntimeException('Refresh accepted a removed selected node');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Refresh accepted a removed selected node') throw $e;
        }
    }

    // Existing unqualified selections preserve their runtime configuration.
    $existing = [
        'subscription_uuid' => $firstSource,
        'outbound_config' => $sources[$firstSource],
        'route_mode' => 'leastLoad',
        'balancer_nodes' => '["Common","Unused"]',
        'balancer_fallback' => 'Common'
    ];
    if (xray_prepare_balancer_sources($existing, $sources) !== $existing) {
        throw new \RuntimeException('Existing single-subscription settings changed');
    }
    try {
        xray_prepare_balancer_sources([
            'subscription_uuid' => $firstSource,
            'route_mode' => 'leastLoad',
            'balancer_nodes' => json_encode([$firstSource . '::Common', $secondSource . '::Gone']),
            'balancer_fallback' => $secondSource . '::Reserve',
        ], $sources);
        throw new \RuntimeException('Missing remote node was accepted');
    } catch (\InvalidArgumentException $e) { /* expected */ }

    // Sniffing exposes a detected BitTorrent protocol to ordered routing.
    // The private direct rule is protected, then custom rules, then LeastLoad.
    $manualRouting = xray_build_config([
        'outbound_config' => json_encode($nodes),
        'selected_outbound' => 'Kalamar-CZ',
        'sniffing_enabled' => '1',
        'sniffing_route_only' => '1',
        'routing_domain_strategy' => 'AsIs',
        'routing_rules' => json_encode([
            ['kind'=>'protocol', 'match'=>'bittorrent', 'target'=>'direct'],
            ['kind'=>'domain', 'match'=>'domain:example.com', 'target'=>'block'],
            ['kind'=>'port', 'match'=>'443,8000-8001', 'target'=>'proxy'],
        ]),
    ]);
    $manualRules = $manualRouting['routing']['rules'];
    if (count($manualRules) !== 4 ||
        ($manualRules[0]['outboundTag'] ?? '') !== 'direct' ||
        $manualRouting['routing']['domainStrategy'] !== 'AsIs' ||
        ($manualRules[1]['protocol'] ?? []) !== ['bittorrent'] ||
        ($manualRules[1]['outboundTag'] ?? '') !== 'direct' ||
        ($manualRules[2]['outboundTag'] ?? '') !== 'block' ||
        ($manualRules[3]['outboundTag'] ?? '') !== 'Kalamar-CZ' ||
        ($manualRouting['inbounds'][0]['sniffing']['routeOnly'] ?? false) !== true ||
        array_column($manualRouting['outbounds'], 'tag') !== ['Kalamar-CZ','direct','block']) {
        throw new \RuntimeException('Manual custom routing or sniffing failed');
    }
    $balancedRouting = xray_build_config([
        'outbound_config' => json_encode($poolNodes),
        'route_mode' => 'leastLoad',
        'balancer_nodes' => json_encode(['Kalamar-US', 'Kalamar-CZ']),
        'balancer_fallback' => 'Kalamar-BY',
        'sniffing_enabled' => '1',
        'routing_rules' => json_encode([
            ['kind'=>'protocol', 'match'=>'bittorrent', 'target'=>'direct'],
            ['kind'=>'domain', 'match'=>'domain:example.com', 'target'=>'proxy'],
        ]),
    ]);
    $balancedRules = $balancedRouting['routing']['rules'];
    if (count($balancedRules) !== 4 ||
        ($balancedRules[1]['outboundTag'] ?? '') !== 'direct' ||
        ($balancedRules[2]['balancerTag'] ?? '') !== 'proxy-balance' ||
        ($balancedRules[3]['balancerTag'] ?? '') !== 'proxy-balance' ||
        isset($balancedRules[0]['balancerTag']) ||
        count($balancedRouting['outbounds']) !== 4) {
        throw new \RuntimeException('LeastLoad custom routing order failed');
    }
    foreach ([
        ['sniffing_enabled'=>'0', 'routing_rules'=>'[{"kind":"protocol","match":"bittorrent","target":"direct"}]'],
        ['sniffing_enabled'=>'1', 'routing_rules'=>'[{"kind":"port","match":"70000","target":"direct"}]'],
        ['sniffing_enabled'=>'1', 'routing_rules'=>'[{"kind":"domain","match":"","target":"direct"}]'],
        ['routing_domain_strategy'=>'Invalid'],
    ] as $invalidRouting) {
        try {
            xray_build_config(array_merge(['outbound_config'=>json_encode($nodes)], $invalidRouting));
            throw new \RuntimeException('Invalid routing rule was accepted');
        } catch (\InvalidArgumentException $e) { /* expected */ }
    }

    // The GUI follows the firewall timezone, not the browser timezone.
    // Refresh timestamps stay UTC in config.xml and are converted on read.
    $time = '2026-11-16 14:37:04 UTC';
    $expectedTimes = [
        'Europe/Moscow' => '2026-11-16 17:37:04 MSK',
        'Europe/Prague' => '2026-11-16 15:37:04 CET',
        'Etc/UTC' => '2026-11-16 14:37:04 UTC',
        'Invalid/Zone' => '2026-11-16 14:37:04 UTC',
    ];
    foreach ($expectedTimes as $timezone => $expectedTime) {
        if (xray_subscription_display_time($time, $timezone) !== $expectedTime) {
            throw new \RuntimeException('Subscription GUI timezone conversion failed: ' . $timezone);
        }
    }
    if (xray_subscription_display_time('2026-07-16 14:37:04 UTC', 'Europe/Prague')
        !== '2026-07-16 16:37:04 CEST' ||
        xray_subscription_display_time('', 'Europe/Moscow') !== '') {
        throw new \RuntimeException('Subscription DST or empty display time failed');
    }

    // HWID is stable once persisted, caller-supplied IDs remain intact,
    // and HTTP header injection is rejected before constructing cURL headers.
    $hwid = xray_subscription_hwid('');
    if (!preg_match('/^[0-9a-f]{32}$/D', $hwid) ||
        xray_subscription_hwid($hwid) !== $hwid ||
        xray_subscription_hwid('pre-registered-device') !== 'pre-registered-device') {
        throw new \RuntimeException('HWID generation or retention failed');
    }
    foreach (["bad\r\nX-Extra: injected", str_repeat('x', 129), 'invalid hwid'] as $invalidHwid) {
        try {
            xray_subscription_hwid($invalidHwid);
            throw new \RuntimeException('Unsafe HWID accepted');
        } catch (\InvalidArgumentException $expected) {
            // expected
        }
    }
    // OPNsense /usr/local/opnsense/version/core contains JSON product metadata.
    if (xray_subscription_os_version('{"product_version":"26.7.10","product_name":"OPNsense"}') !== '26.7.10' ||
        xray_subscription_os_version('not json') !== 'unknown' ||
        xray_subscription_os_version('{"product_version":"26.7\\r\\nInjected: 1"}') !== 'unknown') {
        throw new \RuntimeException('OPNsense version metadata parsing failed');
    }
    $httpHeaders = xray_subscription_headers('pre-registered-device');
    if (!in_array('User-Agent: os-xray/3.2.1', $httpHeaders, true) ||
        !in_array('X-HWID: pre-registered-device', $httpHeaders, true) ||
        !in_array('X-Device-OS: OPNsense', $httpHeaders, true) ||
        !in_array('X-Device-Model: OPNsense Firewall', $httpHeaders, true)) {
        throw new \RuntimeException('PasarGuard identifying headers incorrect');
    }
    foreach ([
        [['ip' => '127.0.0.1']],
        [['ip' => '8.8.8.8'], ['ip' => '192.168.1.1']],
        [['ipv6' => '::1']],
        [],
    ] as $invalidRecords) {
        try {
            xray_subscription_public_ip($invalidRecords);
            throw new \RuntimeException('Non-public DNS result accepted');
        } catch (\InvalidArgumentException | \RuntimeException $expected) {
            if ($expected->getMessage() === 'Non-public DNS result accepted') throw $expected;
        }
    }
    if (xray_subscription_public_ip([['ipv6' => '2606:4700:4700::1111']])
            !== '2606:4700:4700::1111' ||
        xray_subscription_public_ip([['ip' => '1.1.1.1'], ['ipv6' => '2606:4700:4700::1111']])
            !== '1.1.1.1') {
        throw new \RuntimeException('Public IP pinning selection failed');
    }

    echo "subscription smoke: OK\n";
}
