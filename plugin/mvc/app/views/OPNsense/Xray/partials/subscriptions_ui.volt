<script>
$(function () {
    var selectedSource = '';
    var editSourceId = '';

    function sourceMessage(message, failed) {
        $('#xraySourceNotice')
            .removeClass('alert-success alert-danger')
            .addClass(failed ? 'alert-danger' : 'alert-success')
            .text(message)
            .toggle(!!message);
    }

    function sources(callback) {
        $.ajax({
            url: '/api/xray/subscription/searchItem',
            method: 'POST', dataType: 'json',
            data: {rowCount: -1, current: 1, searchPhrase: ''}
        }).done(function (data) {
            var rows = (data && data.rows) || [];
            if (callback) callback(rows);
        }).fail(function (xhr) {
            sourceMessage('Cannot list subscriptions: HTTP ' + xhr.status, true);
        });
    }

    function refreshSourceList() {
        sources(function (rows) {
            var $body = $('#xraySourceRows').empty();
            if (!rows.length) {
                $('<tr>').append($('<td colspan="6" class="text-muted">')
                    .text('No subscriptions yet. Add an HTTPS URL or paste VLESS links.'))
                    .appendTo($body);
            }
            rows.forEach(function (row) {
                var uuid = row.uuid;
                var $tr = $('<tr>').appendTo($body);
                $('<td>').text(row.name || uuid).appendTo($tr);
                $('<td>').text(row.source_type === 'links' ? 'VLESS links' : 'HTTPS URL').appendTo($tr);
                $('<td>').text(row.node_count || '0').appendTo($tr);
                var interval = parseInt(row.refresh_interval, 10);
                if (!Number.isFinite(interval)) interval = 360;
                $('<td>').text(row.source_type === 'links' || interval === 0 ? 'Manual' : interval + ' min').appendTo($tr);
                var $last = $('<td>').text(row.updated_at || '—').appendTo($tr);
                if (row.last_error) $last.attr('title', row.last_error);
                var $actions = $('<td>').appendTo($tr);
                function action(text, handler) {
                    return $('<button type="button" class="btn btn-xs btn-default" style="margin-right:4px;">')
                        .text(text).on('click', handler).appendTo($actions);
                }
                action('Nodes', function () { showNodes(uuid, row.name || uuid); });
                action('Refresh', function () { refreshSource(uuid, row.name || uuid); });
                action('Edit', function () { editSource(uuid); });
                action('Delete', function () {
                    if (!confirm('Delete subscription "' + row.name + '"?')) return;
                    $.post('/api/xray/subscription/delItem/' + encodeURIComponent(uuid), {})
                        .done(function (result) {
                            if (result.result !== 'deleted') {
                                sourceMessage(result.message || 'Cannot delete subscription', true);
                            } else {
                                $('#xraySourceNodes').hide();
                                refreshSourceList();
                                sourceMessage('Subscription removed. Click Apply to update the running configuration.', false);
                            }
                        }).fail(function (xhr) { sourceMessage('Delete failed: HTTP ' + xhr.status, true); });
                });
            });
        });
    }

    function showNodes(uuid, name) {
        selectedSource = uuid;
        $('#xrayNodesTitle').text('Nodes in ' + name);
        $.getJSON('/api/xray/subscription/nodes/' + encodeURIComponent(uuid))
            .done(function (data) {
                if (data.result !== 'ok') {
                    sourceMessage(data.message || 'Cannot load nodes', true);
                    return;
                }
                var $body = $('#xrayNodesRows').empty();
                (data.nodes || []).forEach(function (node) {
                    var $tr = $('<tr>').appendTo($body);
                    $('<td>').text(node.tag).appendTo($tr);
                    $('<td>').text(node.protocol).appendTo($tr);
                    $('<td>').text(node.server).appendTo($tr);
                    $('<td>').text(node.port).appendTo($tr);
                });
                if (!(data.nodes || []).length) {
                    $('<tr>').append($('<td colspan="4" class="text-muted">')
                        .text('No parsed nodes. Click Refresh after saving the source.')).appendTo($body);
                }
                $('#xraySourceNodes').show();
            }).fail(function (xhr) { sourceMessage('Nodes request failed: HTTP ' + xhr.status, true); });
    }

    function refreshSource(uuid, name) {
        sourceMessage('Refreshing ' + name + '...', false);
        $.post('/api/xray/subscription/refresh/' + encodeURIComponent(uuid), {})
            .done(function (data) {
                if (data.result !== 'ok') {
                    sourceMessage(data.message || 'Subscription refresh failed', true);
                    return;
                }
                sourceMessage('Imported ' + data.count + ' node(s) from ' + name + '. Select one on Instances, then Apply.', false);
                refreshSourceList();
                showNodes(uuid, name);
            }).fail(function (xhr) { sourceMessage('Subscription refresh failed: HTTP ' + xhr.status, true); });
    }

    function switchSourceInputs() {
        var isUrl = $('#xraySourceType').val() === 'url';
        $('#xraySourceUrlGroup').toggle(isUrl);
        $('#xraySourceLinksGroup').toggle(!isUrl);
        $('#xraySourceInterval').closest('.form-group').toggle(isUrl);
    }

    function resetEditor() {
        editSourceId = '';
        $('#xraySourceName,#xraySourceUrl,#xraySourceLinks').val('');
        $('#xraySourceType').val('url');
        $('#xraySourceInterval').val('360');
        $('#xraySourceEditorTitle').text('Add subscription');
        switchSourceInputs();
        $('#xraySourceEditor').show();
    }

    function editSource(uuid) {
        $.getJSON('/api/xray/subscription/getItem/' + encodeURIComponent(uuid))
            .done(function (data) {
                var src = data.subscription || {};
                editSourceId = uuid;
                $('#xraySourceName').val(src.name || '');
                $('#xraySourceType').val(src.source_type || 'url');
                $('#xraySourceUrl').val(src.url || '');
                $('#xraySourceLinks').val(src.links || '');
                $('#xraySourceInterval').val(src.refresh_interval == null ? '360' : src.refresh_interval);
                $('#xraySourceEditorTitle').text('Edit subscription');
                switchSourceInputs();
                $('#xraySourceEditor').show();
            }).fail(function (xhr) { sourceMessage('Cannot open subscription: HTTP ' + xhr.status, true); });
    }

    $('#xraySourceAdd').on('click', resetEditor);
    $('#xraySourceCancel').on('click', function () { $('#xraySourceEditor').hide(); });
    $('#xraySourceType').on('change', switchSourceInputs);
    $('#xraySourceSave').on('click', function () {
        var name = $.trim($('#xraySourceName').val() || '');
        var type = $('#xraySourceType').val();
        if (!name || (type === 'url' && !$.trim($('#xraySourceUrl').val() || '')) ||
            (type === 'links' && !$.trim($('#xraySourceLinks').val() || ''))) {
            sourceMessage('Set a name and provide an HTTPS URL or VLESS links.', true);
            return;
        }
        var interval = Number($('#xraySourceInterval').val());
        if (!Number.isInteger(interval) || interval < 0 || interval > 10080) {
            sourceMessage('Interval must be 0–10080 minutes.', true);
            return;
        }
        var id = editSourceId;
        var $btn = $(this).prop('disabled', true);
        var endpoint = '/api/xray/subscription/' + (id ? 'setItem/' + encodeURIComponent(id) : 'addItem');
        $.ajax({
            url: endpoint, method: 'POST', dataType: 'json',
            data: {subscription: {
                enabled: '1', name: name, source_type: type, refresh_interval: interval,
                url: $('#xraySourceUrl').val(), links: $('#xraySourceLinks').val()
            }}
        }).done(function (data) {
            if (data.result !== 'saved') {
                sourceMessage('Cannot save subscription: ' + JSON.stringify(data.validations || data.message || data.result), true);
                return;
            }
            var uuid = id || data.uuid;
            $('#xraySourceEditor').hide();
            refreshSourceList();
            if (uuid) refreshSource(uuid, name);
        }).fail(function (xhr) {
            sourceMessage('Cannot save subscription: HTTP ' + xhr.status, true);
        }).always(function () { $btn.prop('disabled', false); });
    });

    $('a[href="#subscriptions"]').on('shown.bs.tab', refreshSourceList);

    // Two selectors in each instance editor. Hidden text fields remain bound to
    // OPNsense BaseForm so normal Save keeps working.
    var $instance = $('#DialogInstance');
    function installSelect(fieldId, selectId, placeholder) {
        var $field = $instance.find('[id="' + fieldId + '"]');
        if (!$field.length) return null;
        $field.hide();
        var $select = $('#' + selectId);
        if (!$select.length) {
            $select = $('<select class="form-control">').attr('id', selectId).insertAfter($field);
            $select.on('change', function () { $field.val($select.val()).trigger('change'); });
        }
        $select.empty().append($('<option>').val('').text(placeholder));
        return {field: $field, select: $select};
    }

    function fillInstanceNodes(sourceUuid, current) {
        var node = installSelect('instance.selected_outbound', 'xrayInstanceNode', 'First node (default)');
        if (!node) return;
        if (!sourceUuid) {
            node.field.val('');
            return;
        }
        $.getJSON('/api/xray/subscription/nodes/' + encodeURIComponent(sourceUuid))
            .done(function (data) {
                (data.nodes || []).forEach(function (entry) {
                    node.select.append($('<option>').val(entry.tag).text(entry.tag));
                });
                node.select.val(current);
                if (node.select.val() !== current) node.select.val('');
                node.field.val(node.select.val());
            });
    }

    $instance.on('shown.bs.modal', function () {
        var src = installSelect('instance.subscription_uuid', 'xrayInstanceSource', 'Manual JSON (Advanced)');
        if (!src) return;
        var current = src.field.val();
        var active = $instance.find('[id="instance.selected_outbound"]').val() || '';
        sources(function (rows) {
            rows.forEach(function (row) {
                if (String(row.enabled) !== '0') {
                    src.select.append($('<option>').val(row.uuid).text(row.name || row.uuid));
                }
            });
            src.select.val(current);
            if (src.select.val() !== current) src.select.val('');
            fillInstanceNodes(src.select.val(), active);
        });
    });

    $instance.on('change', '#xrayInstanceSource', function () {
        fillInstanceNodes($(this).val(), '');
    });

    // Advanced shows the actual source of outbounds for the selected instance.
    // Subscription-owned outbounds are read-only here: manual edits would be
    // ignored by Xray and overwritten by the next subscription refresh.
    function advancedStatus(text) {
        $('#xrayAdvancedNote').text(text);
    }

    function advancedMode(label, editable) {
        $('#xrayAdvancedJsonLabel').text(label);
        $('#xrayAdvancedJson').prop('readOnly', !editable);
        $('#xrayAdvancedSave').prop('disabled', !editable).toggle(editable);
    }

    function reloadAdvancedInstances() {
        $.post('/api/xray/instance/searchItem', {rowCount: -1, current: 1, searchPhrase: ''})
            .done(function (data) {
                var $select = $('#xrayAdvancedInstance');
                var previous = $select.val();
                $select.empty().append($('<option>').val('').text('Choose instance'));
                (data.rows || []).forEach(function (row) {
                    $select.append($('<option>').val(row.uuid).text(row.name || row.uuid));
                });
                if (previous) $select.val(previous);
                if ($select.val()) $select.trigger('change');
            })
            .fail(function (xhr) {
                advancedStatus('Cannot load instances: HTTP ' + xhr.status);
            });
    }

    $('a[href="#advanced"]').on('shown.bs.tab', reloadAdvancedInstances);
    $('#xrayAdvancedInstance').on('change', function () {
        var uuid = $(this).val();
        $('#xrayAdvancedJson').val('');
        advancedMode('Outbounds JSON', false);
        if (!uuid) {
            advancedStatus('Choose an instance to view its outbounds.');
            return;
        }
        advancedStatus('Loading instance configuration...');
        $.getJSON('/api/xray/instance/getItem/' + encodeURIComponent(uuid))
            .done(function (data) {
                if ($('#xrayAdvancedInstance').val() !== uuid) return;
                var instance = data.instance;
                if (!instance) {
                    advancedStatus('Instance configuration was not found.');
                    return;
                }
                var sourceId = (instance.subscription_uuid || '').trim();
                if (!sourceId) {
                    var raw = instance.outbound_config || '';
                    try {
                        if (raw) raw = JSON.stringify(JSON.parse(raw), null, 2);
                    } catch (_) {
                        // Show malformed manual input as-is so the user can fix it.
                    }
                    $('#xrayAdvancedJson').val(raw);
                    advancedMode('Manual Outbounds JSON', true);
                    advancedStatus(raw ?
                        'Manual outbounds are active. Save here, then Apply to update Xray.' :
                        'No manual outbounds configured for this instance.');
                    return;
                }

                advancedMode('Subscription Outbounds JSON (read-only)', false);
                $.getJSON('/api/xray/subscription/getItem/' + encodeURIComponent(sourceId))
                    .done(function (response) {
                        if ($('#xrayAdvancedInstance').val() !== uuid) return;
                        var source = response.subscription;
                        if (!source || !source.outbounds) {
                            advancedStatus('The selected subscription has no imported nodes. Refresh it first.');
                            return;
                        }
                        try {
                            var list = JSON.parse(source.outbounds);
                            if (!Array.isArray(list) || !list.length) {
                                throw new Error('No outbound nodes');
                            }
                            var selected = instance.selected_outbound || '';
                            if (selected) {
                                var selectedIndex = list.findIndex(function (item) {
                                    return item && item.tag === selected;
                                });
                                if (selectedIndex < 0) {
                                    advancedStatus('Selected node is missing from the subscription.');
                                    return;
                                }
                                if (selectedIndex > 0) {
                                    list.unshift(list.splice(selectedIndex, 1)[0]);
                                }
                            }
                            $('#xrayAdvancedJson').val(JSON.stringify(list, null, 2));
                            advancedStatus(
                                'Source: subscription "' + (source.name || sourceId) +
                                '". ' + list.length + ' outbound(s). ' +
                                'The first outbound is the default. This JSON is generated from the ' +
                                'subscription and cannot be edited here; use Subscriptions to refresh it.'
                            );
                        } catch (e) {
                            advancedStatus('Cannot read subscription JSON: ' + e.message);
                        }
                    })
                    .fail(function (xhr) {
                        if ($('#xrayAdvancedInstance').val() === uuid) {
                            advancedStatus('Cannot load subscription outbounds: HTTP ' + xhr.status);
                        }
                    });
            })
            .fail(function (xhr) {
                if ($('#xrayAdvancedInstance').val() === uuid) {
                    advancedStatus('Cannot load instance configuration: HTTP ' + xhr.status);
                }
            });
    });

    $('#xrayAdvancedSave').on('click', function () {
        var uuid = $('#xrayAdvancedInstance').val();
        if (!uuid || $(this).prop('disabled')) return;
        var raw = $.trim($('#xrayAdvancedJson').val() || '');
        try {
            if (raw) JSON.parse(raw);
        } catch (e) {
            advancedStatus('Invalid JSON: ' + e.message);
            return;
        }
        $.post('/api/xray/instance/setItem/' + encodeURIComponent(uuid), {
            instance: {outbound_config: raw}
        }).done(function (data) {
            advancedStatus(data.result === 'saved' ?
                'Saved. Click Apply to activate changes.' :
                'Save failed: ' + JSON.stringify(data.validations || data.result));
        }).fail(function (xhr) {
            advancedStatus('Save failed: HTTP ' + xhr.status);
        });
    });
});
</script>
