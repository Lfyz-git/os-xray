<script>
$(function () {
    var $dialog = $('#DialogInstance');

    function outboundField() {
        return $dialog.find('[id="instance.outbound_config"]');
    }

    function readOutbounds() {
        var raw = $.trim(outboundField().val() || '');
        if (!raw) return [];
        var decoded = JSON.parse(raw);
        var list = Array.isArray(decoded) ? decoded : [decoded];
        if (!list.length || list.some(function (item) {
            return !item || typeof item !== 'object' || Array.isArray(item);
        })) throw new Error('Outbounds must be a non-empty JSON array of objects');
        return list;
    }

    function validateOutbounds(list) {
        if (!list.length) throw new Error('At least one outbound is required');
        var tags = {direct: true};
        list.forEach(function (item) {
            if (!item || typeof item !== 'object' || Array.isArray(item) ||
                    typeof item.protocol !== 'string' || !item.protocol.trim()) {
                throw new Error('Each outbound must have a protocol');
            }
            var tag = item.tag;
            if (list.length === 1 && tag === undefined) return;
            if (typeof tag !== 'string' || !tag.trim() || tags[tag]) {
                throw new Error('Outbound tags must be unique, non-empty and not "direct"');
            }
            tags[tag] = true;
        });
    }

    function writeOutbounds(list) {
        validateOutbounds(list);
        outboundField().val(JSON.stringify(list, null, 2)).trigger('change');
    }

    function reportError(message) {
        $('#xrayOutboundError').text(message || '').toggle(!!message);
    }

    function uniqueTag(list, base) {
        var used = list.map(function (item) { return item.tag; });
        var tag = base || 'proxy';
        var suffix = 2;
        while (tag === 'direct' || used.indexOf(tag) !== -1) {
            tag = (base || 'proxy') + '-' + suffix++;
        }
        return tag;
    }

    function renderOutbounds() {
        var list;
        var $entries = $('#xrayOutboundEntries').empty();
        if (!$entries.length) return;
        try {
            list = readOutbounds();
            if (list.length) validateOutbounds(list);
            reportError('');
        } catch (e) {
            reportError(e.message);
            return;
        }

        list.forEach(function (item, index) {
            var settings = item.settings || {};
            var next = settings.vnext && settings.vnext[0] || {};
            var host = settings.address || next.address || '';
            var port = settings.port || next.port || '';
            var $entry = $('<div class="panel panel-default" style="margin-bottom:6px;">');
            var $heading = $('<div class="panel-heading" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">');
            $('<strong>').text((index === 0 ? 'Default: ' : '') + (item.tag || '(untagged)'))
                .appendTo($heading);
            $('<span class="text-muted">').text(item.protocol + (host ? ' — ' + host + (port ? ':' + port : '') : ''))
                .appendTo($heading);
            var $actions = $('<span style="margin-left:auto;white-space:nowrap;">').appendTo($heading);
            var $edit = $('<button type="button" class="btn btn-xs btn-default">Edit JSON</button>').appendTo($actions);
            var $up = $('<button type="button" class="btn btn-xs btn-default">↑</button>').prop('disabled', index === 0).appendTo($actions);
            var $down = $('<button type="button" class="btn btn-xs btn-default">↓</button>').prop('disabled', index === list.length - 1).appendTo($actions);
            var $del = $('<button type="button" class="btn btn-xs btn-default">Remove</button>').appendTo($actions);
            var $editor = $('<div class="panel-body" style="display:none;">');
            var $input = $('<textarea rows="9" class="form-control" spellcheck="false" style="font-family:monospace;">')
                .val(JSON.stringify(item, null, 2)).appendTo($editor);
            var $save = $('<button type="button" class="btn btn-sm btn-primary" style="margin-top:6px;">Save outbound</button>')
                .appendTo($editor);
            $entry.append($heading, $editor).appendTo($entries);
            $edit.on('click', function () { $editor.toggle(); });
            $save.on('click', function () {
                try {
                    var updated = JSON.parse($input.val());
                    var nextList = list.slice();
                    nextList[index] = updated;
                    writeOutbounds(nextList);
                } catch (e) { reportError(e.message); }
            });
            $del.on('click', function () {
                try {
                    var nextList = list.slice();
                    nextList.splice(index, 1);
                    writeOutbounds(nextList);
                } catch (e) { reportError(e.message); }
            });
            $up.on('click', function () {
                if (index > 0) {
                    var nextList = list.slice();
                    nextList.splice(index - 1, 0, nextList.splice(index, 1)[0]);
                    writeOutbounds(nextList);
                }
            });
            $down.on('click', function () {
                if (index < list.length - 1) {
                    var nextList = list.slice();
                    nextList.splice(index + 1, 0, nextList.splice(index, 1)[0]);
                    writeOutbounds(nextList);
                }
            });
        });
    }

    $dialog.on('shown.bs.modal', function () {
        var $field = outboundField();
        if (!$field.length) return;
        if (!$('#xrayOutboundsManager').length) {
            $field.before(
                '<div id="xrayOutboundsManager" class="well well-sm">' +
                  '<div style="display:flex;gap:6px;align-items:center;margin-bottom:8px;">' +
                    '<strong>Outbounds</strong>' +
                    '<button type="button" id="xrayDuplicateOutbound" class="btn btn-xs btn-default">Duplicate last</button>' +
                    '<button type="button" id="xrayPasteOutbound" class="btn btn-xs btn-default">Add JSON</button>' +
                  '</div>' +
                  '<p class="text-muted small">First outbound is the default route. Use the VLESS import button above or add JSON; tags must be unique.</p>' +
                  '<div id="xrayOutboundEntries"></div>' +
                  '<div id="xrayNewOutbound" style="display:none;">' +
                    '<textarea class="form-control" rows="7" spellcheck="false" placeholder="Paste one Xray outbound JSON object"></textarea>' +
                    '<button type="button" id="xraySaveNewOutbound" class="btn btn-sm btn-primary" style="margin-top:6px;">Add outbound</button>' +
                  '</div>' +
                  '<div id="xrayOutboundError" class="text-danger" style="display:none;margin-top:6px;"></div>' +
                '</div>'
            );
        }
        $('#xrayNewOutbound').hide();
        renderOutbounds();
    });

    $dialog.on('change', '[id="instance.outbound_config"]', renderOutbounds);
    $dialog.on('click', '#xrayDuplicateOutbound', function () {
        try {
            var list = readOutbounds();
            if (!list.length) throw new Error('Import or add the first outbound before duplicating');
            var clone = JSON.parse(JSON.stringify(list[list.length - 1]));
            clone.tag = uniqueTag(list, clone.tag || 'proxy');
            list.push(clone);
            writeOutbounds(list);
        } catch (e) { reportError(e.message); }
    });
    $dialog.on('click', '#xrayPasteOutbound', function () { $('#xrayNewOutbound').toggle(); });
    $dialog.on('click', '#xraySaveNewOutbound', function () {
        try {
            var item = JSON.parse($('#xrayNewOutbound textarea').val());
            var list = readOutbounds();
            if (!item || typeof item !== 'object' || Array.isArray(item)) {
                throw new Error('Paste one outbound JSON object');
            }
            if (!item.tag) item.tag = uniqueTag(list, 'proxy');
            list.push(item);
            writeOutbounds(list);
            $('#xrayNewOutbound textarea').val('');
            $('#xrayNewOutbound').hide();
        } catch (e) { reportError(e.message); }
    });
});
</script>
