<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Config;

require_once '/usr/local/opnsense/scripts/Xray/xray-subscriptions.php';

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
        return $this->searchBase('subscription', ['enabled', 'name', 'source_type', 'node_count', 'updated_at']);
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
            if ((string)($instance->subscription_uuid ?? '') === $uuid) {
                return ['result' => 'failed', 'message' => 'This subscription is used by an instance'];
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

    private static function download(string $url): string
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
            $kind = trim((string)($source['source_type'] ?? ''));
            if ($kind === '') {
                $kind = 'url';
            }
            if ($kind === 'url') {
                $body = self::download(trim((string)($source['url'] ?? '')));
            } elseif ($kind === 'links') {
                $body = (string)($source['links'] ?? '');
            } else {
                throw new \InvalidArgumentException('Unknown subscription source type');
            }
            $outbounds = xray_subscription_parse($body, [ImportController::class, 'parseVless']);
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
            ]);
            $this->save();
            return ['result' => 'ok', 'count' => count($outbounds)];
        } catch (\Throwable $e) {
            // Keep the previous working cache on a failed refresh, and do not log tokenized URLs.
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
