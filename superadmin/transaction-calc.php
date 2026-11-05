<?php
/**
 * Transaction Auto-Calc — configure, per editable transaction field, a formula that computes it.
 * The saved "active" rule-set auto-fills the Add/Edit Transaction form. A live calculator on the
 * right shows what the rules produce for any member / year / month. Same formula language and
 * security model as the Report Builder.
 */
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/logo-helper.php';
require_once 'includes/auto-calc-lib.php';

if (empty($_SESSION['rb_token'])) {
    $_SESSION['rb_token'] = bin2hex(random_bytes(16));
}
acEnsureTable($pdo);

$faviconHtml = function_exists('renderFavicon') ? renderFavicon() : '';
$members = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName ASC")->fetchAll(PDO::FETCH_ASSOC);
$years = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearID DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeYear = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' ORDER BY YearID DESC LIMIT 1")->fetchColumn();

$boot = [
    'token' => $_SESSION['rb_token'],
    'user' => $_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin',
    'members' => $members,
    'years' => $years,
    'activeYear' => (int)$activeYear,
    'months' => ['January','February','March','April','May','June','July','August','September','October','November','December'],
    'today' => date('Y-m-d'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto-Calc - Super Admin</title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        :root {
            --tc-violet: #5b21b6;
            --tc-violet-soft: #f3eefe;
            --tc-gold: #c49a2c;
            --tc-surface: var(--bg-secondary-solid, #fff);
            --tc-field: var(--bg-tertiary, #f8f9fa);
        }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .tc-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .tc-header h1 { margin: 0; font-size: 26px; color: var(--text-primary); display: flex; align-items: center; gap: 12px; }
        .tc-header h1 i { color: var(--tc-violet); }
        .tc-header p { margin: 4px 0 0; color: var(--text-secondary); font-size: 14px; max-width: 640px; }
        .tc-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .tc-actions .btn { white-space: nowrap; }
        .tc-btn-ghost { background: var(--tc-surface); color: var(--text-primary); border: 1px solid var(--border-color); }
        .tc-btn-ghost:hover { border-color: var(--tc-violet); color: var(--tc-violet); }
        .tc-btn-primary { background: linear-gradient(135deg, #7c3aed, var(--tc-violet)); color: #fff; border: none; }
        .tc-grid { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 18px; align-items: start; }
        @media (max-width: 920px) { .tc-grid { grid-template-columns: 1fr; } }
        .tc-card { background: var(--tc-surface); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; margin-bottom: 16px; }
        .tc-card > h2 { margin: 0 0 4px; font-size: 16px; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .tc-card > p.tc-sub { margin: 0 0 12px; font-size: 12.5px; color: var(--text-secondary); }
        .tc-field { border: 1px solid var(--border-color); border-radius: 12px; padding: 12px; margin-bottom: 12px; background: var(--tc-field); }
        .tc-field.tc-off { opacity: 0.6; }
        .tc-field-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .tc-field-head b { font-size: 14.5px; color: var(--text-primary); }
        .tc-field-head .tc-fmt { font-size: 11px; color: var(--text-secondary); background: var(--tc-violet-soft); color: var(--tc-violet); padding: 2px 8px; border-radius: 20px; margin-left: 6px; }
        .tc-toggles { display: flex; gap: 14px; align-items: center; }
        .tc-toggles label { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; color: var(--text-secondary); cursor: pointer; }
        .tc-formula-row { display: flex; gap: 6px; }
        .tc-formula { flex: 1; width: 100%; font-family: 'Consolas', 'Courier New', monospace; font-size: 13px; padding: 9px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--tc-surface); color: var(--text-primary); resize: vertical; min-height: 40px; }
        .tc-ins { background: var(--tc-violet-soft); color: var(--tc-violet); border: none; border-radius: 8px; padding: 0 12px; cursor: pointer; font-size: 13px; }
        .tc-result { margin-top: 7px; font-size: 13px; }
        .tc-result .ok { color: #0f7a4a; font-weight: 600; }
        .tc-result .err { color: #b4232a; }
        .tc-input { width: 100%; padding: 9px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--tc-field); color: var(--text-primary); font-size: 14px; box-sizing: border-box; }
        .tc-form-row { margin-bottom: 10px; }
        .tc-form-row label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
        .tc-two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .tc-out { border-top: 1px dashed var(--border-color); margin-top: 12px; padding-top: 12px; }
        .tc-out-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        .tc-out-row:last-child { border-bottom: none; }
        .tc-out-row .lab { color: var(--text-secondary); }
        .tc-out-row .val { font-weight: 700; color: var(--text-primary); font-variant-numeric: tabular-nums; }
        .tc-out-row .val.auto::after { content: ' ✨'; }
        .tc-ruleset-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; }
        .tc-ruleset-bar b { color: var(--text-primary); }
        .tc-chip { background: var(--tc-violet-soft); color: var(--tc-violet); padding: 2px 10px; border-radius: 20px; font-weight: 600; }
        .tc-ref { font-size: 12.5px; }
        .tc-ref summary { cursor: pointer; color: var(--tc-violet); font-weight: 600; margin-bottom: 8px; }
        .tc-ref-group { margin-bottom: 10px; }
        .tc-ref-group h4 { margin: 8px 0 4px; font-size: 12px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; }
        .tc-ref-item { display: inline-block; margin: 2px; padding: 3px 8px; border: 1px solid var(--border-color); border-radius: 7px; cursor: pointer; font-family: 'Consolas', monospace; font-size: 12px; background: var(--tc-surface); }
        .tc-ref-item:hover { border-color: var(--tc-violet); color: var(--tc-violet); }
        .tc-modal-back { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .tc-modal-back.show { display: flex; }
        .tc-modal { background: var(--tc-surface); border-radius: 14px; padding: 20px; width: min(560px, 92vw); max-height: 86vh; overflow: auto; }
        .tc-modal h3 { margin: 0 0 14px; color: var(--text-primary); }
        .tc-rs-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px; border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 8px; }
        .tc-rs-item .meta b { color: var(--text-primary); }
        .tc-rs-item .meta small { display: block; color: var(--text-secondary); }
        .tc-muted { color: var(--text-secondary); font-size: 13px; }
        .tc-note { background: var(--tc-violet-soft); border-radius: 10px; padding: 10px 12px; font-size: 12.5px; color: var(--text-primary); margin-bottom: 12px; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <div class="tc-header">
            <div>
                <h1><i class="fas fa-calculator"></i> Auto-Calc</h1>
                <p>Pick a module, then set a formula for each editable field. The <b>active</b> rule-set auto-fills that module's form. Formulas can reference other modules as <code>{module.Field}</code> — try the live calculator on the right.</p>
            </div>
            <div class="tc-actions">
                <button type="button" class="btn tc-btn-ghost" id="tcOpenBtn"><i class="fas fa-folder-open"></i> Open</button>
                <button type="button" class="btn tc-btn-ghost" id="tcSaveAsBtn"><i class="fas fa-copy"></i> Save as</button>
                <button type="button" class="btn tc-btn-primary" id="tcSaveBtn"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>

        <div class="tc-ruleset-bar">
            <i class="fas fa-cubes"></i>
            <span>Module:</span>
            <select id="tcModule" class="tc-input" style="width:auto; min-width:200px; padding:6px 10px;"></select>
            <span style="margin-left:14px;">Editing:</span> <b id="tcName">Default rules</b>
            <span id="tcActiveChip" class="tc-chip" style="display:none"><i class="fas fa-check"></i> Active</span>
            <button type="button" class="btn tc-btn-ghost" id="tcSetActiveBtn" style="display:none; padding:2px 10px; font-size:12px;"><i class="fas fa-star"></i> Make active</button>
        </div>

        <div class="tc-grid">
            <div>
                <div class="tc-card">
                    <h2><i class="fas fa-sliders-h"></i> Field rules</h2>
                    <p class="tc-sub">Enable a field and give it a formula. Tick <b>auto-fill</b> to have the form fill it in automatically.</p>
                    <div id="tcFields"><p class="tc-muted">Loading…</p></div>
                </div>
                <div class="tc-card">
                    <details class="tc-ref" id="tcRef">
                        <summary><i class="fas fa-book"></i> Fields &amp; functions you can use</summary>
                        <p class="tc-muted">Click any item to insert it into the formula box you last used.</p>
                        <div id="tcRefBody"></div>
                    </details>
                </div>
            </div>

            <div>
                <div class="tc-card">
                    <h2><i class="fas fa-vial"></i> Live calculator</h2>
                    <p class="tc-sub">Pick the details of a record to see what the rules compute. ✨ marks fields the form will auto-fill.</p>
                    <div class="tc-note"><i class="fas fa-info-circle"></i> These are the exact values the form will put in for the selected inputs.</div>
                    <div class="tc-two" id="calcInputs"></div>
                    <button type="button" class="btn tc-btn-primary" id="calcRun" style="width:100%; margin-top:6px;"><i class="fas fa-bolt"></i> Calculate</button>
                    <div class="tc-out" id="calcOut"><p class="tc-muted">Computed values appear here.</p></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Open modal -->
    <div class="tc-modal-back" id="tcOpenModal">
        <div class="tc-modal">
            <h3><i class="fas fa-folder-open"></i> Saved rule-sets</h3>
            <div id="tcRsList"><p class="tc-muted">Loading…</p></div>
            <div style="text-align:right; margin-top:12px;">
                <button type="button" class="btn tc-btn-ghost" data-close>Close</button>
            </div>
        </div>
    </div>

    <!-- Save modal -->
    <div class="tc-modal-back" id="tcSaveModal">
        <div class="tc-modal">
            <h3><i class="fas fa-save"></i> Save rule-set</h3>
            <div class="tc-form-row"><label>Name</label><input type="text" class="tc-input" id="saveName" placeholder="e.g. Monthly collection rules"></div>
            <div class="tc-form-row"><label>Description (optional)</label><input type="text" class="tc-input" id="saveDesc"></div>
            <label style="display:flex; align-items:center; gap:8px; font-size:13px; margin:8px 0;"><input type="checkbox" id="saveActive" checked> Make this the active rule-set (used by the form)</label>
            <label style="display:flex; align-items:center; gap:8px; font-size:13px; margin:8px 0;"><input type="checkbox" id="saveAsNew"> Save as a new rule-set (keep the current one)</label>
            <div style="text-align:right; margin-top:12px;">
                <button type="button" class="btn tc-btn-ghost" data-close>Cancel</button>
                <button type="button" class="btn tc-btn-primary" id="saveConfirm"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';
        var BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE); ?>;
        var API = 'api/auto-calc.php';
        var moduleKey = 'transactions';
        var meta = null;              // {module, modules, inputs, fields, variables, functions, default_config, active_config, rulesets, options}
        var config = null;            // current edited config {fields:{...}}
        var current = { id: 0, name: 'Default rules', active: true };
        var lastFocused = null;       // last formula textarea used (for insert)
        var calcEls = {};             // calculator input elements by key
        var moduleWired = false;

        function el(id) { return document.getElementById(id); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

        function api(action, opts) {
            opts = opts || {};
            var url = API + '?action=' + encodeURIComponent(action) + '&module=' + encodeURIComponent(moduleKey);
            var init = { method: opts.method || 'GET', headers: {} };
            var body = opts.body;
            if (body) { body = Object.assign({ module: moduleKey }, body); init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
            if (opts.token) { init.headers['X-RB-Token'] = BOOT.token; }
            return fetch(url, init).then(function (r) { return r.json().then(function (j) {
                if (!r.ok || j.error) { throw new Error(j.error || ('Request failed (' + r.status + ')')); }
                return j;
            }); });
        }

        // ── rule builder ──────────────────────────────────────────────────
        function renderFields() {
            var host = el('tcFields');
            host.innerHTML = '';
            meta.fields.forEach(function (f) {
                var rule = (config.fields && config.fields[f.key]) || { formula: '', enabled: false, auto: false };
                var wrap = document.createElement('div');
                wrap.className = 'tc-field' + (rule.enabled ? '' : ' tc-off');
                wrap.innerHTML =
                    '<div class="tc-field-head">' +
                        '<span><b>' + esc(f.label) + '</b><span class="tc-fmt">' + esc(f.format) + '</span></span>' +
                        '<div class="tc-toggles">' +
                            '<label><input type="checkbox" class="tc-en" ' + (rule.enabled ? 'checked' : '') + '> Enabled</label>' +
                            '<label><input type="checkbox" class="tc-au" ' + (rule.auto ? 'checked' : '') + '> Auto-fill</label>' +
                        '</div>' +
                    '</div>' +
                    '<div class="tc-formula-row">' +
                        '<textarea class="tc-formula" placeholder="e.g. {YearFixedAmount}">' + esc(rule.formula) + '</textarea>' +
                        '<button type="button" class="tc-ins" title="Insert field / function">ƒ</button>' +
                    '</div>' +
                    '<div class="tc-result"></div>';
                var ta = wrap.querySelector('.tc-formula');
                var en = wrap.querySelector('.tc-en');
                var au = wrap.querySelector('.tc-au');
                ta.addEventListener('focus', function () { lastFocused = ta; });
                ta.addEventListener('input', function () { rule.formula = ta.value; config.fields[f.key] = rule; });
                en.addEventListener('change', function () { rule.enabled = en.checked; config.fields[f.key] = rule; wrap.classList.toggle('tc-off', !en.checked); });
                au.addEventListener('change', function () { rule.auto = au.checked; config.fields[f.key] = rule; });
                wrap.querySelector('.tc-ins').addEventListener('click', function () { lastFocused = ta; el('tcRef').open = true; el('tcRef').scrollIntoView({ behavior: 'smooth' }); });
                config.fields[f.key] = rule;
                host.appendChild(wrap);
            });
        }

        function renderRef() {
            var body = el('tcRefBody');
            var html = '';
            // variables grouped
            var groups = {};
            meta.variables.forEach(function (v) { (groups[v.group] = groups[v.group] || []).push(v); });
            Object.keys(groups).forEach(function (g) {
                html += '<div class="tc-ref-group"><h4>' + esc(g) + '</h4>';
                groups[g].forEach(function (v) {
                    html += '<span class="tc-ref-item" data-ins="{' + esc(v.key) + '}" title="' + esc(v.label) + '">{' + esc(v.key) + '}</span>';
                });
                html += '</div>';
            });
            // functions grouped
            var fg = {};
            meta.functions.forEach(function (fn) { (fg[fn.category] = fg[fn.category] || []).push(fn); });
            Object.keys(fg).forEach(function (cat) {
                html += '<div class="tc-ref-group"><h4>' + esc(cat) + '</h4>';
                fg[cat].forEach(function (fn) {
                    html += '<span class="tc-ref-item" data-ins="' + esc(fn.name) + '(" title="' + esc(fn.syntax + ' — ' + fn.description) + '">' + esc(fn.name) + '</span>';
                });
                html += '</div>';
            });
            body.innerHTML = html;
            body.querySelectorAll('.tc-ref-item').forEach(function (item) {
                item.addEventListener('click', function () { insertAtCursor(item.getAttribute('data-ins')); });
            });
        }

        function insertAtCursor(text) {
            var ta = lastFocused;
            if (!ta) { return; }
            var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
            ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
            ta.focus();
            var pos = s + text.length;
            ta.setSelectionRange(pos, pos);
            ta.dispatchEvent(new Event('input'));
        }

        // ── calculator (inputs built from the module's definition) ─────────
        function opt(value, label, selected) {
            return '<option value="' + esc(value) + '"' + (selected ? ' selected' : '') + '>' + esc(label) + '</option>';
        }

        function buildCalcInputs() {
            var host = el('calcInputs');
            host.innerHTML = '';
            calcEls = {};
            var o = meta.options || {};
            (meta.inputs || []).forEach(function (inp) {
                var cell = document.createElement('div');
                cell.className = 'tc-form-row';
                var control = '';
                var t = inp.type;
                if (t === 'member') {
                    control = '<select class="tc-input">' + opt('0', '— choose a member —', false) +
                        (o.members || []).map(function (m) { return opt(m.MemberID, m.MemberName); }).join('') + '</select>';
                } else if (t === 'year') {
                    control = '<select class="tc-input">' + (o.years || []).map(function (y) { return opt(y.YearID, y.YearName, y.YearID == o.active_year); }).join('') + '</select>';
                } else if (t === 'month') {
                    control = '<select class="tc-input">' + (o.months || []).map(function (m) { return opt(m, m); }).join('') + '</select>';
                } else if (t === 'payment_mode') {
                    control = '<select class="tc-input">' + (o.payment_modes || []).map(function (m) { return opt(m, m); }).join('') + '</select>';
                } else if (t === 'expense_category') {
                    control = '<select class="tc-input">' + opt('', '— any category —', false) + (o.expense_categories || []).map(function (c) { return opt(c, c); }).join('') + '</select>';
                } else if (t === 'event_category') {
                    control = '<select class="tc-input">' + opt('', '— any category —', false) + (o.event_categories || []).map(function (c) { return opt(c, c); }).join('') + '</select>';
                } else if (t === 'event') {
                    control = '<select class="tc-input">' + opt('0', '— choose an event —', false) + (o.events || []).map(function (ev) { return opt(ev.EventID, ev.EventName); }).join('') + '</select>';
                } else if (t === 'txn_type') {
                    control = '<select class="tc-input">' + (o.txn_types || []).map(function (x) { return opt(x, x); }).join('') + '</select>';
                } else if (t === 'date') {
                    control = '<input type="date" class="tc-input" value="' + esc(o.today || '') + '">';
                } else { // number
                    control = '<input type="number" step="0.01" class="tc-input" value="0">';
                }
                cell.innerHTML = '<label>' + esc(inp.label) + '</label>' + control;
                host.appendChild(cell);
                var ctrl = cell.querySelector('input,select');
                if (t === 'month') { try { ctrl.selectedIndex = (new Date()).getMonth(); } catch (e) {} }
                calcEls[inp.key] = ctrl;
                var evName = (ctrl.tagName === 'SELECT' || ctrl.type === 'date') ? 'change' : 'input';
                ctrl.addEventListener(evName, runCalc);
            });
        }

        function runCalc() {
            var inputs = {};
            Object.keys(calcEls).forEach(function (k) { inputs[k] = calcEls[k].value; });
            el('calcOut').innerHTML = '<p class="tc-muted">Calculating…</p>';
            api('compute', { body: { inputs: inputs, config: config } }).then(function (res) {
                var out = res.fields || {};
                var html = '';
                meta.fields.forEach(function (f) {
                    var r = out[f.key];
                    if (!r) { return; }
                    if (r.error) {
                        html += '<div class="tc-out-row"><span class="lab">' + esc(f.label) + '</span><span class="val err" style="color:#b4232a">⚠ ' + esc(r.error) + '</span></div>';
                    } else {
                        html += '<div class="tc-out-row"><span class="lab">' + esc(f.label) + '</span><span class="val' + (r.auto ? ' auto' : '') + '">' + esc(r.formatted) + '</span></div>';
                    }
                });
                el('calcOut').innerHTML = html || '<p class="tc-muted">No enabled field rules yet.</p>';
            }).catch(function (e) { el('calcOut').innerHTML = '<p class="err" style="color:#b4232a">' + esc(e.message) + '</p>'; });
        }

        // ── rule-set bar / save / open ────────────────────────────────────
        function refreshBar() {
            el('tcName').textContent = current.name;
            el('tcActiveChip').style.display = current.active ? '' : 'none';
            el('tcSetActiveBtn').style.display = (current.id && !current.active) ? '' : 'none';
        }

        function openModal(id) { el(id).classList.add('show'); }
        function closeModal(id) { el(id).classList.remove('show'); }
        document.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { b.closest('.tc-modal-back').classList.remove('show'); }); });
        document.querySelectorAll('.tc-modal-back').forEach(function (m) { m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('show'); }); });

        function renderRulesets() {
            var list = el('tcRsList');
            var rs = meta.rulesets || [];
            if (!rs.length) { list.innerHTML = '<p class="tc-muted">No saved rule-sets yet. The default rules are in use.</p>'; return; }
            list.innerHTML = rs.map(function (r) {
                return '<div class="tc-rs-item"><div class="meta"><b>' + esc(r.Name) + '</b>' +
                    (Number(r.IsActive) ? ' <span class="tc-chip">Active</span>' : '') +
                    '<small>' + esc(r.Description || '') + '</small></div>' +
                    '<div style="display:flex; gap:6px;">' +
                        '<button type="button" class="btn tc-btn-ghost" data-load="' + r.RulesetID + '" style="padding:4px 10px;">Open</button>' +
                        '<button type="button" class="btn tc-btn-ghost" data-del="' + r.RulesetID + '" style="padding:4px 10px; color:#b4232a;">Delete</button>' +
                    '</div></div>';
            }).join('');
            list.querySelectorAll('[data-load]').forEach(function (b) { b.addEventListener('click', function () { loadRuleset(b.getAttribute('data-load')); }); });
            list.querySelectorAll('[data-del]').forEach(function (b) { b.addEventListener('click', function () { delRuleset(b.getAttribute('data-del')); }); });
        }

        function loadRuleset(id) {
            fetch(API + '?action=ruleset&id=' + encodeURIComponent(id)).then(function (r) { return r.json(); }).then(function (j) {
                if (j.error) { alert(j.error); return; }
                var rs = j.ruleset;
                config = normalize(rs.Config);
                current = { id: Number(rs.RulesetID), name: rs.Name, active: !!Number(rs.IsActive) };
                renderFields(); refreshBar(); runCalc();
                closeModal('tcOpenModal');
            });
        }

        function delRuleset(id) {
            if (!confirm('Delete this rule-set?')) { return; }
            api('delete', { token: true, body: { id: Number(id) } }).then(function (j) {
                meta.rulesets = j.rulesets; renderRulesets();
            }).catch(function (e) { alert(e.message); });
        }

        function normalize(cfg) {
            var base = JSON.parse(JSON.stringify(meta.default_config));
            if (cfg && cfg.fields) {
                meta.fields.forEach(function (f) {
                    if (cfg.fields[f.key]) { base.fields[f.key] = cfg.fields[f.key]; }
                });
            }
            return base;
        }

        function doSave(asNew) {
            el('saveName').value = asNew ? '' : current.name;
            el('saveDesc').value = '';
            el('saveAsNew').checked = asNew || !current.id;
            el('saveActive').checked = current.active || asNew || !current.id;
            openModal('tcSaveModal');
        }

        el('saveConfirm').addEventListener('click', function () {
            var payload = {
                id: el('saveAsNew').checked ? 0 : current.id,
                name: el('saveName').value.trim() || 'Untitled rules',
                description: el('saveDesc').value.trim(),
                config: config,
                active: el('saveActive').checked
            };
            api('save', { token: true, body: payload }).then(function (j) {
                current = { id: j.id, name: payload.name, active: payload.active };
                meta.rulesets = j.rulesets;
                refreshBar(); closeModal('tcSaveModal');
            }).catch(function (e) { alert(e.message); });
        });

        el('tcSaveBtn').addEventListener('click', function () { doSave(false); });
        el('tcSaveAsBtn').addEventListener('click', function () { doSave(true); });
        el('tcOpenBtn').addEventListener('click', function () { renderRulesets(); openModal('tcOpenModal'); });
        el('tcSetActiveBtn').addEventListener('click', function () {
            api('set_active', { token: true, body: { id: current.id } }).then(function (j) {
                current.active = true; meta.rulesets = j.rulesets; refreshBar();
            }).catch(function (e) { alert(e.message); });
        });
        el('calcRun').addEventListener('click', runCalc);

        function buildModuleSelect() {
            var sel = el('tcModule');
            sel.innerHTML = (meta.modules || []).map(function (m) { return opt(m.key, m.label, m.key === moduleKey); }).join('');
            if (!moduleWired) {
                sel.addEventListener('change', function () { loadModule(sel.value); });
                moduleWired = true;
            }
        }

        // ── load a module ─────────────────────────────────────────────────
        function loadModule(module) {
            moduleKey = module;
            el('tcFields').innerHTML = '<p class="tc-muted">Loading…</p>';
            el('calcOut').innerHTML = '<p class="tc-muted">Computed values appear here.</p>';
            api('meta').then(function (m) {
                meta = m; moduleKey = m.module;
                config = normalize(m.active_config);
                current = { id: 0, name: 'Default rules', active: true };
                var activeRs = (m.rulesets || []).filter(function (r) { return Number(r.IsActive); })[0];
                if (activeRs) { current = { id: Number(activeRs.RulesetID), name: activeRs.Name, active: true }; }
                buildModuleSelect();
                buildCalcInputs();
                renderFields(); renderRef(); refreshBar(); runCalc();
            }).catch(function (e) {
                el('tcFields').innerHTML = '<p class="err" style="color:#b4232a">' + esc(e.message) + '</p>';
            });
        }

        // ── init ──────────────────────────────────────────────────────────
        loadModule('transactions');
    })();
    </script>
</body>
</html>
