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

    // Keep OPNsense BaseForm fields bound to the normal Save API, replacing
    // technical tag/JSON inputs with user-facing selectors and checkboxes.
    var $instance = $('#DialogInstance');
    function instanceField(name) {
        return $instance.find('[id="instance.' + name + '"]');
    }

    function installSelect(fieldName, selectId, placeholder) {
        var $field = instanceField(fieldName);
        if (!$field.length) return null;
        $field.hide();
        var $select = $('#' + selectId);
        if (!$select.length) {
            $select = $('<select class="form-control">').attr('id', selectId).insertAfter($field);
            $select.on('change', function () {
                $field.val($select.val()).trigger('change');
            });
        }
        $select.empty().append($('<option>').val('').text(placeholder));
        return {field: $field, select: $select};
    }

    function installPool() {
        var $field = instanceField('balancer_nodes');
        if (!$field.length) return null;
        $field.hide();
        var $pool = $('#xrayInstancePool');
        if (!$pool.length) {
            $pool = $('<div id="xrayInstancePool" class="well well-sm" ' +
                'style="max-height:200px;overflow-y:auto;margin:4px 0;">' +
                'Select a subscription first.</div>').insertAfter($field);
        }
        return {field: $field, pool: $pool};
    }

    function readPool(raw) {
        try {
            var data = JSON.parse(raw || '[]');
            return Array.isArray(data) ? data.filter(function (x) { return typeof x === 'string'; }) : [];
        } catch (_) {
            return [];
        }
    }

    function setVisibleRoutingMode() {
        var balanced = instanceField('route_mode').val() === 'leastLoad';
        // OPNsense BaseForm renders each field as <tr id="row_instance.FIELD">.
        // There is no .form-group ancestor here, so hiding one has no effect.
        function showRow(name, visible) {
            $instance.find('[id="row_instance.' + name + '"]').toggle(visible);
        }
        showRow('subscription_uuid', !balanced);
        showRow('selected_outbound', !balanced);
        ['balancer_nodes', 'balancer_fallback', 'balancer_expected', 'balancer_tolerance']
            .forEach(function (name) { showRow(name, balanced); });
        showRow('sniffing_route_only',
            instanceField('sniffing_enabled').prop('checked'));
        // Keep hidden values for subsequent mode changes; do not alter
        // the saved selections just because a row is invisible.
    }

    var nodeLoadEpoch = 0;
    function fillInstanceNodes(primaryId, initial, sourceRows) {
        var manual = installSelect('selected_outbound', 'xrayInstanceNode', 'First node (default)');
        var fallback = installSelect('balancer_fallback', 'xrayInstanceFallback', 'Choose a fallback node');
        var pool = installPool();
        if (!manual || !fallback || !pool) return;
        var epoch = ++nodeLoadEpoch;
        var sourceList = (sourceRows || []).filter(function (row) {
            return String(row.enabled) !== '0';
        });
        var qualifiedRx = /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}::/i;
        function qualify(tag) {
            if (!tag || qualifiedRx.test(tag)) return tag || '';
            return primaryId ? primaryId + '::' + tag : tag;
        }
        var chosen = readPool(initial.pool).map(qualify);
        var selectedFallback = qualify(initial.fallback);
        var records = [];
        var failures = [];

        pool.pool.empty().text(sourceList.length ? 'Loading nodes from all subscriptions…' :
            'Add and refresh a subscription to select LeastLoad nodes.');
        if (!sourceList.length) {
            setVisibleRoutingMode();
            return;
        }

        function addPoolChoice(value, label, checked, missing) {
            var $label = $('<label class="xray-pool-choice">').css({
                display: 'flex', alignItems: 'center', gap: '10px',
                padding: '5px 10px', margin: '0', width: '100%',
                cursor: 'pointer', fontWeight: 'normal'
            });
            var $checkbox = $('<input type="checkbox">').val(value)
                .prop('checked', checked)
                .css({position: 'static', margin: '0', flex: '0 0 auto'});
            $label.append($checkbox).append($('<span>').text(label));
            if (missing) $label.addClass('text-danger');
            pool.pool.append($label);
        }

        function renderNodes() {
            if (epoch !== nodeLoadEpoch) return;
            pool.pool.empty();
            var known = {};
            var manualTags = [];

            records.forEach(function (record) {
                var value = record.sourceId + '::' + record.tag;
                var caption = record.sourceName + ' / ' + record.tag;
                known[value] = true;
                fallback.select.append($('<option>').val(value).text(caption));
                addPoolChoice(value, caption, chosen.indexOf(value) !== -1, false);
                if (record.sourceId === primaryId) {
                    manualTags.push(record.tag);
                    manual.select.append($('<option>').val(record.tag).text(record.tag));
                }
            });

            if (initial.manual && manualTags.indexOf(initial.manual) === -1) {
                manual.select.append($('<option>').val(initial.manual)
                    .text('Missing: ' + initial.manual));
            }
            manual.select.val(initial.manual || '');
            manual.field.val(manual.select.val() || '');

            if (selectedFallback && !known[selectedFallback]) {
                fallback.select.append($('<option>').val(selectedFallback)
                    .text('Missing: ' + selectedFallback));
            }
            fallback.select.val(selectedFallback);
            fallback.field.val(fallback.select.val() || '');

            chosen.forEach(function (value) {
                if (!known[value]) {
                    addPoolChoice(value, 'Missing: ' + value, true, true);
                }
            });
            if (failures.length) {
                $('<div class="text-danger">').text(
                    'Could not load nodes from: ' + failures.join(', ')
                ).appendTo(pool.pool);
            }
            pool.pool.off('change', 'input').on('change', 'input', function () {
                var values = pool.pool.find('input:checked').map(function () {
                    return $(this).val();
                }).get();
                pool.field.val(JSON.stringify(values)).trigger('change');
            });
            // Convert existing single-subscription names to stable refs on
            // ordinary Save without requiring users to reselect every node.
            pool.field.val(JSON.stringify(chosen));
            setVisibleRoutingMode();
        }

        var pending = sourceList.length;
        sourceList.forEach(function (source) {
            $.getJSON('/api/xray/subscription/nodes/' + encodeURIComponent(source.uuid))
                .done(function (data) {
                    if (epoch !== nodeLoadEpoch) return;
                    if (!data || data.result !== 'ok') {
                        failures.push(source.name || source.uuid);
                        return;
                    }
                    (data.nodes || []).forEach(function (item) {
                        records.push({
                            sourceId: source.uuid,
                            sourceName: source.name || source.uuid,
                            tag: item.tag
                        });
                    });
                })
                .fail(function () {
                    if (epoch === nodeLoadEpoch) failures.push(source.name || source.uuid);
                })
                .always(function () {
                    if (--pending === 0) renderNodes();
                });
        });
    }

    // The routing form is a structured editor; only the canonical JSON field
    // is submitted by OPNsense BaseForm. This avoids accepting unbounded raw
    // Xray routing objects or exposing the entire runtime config for editing.
    function installRoutingEditor() {
        var $field = instanceField('routing_rules');
        if (!$field.length) return;
        $field.hide();
        var $editor = $('#xrayRoutingEditor');
        if (!$editor.length) {
            $editor = $('<div id="xrayRoutingEditor">').css({
                maxWidth: '100%', minWidth: '0'
            }).insertAfter($field);
        }
        $editor.empty();
        var raw = $field.val() || '[]';
        var rules;
        try {
            rules = JSON.parse(raw);
            if (!Array.isArray(rules)) throw new Error('Expected an array');
        } catch (_) {
            $('<div class="text-danger">').text(
                'Stored routing rules are invalid JSON. Fix them before adding new rules.'
            ).appendTo($editor);
            return;
        }

        $('<p class="text-muted">').text(
            'First match wins. Private-network bypass runs first; these rules run ' +
            'before the default proxy / LeastLoad. Direct uses the local WAN, not a VPN exit.'
        ).appendTo($editor);

        var $toolbar = $('<div>').css({
            display: 'flex', flexWrap: 'wrap', gap: '6px', marginBottom: '8px'
        }).appendTo($editor);
        var $add = $('<button type="button" class="btn btn-default btn-sm">')
            .text('Add rule').appendTo($toolbar);
        var $addBt = $('<button type="button" class="btn btn-default btn-sm">')
            .text('BitTorrent → Direct').appendTo($toolbar);
        var $list = $('<div id="xrayRoutingRuleList">').appendTo($editor);

        function sync() {
            $field.val(JSON.stringify(rules)).trigger('change');
        }
        function ruleSelect(options, current) {
            // The OPNsense theme gives .form-control select elements an
            // oversized line-height; combined with input-sm this clips text
            // along the lower edge. Explicit native-select sizing fixes it.
            var $select = $('<select class="form-control">').css({
                width: '100%',
                minWidth: '0',
                height: '34px',
                lineHeight: 'normal',
                padding: '5px 28px 5px 10px',
                boxSizing: 'border-box',
                verticalAlign: 'middle'
            });
            options.forEach(function (item) {
                $select.append($('<option>').val(item.value).text(item.label));
            });
            $select.val(current);
            return $select;
        }
        function render() {
            $list.empty();
            if (!rules.length) {
                $('<p class="text-muted">').text('No custom rules.').appendTo($list);
                return;
            }
            rules.forEach(function (rule, index) {
                var $card = $('<div class="well well-sm">').css({
                    marginBottom: '6px', minWidth: '0', padding: '8px'
                }).appendTo($list);
                var $top = $('<div>').css({
                    display: 'flex', gap: '6px', marginBottom: '6px'
                }).appendTo($card);
                var $kind = ruleSelect([
                    {value:'protocol',label:'Protocol'},
                    {value:'domain',label:'Domain'},
                    {value:'ip',label:'IP/CIDR'},
                    {value:'port',label:'Port'}
                ], rule.kind).appendTo($top).css({flex: '1 1 50%'});
                var $target = ruleSelect([
                    {value:'proxy',label:'Proxy / LeastLoad'},
                    {value:'direct',label:'Direct'},
                    {value:'block',label:'Block'}
                ], rule.target).appendTo($top).css({flex: '1 1 50%'});
                var $match = $('<input type="text" class="form-control input-sm">')
                    .css({width:'100%',minWidth:'0',marginBottom:'6px'})
                    .val(rule.match || '').appendTo($card);
                var placeholders = {
                    protocol: 'bittorrent, tls, http or quic',
                    domain: 'domain:example.com, geosite:category-ads-all',
                    ip: '203.0.113.0/24, geoip:private',
                    port: '443, 8000-9000'
                };
                $match.attr('placeholder', placeholders[rule.kind] || '');
                $kind.on('change', function () {
                    rule.kind = $(this).val();
                    $match.attr('placeholder', placeholders[rule.kind] || '');
                    sync();
                });
                $target.on('change', function () {
                    rule.target = $(this).val();
                    sync();
                });
                $match.on('input change', function () {
                    rule.match = $(this).val();
                    sync();
                });

                var $commands = $('<div>').css({
                    display:'flex',gap:'6px',justifyContent:'flex-end'
                }).appendTo($card);
                function command(text, disabled, callback) {
                    $('<button type="button" class="btn btn-default btn-xs">')
                        .text(text).prop('disabled', disabled)
                        .appendTo($commands).on('click', callback);
                }
                command('↑', index === 0, function () {
                    var value = rules[index - 1];
                    rules[index - 1] = rules[index];
                    rules[index] = value;
                    sync(); render();
                });
                command('↓', index === rules.length - 1, function () {
                    var value = rules[index + 1];
                    rules[index + 1] = rules[index];
                    rules[index] = value;
                    sync(); render();
                });
                command('Remove', false, function () {
                    rules.splice(index, 1); sync(); render();
                });
            });
        }
        $add.on('click', function () {
            rules.push({kind:'domain',match:'',target:'proxy'});
            sync(); render();
        });
        $addBt.on('click', function () {
            if (!rules.some(function (rule) {
                return rule.kind === 'protocol' &&
                    rule.match === 'bittorrent' && rule.target === 'direct';
            })) {
                rules.push({kind:'protocol',match:'bittorrent',target:'direct'});
            }
            instanceField('sniffing_enabled').prop('checked', true).trigger('change');
            sync(); render();
        });
        render();
    }

    $instance.on('shown.bs.modal', function () {
        var src = installSelect('subscription_uuid', 'xrayInstanceSource',
            'Manual JSON (Advanced)');
        if (!src) return;
        var current = src.field.val();
        var state = {
            manual: instanceField('selected_outbound').val() || '',
            fallback: instanceField('balancer_fallback').val() || '',
            pool: instanceField('balancer_nodes').val() || '[]'
        };
        installPool();
        installRoutingEditor();
        setVisibleRoutingMode();
        sources(function (rows) {
            src.select.empty().append($('<option>').val('').text('Manual JSON (Advanced)'));
            rows.forEach(function (row) {
                if (String(row.enabled) !== '0') {
                    src.select.append($('<option>').val(row.uuid).text(row.name || row.uuid));
                }
            });
            src.select.val(current);
            if (src.select.val() !== current) src.select.val('');
            fillInstanceNodes(src.select.val(), state, rows);
        });
    });

    $instance.on('change', '#xrayInstanceSource', function () {
        sources(function (rows) {
            fillInstanceNodes($('#xrayInstanceSource').val(), {
                manual: '',
                fallback: instanceField('balancer_fallback').val() || '',
                pool: instanceField('balancer_nodes').val() || '[]'
            }, rows);
        });
    });

    $instance.on('change', '[id="instance.route_mode"]', setVisibleRoutingMode);
    $instance.on('change', '[id="instance.sniffing_enabled"]', function () {
        $instance.find('[id="row_instance.sniffing_route_only"]')
            .toggle($(this).prop('checked'));
    });

    // Advanced displays exactly the last applied config file, never a newly
    // assembled subscription preview or an unsaved candidate.
    function advancedStatus(text) {
        $('#xrayAdvancedNote').text(text);
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
        $('#xrayAdvancedCopy').prop('disabled', true);
        $('#xrayAdvancedSummary').text('');
        if (!uuid) {
            advancedStatus('Choose an instance to view its applied Xray configuration.');
            return;
        }
        advancedStatus('Reading the last applied Xray configuration...');
        $.post('/api/xray/service/config/' + encodeURIComponent(uuid), {})
            .done(function (response) {
                // Ignore a delayed response for a previously selected instance.
                if ($('#xrayAdvancedInstance').val() !== uuid) return;
                if (!response || response.result !== 'ok' || !response.config) {
                    advancedStatus((response && response.message) ||
                        'No applied configuration is available. Save and Apply the instance first.');
                    return;
                }
                try {
                    var formatted = JSON.stringify(response.config, null, 2);
                    $('#xrayAdvancedJson').val(formatted);
                    $('#xrayAdvancedCopy').prop('disabled', false);
                    var state = response.xray_core === 'running' ? 'Xray running' : 'Xray stopped';
                    var balancers = (response.config.routing || {}).balancers || [];
                    if (balancers.length) {
                        var balancer = balancers[0];
                        $('#xrayAdvancedSummary').text(
                            state + ' | LeastLoad pool: ' + (balancer.selector || []).join(', ') +
                            ' | Fallback: ' + (balancer.fallbackTag || '(none)')
                        );
                    } else {
                        $('#xrayAdvancedSummary').text(
                            state + ' | Manual node: ' + (response.default_outbound || '(not defined)')
                        );
                    }
                    advancedStatus(
                        'Last applied on-disk config. Includes all declared outbounds, inbounds ' +
                        'and routing rules. The first outbound is the default for traffic ' +
                        'without a matching routing rule; other outbounds can still be ' +
                        'selected by routing. Contains credentials — do not publish it.'
                    );
                } catch (e) {
                    advancedStatus('Could not display applied JSON: ' + e.message);
                }
            })
            .fail(function (xhr) {
                if ($('#xrayAdvancedInstance').val() === uuid) {
                    advancedStatus('Cannot read applied configuration: HTTP ' + xhr.status);
                }
            });
    });

    $('#xrayAdvancedCopy').on('click', function () {
        if ($(this).prop('disabled')) return;
        var value = $('#xrayAdvancedJson').val();
        if (!value) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                advancedStatus('Copied applied JSON. It contains credentials; handle it securely.');
            }).catch(function () {
                advancedStatus('Browser blocked clipboard access. Select and copy the text manually.');
            });
        } else {
            $('#xrayAdvancedJson').trigger('focus').trigger('select');
            advancedStatus('Select and copy the JSON manually. It contains credentials.');
        }
    });
});
</script>
