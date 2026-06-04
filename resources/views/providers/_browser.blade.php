{{-- Full-width channel + group browser (channels 75% / groups 25%).
     Markup only — styles + the GXP controller live in providers/_grid.blade.php,
     which must be included on the same page. --}}
<div class="gx-browse-pane" id="gx-browse-pane" hidden>
    <div class="gx-browse-head">
        <h2>Channels — <span id="gx-browse-name"></span></h2>
        <input id="gx-browse-search" placeholder="Filter name / group / tvg-name…">
        <span class="gx-count" id="gx-browse-count"></span>
        <button class="gx-btn secondary" type="button" onclick="GXP.closeBrowse()">Close</button>
    </div>
    <div class="gx-split">
        <div class="gx-pane gx-pane-ch">
            <div id="provider-channels"></div>
            <div class="gx-toolbar">
                <button title="Add channel" onclick="GXP.toggleAddChannel()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                </button>
                <button title="Reload channels" onclick="GXP.reloadBrowse()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                </button>
                <span class="gx-addinline" id="gx-addrow" hidden>
                    <input id="gx-add-name" placeholder="Name *">
                    <select id="gx-add-group" title="Group"></select>
                    <input id="gx-add-url" placeholder="Stream URL *">
                    <button class="gx-btn" type="button" onclick="GXP.addChannel()">Add</button>
                    <button class="gx-btn secondary" type="button" onclick="GXP.toggleAddChannel(false)">Cancel</button>
                    <span class="gx-add-err" id="gx-add-err"></span>
                </span>
            </div>
        </div>
        <div class="gx-pane gx-pane-gr">
            <div class="gx-pane-title">Groups</div>
            <div id="provider-groups"></div>
            <div class="gx-toolbar">
                <button title="Add group" onclick="GXP.toggleAddGroup()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                </button>
                <button title="Reload groups" onclick="GXP.reloadGroups()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                </button>
                <span class="gx-addinline" id="gx-addgrouprow" hidden>
                    <input id="gx-add-grouptitle" placeholder="Group name *">
                    <button class="gx-btn" type="button" onclick="GXP.addGroup()">Add</button>
                    <button class="gx-btn secondary" type="button" onclick="GXP.toggleAddGroup(false)">Cancel</button>
                    <span class="gx-add-err" id="gx-addgroup-err"></span>
                </span>
            </div>
        </div>
    </div>
</div>
