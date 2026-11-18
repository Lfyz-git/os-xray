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
        <h4>Xray-core package</h4>
        <p class="text-muted">
            Xray-core is managed separately from the os-xray plugin using the official
            OPNsense package repository. Updates temporarily stop running tunnels,
            validate the new core and restart those same tunnels on success.
            If upgrading or validation fails, tunnels may remain stopped.
        </p>
        <div class="form-group">
            <span>Installed: <strong id="xrayCorePackageInstalled">—</strong></span>
            <span style="margin-left: 16px;">Available: <strong id="xrayCorePackageAvailable">—</strong></span>
            <span style="margin-left: 16px;">Source: <strong id="xrayCorePackageRepository">OPNsense</strong></span>
        </div>
        <div class="form-group">
            <button id="xrayCorePackageCheck" type="button" class="btn btn-default btn-sm">
                Check core updates
            </button>
            <button id="xrayCorePackageUpgrade" type="button" class="btn btn-warning btn-sm" disabled>
                Update Xray-core
            </button>
            <span id="xrayCorePackageMessage" role="status" class="text-muted" style="margin-left:12px;"></span>
        </div>
        <hr>

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
