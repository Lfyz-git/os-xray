<style>
    #advanced .xray-advanced-body {
        padding: 16px 24px 24px;
        width: 100%;
        box-sizing: border-box;
    }
    #advanced .xray-advanced-body .form-group > label {
        display: block;
        margin-bottom: 8px;
    }
    #advanced .xray-advanced-instance-group {
        max-width: 480px;
    }
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
    <div class="xray-advanced-body">
        <div class="form-group xray-advanced-instance-group">
            <label for="xrayAdvancedInstance">Instance</label>
            <select class="form-control" id="xrayAdvancedInstance">
                <option value="">Choose instance</option>
            </select>
        </div>
        <div class="form-group xray-json-editor-group">
            <label for="xrayAdvancedJson">Applied Xray configuration (JSON)</label>
            <textarea class="form-control" id="xrayAdvancedJson" rows="28" spellcheck="false"
                      readonly placeholder="Choose an instance to load its applied Xray configuration."></textarea>
        </div>
        <div class="clearfix" style="margin:10px 0;">
            <button type="button" class="btn btn-default btn-sm" id="xrayAdvancedCopy" disabled>
                <i class="fa fa-copy"></i> Copy JSON
            </button>
            <span id="xrayAdvancedSummary" class="text-muted" style="margin-left:12px;" role="status"></span>
        </div>
        <p id="xrayAdvancedNote" class="text-muted" role="status">
            Displays the last applied Xray configuration for this instance, including inbounds,
            outbounds and routing. Read-only; edit settings in their respective tabs.
            This JSON contains connection credentials — do not share it publicly.
        </p>
    </div>
</div>
