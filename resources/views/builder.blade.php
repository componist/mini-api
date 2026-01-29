<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:,">
    <title>Mini API – Config-Builder</title>
    <style>
        .builder-body { background: #f1f5f9; min-height: 100vh; padding: 1.5rem; }
        .builder-container { max-width: 56rem; margin: 0 auto; }
        .builder-card { background: #fff; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 1.25rem; }
        .builder-title { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .builder-subtitle { color: #64748b; margin-top: 0.25rem; }
        .builder-heading { font-weight: 600; color: #334155; margin-bottom: 0.75rem; }
        .builder-flex { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .builder-label { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.75rem; border-radius: 0.25rem; border: 1px solid #e2e8f0; cursor: pointer; }
        .builder-label:hover { background: #f8fafc; }
        .builder-label.selected { border-color: #6366f1; background: #eef2ff; }
        .builder-input { width: 100%; border-radius: 0.25rem; border: 1px solid #cbd5e1; padding: 0.5rem 0.75rem; }
        .builder-btn { padding: 0.5rem 1rem; border-radius: 0.25rem; border: none; cursor: pointer; font-weight: 500; }
        .builder-btn-indigo { background: #4f46e5; color: #fff; }
        .builder-btn-indigo:hover { background: #4338ca; }
        .builder-btn-slate { background: #475569; color: #fff; }
        .builder-btn-slate:hover { background: #334155; }
        .builder-btn-emerald { background: #059669; color: #fff; }
        .builder-btn-emerald:hover { background: #047857; }
        .builder-link { font-size: 0.875rem; color: #4f46e5; cursor: pointer; background: none; border: none; text-decoration: underline; }
        .builder-pre { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 0.25rem; font-size: 0.875rem; overflow-x: auto; white-space: pre-wrap; }
        .builder-space > * + * { margin-top: 1.5rem; }
        .builder-gap { gap: 0.75rem; }
        .builder-msg-ok { color: #059669; }
        .builder-msg-err { color: #dc2626; }
        .builder-hint { color: #64748b; font-size: 0.8125rem; margin-top: 0.25rem; line-height: 1.4; }
        .builder-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .builder-step { border-left: 3px solid #e2e8f0; padding-left: 1rem; }
        .builder-step.active { border-left-color: #6366f1; }
        .builder-badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 9999px; background: #eef2ff; color: #4338ca; font-size: 0.75rem; font-weight: 500; }
        .builder-summary { background: #f8fafc; border-radius: 0.25rem; padding: 0.75rem 1rem; font-size: 0.875rem; color: #475569; }
    </style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="builder-body" x-data="builderApp()">
    <div class="builder-container">
        <header style="margin-bottom: 2rem;">
            <h1 class="builder-title">Mini API – Config-Builder</h1>
            <p class="builder-subtitle">Endpoint-Config per Klick erzeugen – einzeln oder mehrere Endpoints sammeln und gemeinsam schreiben.</p>
            <p class="builder-hint" style="margin-top: 0.5rem;">Ablauf: Tabelle wählen → Spalten (und optional Model/Relationen) → Key & Route eintragen → „Zur Liste hinzufügen“ oder direkt „In Config schreiben“.</p>
        </header>

        <div class="builder-space">
            <!-- Gesammelte Endpoints -->
            <section class="builder-card" x-show="endpointsList.length > 0">
                <h2 class="builder-heading">Deine Endpoints <span class="builder-badge" x-text="endpointsList.length + ' in der Liste'"></span></h2>
                <p class="builder-hint">Diese Endpoints werden gemeinsam in die Config geschrieben. Reihenfolge per „Entfernen“ anpassen.</p>
                <ul style="list-style: none; padding: 0; margin: 0.75rem 0 0 0;">
                    <template x-for="(ep, i) in endpointsList" :key="'ep-'+i">
                        <li class="builder-flex builder-gap" style="align-items: center; margin-bottom: 0.5rem; padding: 0.625rem 0.75rem; background: #f8fafc; border-radius: 0.25rem; border: 1px solid #e2e8f0;">
                            <span x-text="(i+1) + '. ' + ep.key + ' → /api/' + ep.config.route + ' (' + (ep.config.table || (ep.config.model ? 'Model' : '')) + ')'"></span>
                            <button type="button" @click="removeEndpointFromList(i)" class="builder-link" style="margin-left: auto;" title="Aus Liste entfernen">Entfernen</button>
                        </li>
                    </template>
                </ul>
            </section>

            <!-- Aktueller Endpoint (Zusammenfassung) -->
            <section class="builder-card builder-step" :class="canAddCurrentEndpoint() ? 'active' : ''" x-show="selectedTable">
                <h2 class="builder-heading">Aktuell konfigurierter Endpoint</h2>
                <p class="builder-summary" x-show="selectedTable">
                    <span x-text="'Tabelle: ' + selectedTable"></span>
                    <span x-show="endpointKey" x-text="' · Key: ' + endpointKey"></span>
                    <span x-show="endpointRoute" x-text="' · Route: /api/' + endpointRoute"></span>
                    <span x-show="selectedColumns.length" x-text="' · ' + selectedColumns.length + ' Spalten'"></span>
                    <span x-show="selectedModel" x-text="' · Model + Relationen'"></span>
                </p>
            </section>

            <!-- Schritt 1: Tabelle wählen -->
            <section class="builder-card builder-step" :class="selectedTable ? 'active' : ''">
                <h2 class="builder-heading">1. Tabelle wählen</h2>
                <p class="builder-hint">Wähle die Datenbanktabelle, die der API-Endpoint auslesen soll.</p>
                <div class="builder-flex" style="margin-top: 0.75rem;" x-show="Array.isArray(tables) && tables.length">
                    <template x-for="(t, i) in (Array.isArray(tables) ? tables : [])" :key="'table-'+i">
                        <label class="builder-label" :class="selectedTable === t ? 'selected' : ''">
                            <input type="radio" name="table" :value="t" x-model="selectedTable" @change="onTableChange()">
                            <span x-text="t"></span>
                        </label>
                    </template>
                </div>
                <p x-show="(!Array.isArray(tables) || !tables.length) && !loadingTables" style="color: #64748b;">Keine Tabellen geladen.</p>
                <p x-show="loadingTables" style="color: #64748b;">Lade Tabellen…</p>
            </section>

            <!-- Schritt 2: Spalten -->
            <section class="builder-card builder-step" x-show="selectedTable" :class="selectedTable ? 'active' : ''">
                <h2 class="builder-heading">2. Spalten (columns)</h2>
                <p class="builder-hint">Welche Spalten soll die API zurückgeben? Leer = alle (*).</p>
                <div class="builder-flex builder-gap" style="margin: 0.5rem 0 0.75rem 0;">
                    <button type="button" @click="selectAllColumns(true)" class="builder-link">Alle auswählen</button>
                    <button type="button" @click="selectAllColumns(false)" class="builder-link">Alle abwählen</button>
                </div>
                <div class="builder-flex">
                    <template x-for="(c, i) in (Array.isArray(columns) ? columns : [])" :key="'col-'+i">
                        <label class="builder-label">
                            <input type="checkbox" :value="c" x-model="selectedColumns">
                            <span x-text="c"></span>
                        </label>
                    </template>
                </div>
                <p x-show="selectedTable && (!Array.isArray(columns) || !columns.length) && !loadingColumns" style="color: #64748b;">Keine Spalten.</p>
                <p x-show="loadingColumns" style="color: #64748b;">Lade Spalten…</p>
            </section>

            <!-- Optional: Model -->
            <section class="builder-card builder-step" x-show="selectedTable">
                <h2 class="builder-heading">3. Optional: Model (für Relationen)</h2>
                <p class="builder-hint">Wenn ein Eloquent-Model zur Tabelle existiert, kannst du Relationen (z. B. company, company.country) mit ausgeben.</p>
                <select x-model="selectedModel" @change="onModelChange()" class="builder-input" style="max-width: 28rem; margin-top: 0.5rem;" title="Model für Eager Loading">
                    <option value="">— Nur Tabelle (kein Model) —</option>
                    <template x-for="(m, i) in (Array.isArray(modelsForTable) ? modelsForTable : [])" :key="'model-'+i">
                        <option :value="m.class" x-text="m.class"></option>
                    </template>
                </select>
            </section>

            <!-- Optional: Relationen -->
            <section class="builder-card builder-step" x-show="selectedModel">
                <h2 class="builder-heading">4. Optional: Relationen</h2>
                <p class="builder-hint">Relationen, die mitgeladen werden (z. B. company, applications.user).</p>
                <div class="builder-flex" style="margin-top: 0.5rem;">
                    <template x-for="(r, i) in (Array.isArray(relationOptions) ? relationOptions : [])" :key="'rel-'+i">
                        <label class="builder-label">
                            <input type="checkbox" :value="r" x-model="selectedRelations">
                            <span x-text="r"></span>
                        </label>
                    </template>
                </div>
                <p x-show="selectedModel && (!Array.isArray(relationOptions) || !relationOptions.length) && !loadingRelations" style="color: #64748b;">Keine Relationen oder noch nicht geladen.</p>
                <p x-show="loadingRelations" style="color: #64748b;">Lade Relationen…</p>
            </section>

            <!-- Route & Key -->
            <section class="builder-card builder-step">
                <h2 class="builder-heading">5. Endpoint-Key & Route</h2>
                <p class="builder-hint">Eindeutiger Key in der Config und die URL der API (z. B. <code>users</code> → GET /api/users).</p>
                <div style="display: grid; gap: 0.75rem; max-width: 28rem; margin-top: 0.5rem;">
                    <div>
                        <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #334155; margin-bottom: 0.25rem;">Config-Key</label>
                        <input type="text" x-model="endpointKey" placeholder="z. B. users" class="builder-input" title="Eindeutiger Name in config/mini-api.php">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #334155; margin-bottom: 0.25rem;">API-Route (Pfad nach /api/)</label>
                        <input type="text" x-model="endpointRoute" placeholder="z. B. users" class="builder-input" title="URL-Pfad, z. B. users → /api/users">
                    </div>
                </div>
            </section>

            <!-- Endpoint zur Liste hinzufügen / Schreiben -->
            <section class="builder-card">
                <h2 class="builder-heading">6. Hinzufügen oder Schreiben</h2>
                <p class="builder-hint">Einzeln: Konfiguration oben auswählen und „In Config schreiben“. Mehrere: erst „Zur Liste hinzufügen“, dann für alle „In Config schreiben“.</p>
                <div class="builder-flex builder-gap" style="margin-top: 0.75rem; flex-wrap: wrap; align-items: center;">
                    <button type="button" @click="addEndpointToList()" :disabled="!canAddCurrentEndpoint()" :title="canAddCurrentEndpoint() ? 'Aktuellen Endpoint zur Liste hinzufügen' : 'Bitte zuerst Tabelle wählen und Key/Route eintragen'" class="builder-btn builder-btn-indigo">Endpoint zur Liste hinzufügen</button>
                    <button type="button" @click="previewConfig()" :disabled="!hasAnythingToExport()" title="PHP-Array anzeigen" class="builder-btn builder-btn-indigo">Vorschau</button>
                    <button type="button" @click="copyToClipboard()" :disabled="!hasAnythingToExport()" title="In Zwischenablage kopieren" class="builder-btn builder-btn-slate">Kopieren</button>
                    <button type="button" @click="writeConfig()" :disabled="!hasAnythingToExport()" :title="hasAnythingToExport() ? 'In config/mini-api.php schreiben' : 'Zuerst Endpoint konfigurieren oder zur Liste hinzufügen'" class="builder-btn builder-btn-emerald">In Config schreiben</button>
                </div>
                <p x-show="message" style="margin-top: 0.75rem; font-size: 0.875rem; font-weight: 500;" :class="messageSuccess ? 'builder-msg-ok' : 'builder-msg-err'" x-text="message"></p>
            </section>

            <!-- Vorschau -->
            <section class="builder-card" x-show="previewText">
                <h2 class="builder-heading">Vorschau <button type="button" @click="copyPreviewToClipboard()" class="builder-link" style="font-size: 0.875rem; margin-left: 0.5rem;">Kopieren</button></h2>
                <pre class="builder-pre" x-text="previewText" id="builder-preview"></pre>
            </section>
        </div>
    </div>

    <script>
        function builderApp() {
            const base = '/{{ rtrim(config("mini-api.builder.route", "mini-api-builder"), "/") }}';
            return {
                base,
                tables: [],
                columns: [],
                models: [],
                modelsForTable: [],
                relationOptions: [],
                selectedTable: '',
                selectedColumns: [],
                selectedModel: '',
                selectedRelations: [],
                endpointKey: '',
                endpointRoute: '',
                endpointsList: [],
                loadingTables: true,
                loadingColumns: false,
                loadingRelations: false,
                previewText: '',
                message: '',
                messageSuccess: false,

                init() {
                    this.loadTables();
                    this.loadModels();
                },

                async loadTables() {
                    this.loadingTables = true;
                    try {
                        const r = await fetch(`${this.base}/api/tables`);
                        const d = await r.json();
                        this.tables = Array.isArray(d.tables) ? d.tables : [];
                    } catch (e) {
                        this.tables = [];
                    }
                    this.loadingTables = false;
                },

                async loadModels() {
                    try {
                        const r = await fetch(`${this.base}/api/models`);
                        const d = await r.json();
                        this.models = Array.isArray(d.models) ? d.models : [];
                    } catch (e) {
                        this.models = [];
                    }
                },

                onTableChange() {
                    this.columns = [];
                    this.selectedColumns = [];
                    this.selectedModel = '';
                    this.relationOptions = [];
                    this.selectedRelations = [];
                    if (!this.selectedTable) return;
                    this.loadingColumns = true;
                    fetch(`${this.base}/api/tables/${encodeURIComponent(this.selectedTable)}/columns`)
                        .then(res => res.json())
                        .then(d => { this.columns = Array.isArray(d.columns) ? d.columns : []; })
                        .finally(() => { this.loadingColumns = false; });
                    this.modelsForTable = this.models.filter(m => m.table === this.selectedTable);
                    this.endpointKey = this.selectedTable.replace(/-/g, '_');
                    this.endpointRoute = this.selectedTable.replace(/_/g, '-');
                },

                onModelChange() {
                    this.relationOptions = [];
                    this.selectedRelations = [];
                    if (!this.selectedModel) return;
                    this.loadingRelations = true;
                    const modelParam = encodeURIComponent(this.selectedModel.replace(/\\/g, '.'));
                    fetch(`${this.base}/api/models/${modelParam}/relations`)
                        .then(res => res.json())
                        .then(d => {
                            const list = d.nested ? Object.keys(d.nested) : (d.relations || []);
                            this.relationOptions = Array.isArray(list) ? list : [];
                        })
                        .finally(() => { this.loadingRelations = false; });
                },

                selectAllColumns(checked) {
                    if (checked) this.selectedColumns = [...this.columns];
                    else this.selectedColumns = [];
                },

                canAddCurrentEndpoint() {
                    return !!(this.selectedTable && (this.endpointKey || this.endpointRoute || this.selectedTable));
                },

                hasAnythingToExport() {
                    return this.getAllEndpointsToWrite().length > 0;
                },

                addEndpointToList() {
                    const obj = this.buildConfigObject();
                    if (!obj.config.table && !obj.config.model) return;
                    this.endpointsList.push({ key: obj.key, config: obj.config });
                    this.message = 'Endpoint „' + obj.key + '“ zur Liste hinzugefügt.';
                    this.messageSuccess = true;
                    setTimeout(() => { this.message = ''; }, 2500);
                },

                removeEndpointFromList(index) {
                    this.endpointsList.splice(index, 1);
                },

                buildConfigObject() {
                    const key = this.endpointKey || this.selectedTable?.replace(/-/g, '_') || 'endpoint';
                    const route = this.endpointRoute || this.selectedTable?.replace(/_/g, '-') || 'data';
                    const config = { route, columns: this.selectedColumns.length ? this.selectedColumns : ['*'] };
                    if (this.selectedModel) {
                        config.model = this.selectedModel;
                        if (this.selectedRelations.length) config.relations = this.selectedRelations;
                    } else {
                        config.table = this.selectedTable || key;
                    }
                    return { key, config };
                },

                getAllEndpointsToWrite() {
                    if (this.endpointsList.length > 0) {
                        return this.endpointsList.map(ep => ({ key: ep.key, config: ep.config }));
                    }
                    const single = this.buildConfigObject();
                    return single.config.table || single.config.model ? [single] : [];
                },

                previewConfig() {
                    const all = this.getAllEndpointsToWrite();
                    if (all.length === 0) { this.previewText = ''; return; }
                    this.previewText = all.map(({ key, config }) => this.configToPhp(key, config)).join('\n\n');
                },

                copyPreviewToClipboard() {
                    if (!this.previewText) return;
                    navigator.clipboard.writeText(this.previewText).then(() => {
                        this.message = 'Vorschau in Zwischenablage kopiert.';
                        this.messageSuccess = true;
                        setTimeout(() => { this.message = ''; }, 2500);
                    }).catch(() => {
                        this.message = 'Kopieren fehlgeschlagen.';
                        this.messageSuccess = false;
                    });
                },

                copyToClipboard() {
                    const all = this.getAllEndpointsToWrite();
                    if (all.length === 0) { this.message = 'Keinen Endpoint zum Kopieren.'; this.messageSuccess = false; return; }
                    const php = all.map(({ key, config }) => this.configToPhp(key, config)).join('\n\n');
                    navigator.clipboard.writeText(php).then(() => {
                        this.message = (all.length > 1 ? all.length + ' Endpoints' : 'Endpoint') + ' in Zwischenablage kopiert.';
                        this.messageSuccess = true;
                        setTimeout(() => { this.message = ''; }, 3000);
                    }).catch(() => {
                        this.message = 'Kopieren fehlgeschlagen.';
                        this.messageSuccess = false;
                    });
                },

                async writeConfig() {
                    const all = this.getAllEndpointsToWrite();
                    if (all.length === 0) { this.message = 'Keinen Endpoint zum Schreiben. Zuerst Tabelle/Model wählen oder zur Liste hinzufügen.'; this.messageSuccess = false; return; }
                    this.message = '';
                    try {
                        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                        const body = all.length > 1
                            ? { endpoints: all.map(({ key, config }) => ({ key, route: config.route, table: config.table || null, model: config.model || null, columns: config.columns, relations: config.relations || [] })) }
                            : { key: all[0].key, route: all[0].config.route, table: all[0].config.table || null, model: all[0].config.model || null, columns: all[0].config.columns, relations: all[0].config.relations || [] };
                        const r = await fetch(`${this.base}/api/config`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            body: JSON.stringify(body),
                        });
                        const d = await r.json();
                        if (!r.ok) {
                            this.message = d.error || 'Fehler beim Schreiben.';
                            this.messageSuccess = false;
                            return;
                        }
                        this.message = d.message || 'Config geschrieben.';
                        this.messageSuccess = true;
                        if (all.length > 1) this.endpointsList = [];
                    } catch (e) {
                        this.message = 'Netzwerkfehler.';
                        this.messageSuccess = false;
                    }
                    setTimeout(() => { this.message = ''; }, 5000);
                },

                configToPhp(key, config) {
                    const lines = ["        '" + key.replace(/'/g, "\\'") + "' => ["];
                    lines.push("            'route' => '" + (config.route || '').replace(/'/g, "\\'") + "',");
                    if (config.model) {
                        lines.push("            'model' => \\" + config.model + "::class,");
                    } else {
                        lines.push("            'table' => '" + (config.table || key).replace(/'/g, "\\'") + "',");
                    }
                    const cols = config.columns && config.columns.length ? config.columns : ['*'];
                    lines.push("            'columns' => [" + cols.map(c => "'" + c.replace(/'/g, "\\'") + "'").join(', ') + "],");
                    if (config.relations && config.relations.length) {
                        lines.push("            'relations' => [" + config.relations.map(r => "'" + r.replace(/'/g, "\\'") + "'").join(', ') + "],");
                    }
                    lines.push("        ],");
                    return lines.join("\n");
                },
            };
        }
    </script>
</body>
</html>
