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
    if ($config['outbounds'][0]['tag'] !== 'Kalamar-CZ' ||
        $config['outbounds'][1]['tag'] !== 'Kalamar-US' ||
        $config['outbounds'][2]['tag'] !== 'direct') {
        throw new \RuntimeException('Active node selection failed');
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
        || ($balanced['outbounds'][0]['tag'] ?? '') !== 'Kalamar-BY') {
        throw new \RuntimeException('LeastLoad config generation failed');
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

    echo "subscription smoke: OK\n";
}
