<div id="subscriptions" class="tab-pane fade in">
    <div class="row">
        <section class="col-xs-12">
            <div class="clearfix" style="padding:12px 0;">
                <button type="button" class="btn btn-primary btn-sm" id="xraySourceAdd">
                    <i class="fa fa-plus"></i> Add subscription
                </button>
                <span class="text-muted" style="margin-left:10px;">
                    A subscription contains one or more outbounds. Refresh downloads and parses nodes; Apply activates a selection.
                </span>
            </div>
            <div id="xraySourceNotice" class="alert" style="display:none;"></div>
            <table class="table table-striped table-condensed">
                <thead><tr>
                    <th>Name</th><th>Type</th><th>Nodes</th><th>Last refreshed</th><th>Actions</th>
                </tr></thead>
                <tbody id="xraySourceRows"></tbody>
            </table>

            <div id="xraySourceEditor" class="panel panel-default" style="display:none;width:100%;max-width:none;">
                <div class="panel-heading"><strong id="xraySourceEditorTitle">Add subscription</strong></div>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="xraySourceName">Name</label>
                        <input type="text" class="form-control" id="xraySourceName" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="xraySourceType">Source type</label>
                        <select class="form-control" id="xraySourceType">
                            <option value="url">HTTPS subscription URL</option>
                            <option value="links">Paste VLESS links</option>
                        </select>
                    </div>
                    <div class="form-group" id="xraySourceUrlGroup">
                        <label for="xraySourceUrl">Subscription URL</label>
                        <input type="url" class="form-control" id="xraySourceUrl" placeholder="https://provider.example/subscription/...">
                    </div>
                    <div class="form-group" id="xraySourceLinksGroup" style="display:none;">
                        <label for="xraySourceLinks">VLESS links (one per line, or base64 subscription content)</label>
                        <textarea class="form-control" id="xraySourceLinks" rows="16" spellcheck="false"
                                  style="width:100%;min-height:360px;resize:vertical;font-family:monospace;"
                                  placeholder="vless://...#Kalamar-US"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary" id="xraySourceSave">Save and refresh</button>
                    <button type="button" class="btn btn-default" id="xraySourceCancel">Cancel</button>
                </div>
            </div>
            <div id="xraySourceNodes" class="panel panel-default" style="display:none;">
                <div class="panel-heading"><strong id="xrayNodesTitle">Nodes</strong></div>
                <div class="panel-body">
                    <table class="table table-condensed">
                        <thead><tr><th>Name / Xray tag</th><th>Protocol</th><th>Server</th><th>Port</th></tr></thead>
                        <tbody id="xrayNodesRows"></tbody>
                    </table>
                    <p class="text-muted">Presence in the subscription does not prove connectivity. Select a node on the Instances tab and test from the lab client.</p>
                </div>
            </div>
        </section>
    </div>
</div>
