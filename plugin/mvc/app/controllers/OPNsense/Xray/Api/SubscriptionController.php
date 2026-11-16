<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Config;

require_once '/usr/local/opnsense/scripts/Xray/xray-subscriptions.php';
require_once '/usr/local/opnsense/scripts/Xray/xray-config.php';

class SubscriptionController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\\OPNsense\\Xray\\Subscription';
    protected static $internalModelName = 'subscription';

    private static function validUuid(string $uuid): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $uuid
        );
    }

    public function searchItemAction()
    {
        // Never expose subscription URLs, share links or proxy credentials in grid responses.
        $result = $this->searchBase('subscription', [
            'enabled', 'name', 'source_type', 'node_count',
            'refresh_interval', 'updated_at', 'last_error'
        ]);
        // OPNsense stores its configured timezone in system.timezone. Do not
        // use the browser timezone, which can differ from the firewall's.
        $timezone = (string)(Config::getInstance()->object()->system->timezone ?? 'Etc/UTC');
        foreach ($result['rows'] ?? [] as $index => $row) {
            $result['rows'][$index]['updated_at'] = xray_subscription_display_time(
                (string)($row['updated_at'] ?? ''), $timezone
            );
        }
        return $result;
    }

    public function getItemAction($uuid = null)
    {
        $result = $this->getBase('subscription', 'subscription', $uuid);
        // OPNsense OptionField::getNodeData() returns the choices map, not
        // the selected scalar; the editor needs the actual stored value.
        if (self::validUuid((string)$uuid) && isset($result['subscription'])) {
            $node = $this->getModel()->getNodeByReference('subscription.' . $uuid);
            if ($node !== null) {
                $result['subscription']['source_type'] = (string)$node->source_type;
            }
        }
        return $result;
    }

    public function addItemAction()
    {
        return $this->addBase('subscription', 'subscription');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('subscription', 'subscription', $uuid);
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('subscription', $uuid, $enabled);
    }

    public function delItemAction($uuid)
    {
        if (!self::validUuid((string)$uuid)) {
            return ['result' => 'failed', 'message' => 'Invalid subscription ID'];
        }
        $cfg = Config::getInstance()->object();
        foreach ($cfg->OPNsense->xray->instances->instance ?? [] as $instance) {
            $primary = strtolower((string)($instance->subscription_uuid ?? ''));
            if ($primary === strtolower((string)$uuid)) {
                return ['result' => 'failed', 'message' => 'This subscription is used by an instance'];
            }
            if ((string)($instance->route_mode ?? 'manual') !== 'leastLoad') continue;
            $pool = json_decode((string)($instance->balancer_nodes ?? '[]'), true);
            if (!is_array($pool)) $pool = [];
            $pool[] = (string)($instance->balancer_fallback ?? '');
            foreach ($pool as $ref) {
                if (!is_string($ref) || $ref === '') continue;
                [$sourceId] = xray_balancer_parse_ref($ref, $primary);
                if ($sourceId === strtolower((string)$uuid)) {
                    return ['result' => 'failed',
                        'message' => 'This subscription supplies a LeastLoad node or fallback'];
                }
            }
        }
        return $this->delBase('subscription', $uuid);
    }

    private function loadItem(string $uuid): ?array
    {
        if (!self::validUuid($uuid)) {
            return null;
        }
        // Read raw values from the model. In particular, source_type is
        // an OptionField and getBase() exposes a choices map for forms.
        $node = $this->getModel()->getNodeByReference('subscription.' . $uuid);
        if ($node === null) {
            return null;
        }
        return [
            'source_type' => (string)$node->source_type,
            'url' => (string)$node->url,
            'links' => (string)$node->links,
            'outbounds' => (string)$node->outbounds,
        ];
    }

    public function nodesAction($uuid)
    {
        $source = $this->loadItem((string)$uuid);
        if ($source === null) {
            return ['result' => 'failed', 'message' => 'Subscription not found'];
        }
        $outbounds = json_decode((string)($source['outbounds'] ?? ''), true);
        return [
            'result' => 'ok',
            'nodes' => is_array($outbounds) ? xray_subscription_node_summaries($outbounds) : [],
        ];
    }


    public function refreshAction($uuid)
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $source = $this->loadItem((string)$uuid);
        if ($source === null) {
            return ['result' => 'failed', 'message' => 'Subscription not found'];
        }
        try {
            $kind = trim((string)($source['source_type'] ?? 'url')) ?: 'url';
            $outbounds = xray_subscription_import(
                $kind, (string)($source['url'] ?? ''), (string)($source['links'] ?? ''),
                [ImportController::class, 'parseVless']
            );
            $json = json_encode($outbounds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            Config::getInstance()->lock();
            $node = $this->getModel()->getNodeByReference('subscription.' . $uuid);
            if ($node === null) {
                throw new \RuntimeException('Subscription no longer exists');
            }
            $node->setNodes([
                'outbounds' => $json,
                'node_count' => (string)count($outbounds),
                'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                'last_checked' => (string)time(),
                'last_error' => '',
            ]);
            $this->save();
            return ['result' => 'ok', 'count' => count($outbounds)];
        } catch (\Throwable $e) {
            // Keep the previous working cache on a failed refresh, and do not log tokenized URLs.
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
