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
    echo "subscription smoke: OK\n";
}
