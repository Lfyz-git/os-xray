<style>
    /* OPNsense theme constrains form controls; the Advanced editor needs the
       available content width, not the compact form-control default. */
    #advanced .xray-json-editor-group {
        width: 100%;
        max-width: none !important;
        min-width: 0;
    }
    #advanced #xrayAdvancedJson {
        display: block;
        width: 100% !important;
        max-width: none !important;
        min-width: 0;
        min-height: 520px;
        box-sizing: border-box;
        font-family: monospace;
        resize: vertical;
    }
</style>
<div id="advanced" class="tab-pane fade in">
    <div class="row">
        <section class="col-xs-12">
            <div class="form-group" style="padding-top:14px;max-width:480px;">
                <label for="xrayAdvancedInstance">Instance</label>
                <select class="form-control" id="xrayAdvancedInstance">
                    <option value="">Choose instance</option>
                </select>
            </div>
            <div class="form-group xray-json-editor-group">
                <label for="xrayAdvancedJson">Manual Outbounds JSON</label>
                <textarea class="form-control" id="xrayAdvancedJson" rows="28" spellcheck="false" disabled></textarea>
            </div>
            <button type="button" class="btn btn-primary" id="xrayAdvancedSave">Save JSON</button>
            <p id="xrayAdvancedNote" class="text-muted" style="margin-top:8px;">
                This is the manual outbound fallback. Subscription nodes are managed in the Subscriptions tab.
            </p>
        </section>
    </div>
</div>
