<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';

    public function searchItemAction()
    {
        $response = $this->searchBase(
            'instance', ['enabled', 'name', 'outbound_config', 'subscription_uuid', 'selected_outbound']
        );
        $sources = [];
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        foreach ($cfg->OPNsense->xray->subscriptions->subscription ?? [] as $src) {
            $sources[(string)$src['uuid']] = json_decode((string)($src->outbounds ?? ''), true) ?: [];
        }
        foreach ($response['rows'] ?? [] as &$row) {
            $sourceId = (string)($row['subscription_uuid'] ?? '');
            $outbounds = $sourceId !== '' ?
                ($sources[$sourceId] ?? []) :
                json_decode((string)($row['outbound_config'] ?? ''), true);
            if (!is_array($outbounds)) $outbounds = [];
            if (!array_is_list($outbounds)) $outbounds = [$outbounds];

            $active = (string)($row['selected_outbound'] ?? '');
            $ob = $outbounds[0] ?? [];
            foreach ($outbounds as $candidate) {
                if ($active !== '' && ($candidate['tag'] ?? '') === $active) {
                    $ob = $candidate;
                    break;
                }
            }
            $settings = $ob['settings'] ?? [];
            $vnext = $settings['vnext'][0] ?? [];
            $row['server_address'] = (string)($settings['address'] ?? $vnext['address'] ?? '');
            $row['server_port'] = (string)($settings['port'] ?? $vnext['port'] ?? '');
            unset($row['outbound_config']);
        }
        unset($row);
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

    public function getItemAction($uuid = null)
    {
        $response = $this->getBase('instance', 'instance', $uuid);
        // Dialog metadata: only an existing, saved object can be validated.
        $response['instance_uuid'] = isset($response['instance']) && is_string($uuid)
            && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $uuid) ? $uuid : '';
        return $response;
    }

    public function addItemAction()
    {
        return $this->addBase('instance', 'instance');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('instance', 'instance', $uuid);
    }

    public function delItemAction($uuid)
    {
        return $this->delBase('instance', $uuid);
    }
}
