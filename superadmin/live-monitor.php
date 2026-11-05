<?php
// Super Admin - Live Monitor: the application's live flow (every request, web and desktop app) and its errors
define('AM_SKIP', true);
require_once 'session.php';
require_once '../config/config.php';

if (empty($_SESSION['rb_token'])) {
    $_SESSION['rb_token'] = bin2hex(random_bytes(16));
}
$page_title = 'Live Monitor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Monitor - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <script src="theme-manager.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .lm-page { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .lm-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
        .lm-head h1 { font-size: 26px; color: var(--text-primary); display: flex; align-items: center; gap: 10px; margin: 0 0 4px; }
        .lm-head p { color: var(--text-secondary); margin: 0; }
        .lm-live { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #059669; }
        .lm-live .dot { width: 9px; height: 9px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 0 rgba(16,185,129,.6); animation: lmPulse 1.6s infinite; }
        .lm-live.paused { color: var(--text-secondary); } .lm-live.paused .dot { background: #94a3b8; animation: none; }
        @keyframes lmPulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.6); } 70% { box-shadow: 0 0 0 8px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
        .lm-stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 16px; }
        .lm-stat { background: var(--bg-secondary); border-radius: 12px; padding: 12px 14px; box-shadow: var(--shadow); border-left: 4px solid var(--gradient-1); }
        .lm-stat .l { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .4px; }
        .lm-stat .v { font-size: 22px; font-weight: 700; color: var(--text-primary); margin-top: 2px; }
        .lm-stat .s { font-size: 11px; color: var(--text-secondary); }
        .lm-stat.bad { border-left-color: #ef4444; } .lm-stat.warn { border-left-color: #f59e0b; } .lm-stat.ok { border-left-color: #10b981; } .lm-stat.desk { border-left-color: #8b5cf6; }
        .lm-tabs { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
        .lm-tab { padding: 9px 16px; border-radius: 10px; background: var(--bg-secondary); color: var(--text-primary); font-weight: 600; box-shadow: var(--shadow); cursor: pointer; border: none; font: inherit; font-weight: 600; }
        .lm-tab.active { background: var(--gradient-primary); color: #fff; }
        .lm-tab .n { background: #ef4444; color: #fff; border-radius: 10px; padding: 0 7px; font-size: 11px; margin-left: 4px; }
        .lm-panel { display: none; } .lm-panel.active { display: block; }
        .lm-card { background: var(--bg-secondary); border-radius: 12px; box-shadow: var(--shadow); padding: 16px 18px; margin-bottom: 16px; }
        .lm-card h3 { margin: 0 0 12px; font-size: 15px; color: var(--text-primary); display: flex; gap: 8px; align-items: center; }
        .lm-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; margin-bottom: 12px; }
        .lm-filters label { display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 3px; }
        .lm-filters select, .lm-filters input { padding: 7px 9px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-tertiary); color: var(--text-primary); font: inherit; font-size: 13px; }
        .lm-btn { padding: 7px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-tertiary); color: var(--text-primary); cursor: pointer; font: inherit; font-size: 13px; font-weight: 600; }
        .lm-btn.primary { background: var(--gradient-primary); color: #fff; border: none; } .lm-btn.danger { background: #ef4444; color: #fff; border: none; }
        .lm-table-wrap { overflow: auto; max-height: 640px; }
        .lm-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .lm-table th { position: sticky; top: 0; text-align: left; padding: 8px; background: var(--header-bg); color: var(--text-secondary); font-size: 11px; text-transform: uppercase; white-space: nowrap; z-index: 1; }
        .lm-table td { padding: 7px 8px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); vertical-align: top; }
        .lm-table tr { cursor: pointer; } .lm-table tr:hover td { background: var(--hover-bg); }
        .lm-table tr.new td { animation: lmNew 2.5s ease-out; }
        @keyframes lmNew { from { background: rgba(102,126,234,.22); } to { background: transparent; } }
        .lm-table .muted { color: var(--text-secondary); font-size: 11.5px; }
        .lm-table .msg { max-width: 520px; word-break: break-word; }
        .lv { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .lv.info { background: rgba(16,185,129,.12); color: #059669; } .lv.notice { background: rgba(59,130,246,.12); color: #2563eb; }
        .lv.warning { background: rgba(245,158,11,.15); color: #b45309; } .lv.error, .lv.fatal { background: rgba(239,68,68,.14); color: #dc2626; }
        .src { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 11px; font-weight: 600; background: rgba(102,126,234,.12); color: var(--gradient-1); }
        .src.desktop { background: rgba(139,92,246,.14); color: #7c3aed; }
        .lm-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .lm-bars { display: flex; align-items: flex-end; gap: 3px; height: 90px; padding-top: 6px; }
        .lm-bars .b { flex: 1; background: var(--gradient-1); border-radius: 3px 3px 0 0; position: relative; min-height: 1px; opacity: .85; }
        .lm-bars .b .e { position: absolute; bottom: 0; left: 0; right: 0; background: #ef4444; border-radius: 3px 3px 0 0; }
        .lm-modal { position: fixed; inset: 0; background: rgba(15,23,42,.55); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 20px; }
        .lm-modal.open { display: flex; }
        .lm-modal .box { background: var(--bg-secondary); border-radius: 14px; max-width: 980px; width: 100%; max-height: 90vh; overflow: auto; padding: 20px 22px; box-shadow: var(--shadow-lg); }
        .lm-kv { display: grid; grid-template-columns: 140px 1fr; gap: 6px 12px; font-size: 13px; color: var(--text-primary); margin-bottom: 12px; }
        .lm-kv b { color: var(--text-secondary); font-weight: 600; }
        .lm-pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; font: 12px/1.5 Consolas, monospace; white-space: pre-wrap; word-break: break-word; max-height: 340px; overflow: auto; }
        .lm-empty { text-align: center; padding: 30px; color: var(--text-secondary); }
        @media (max-width: 1100px) { .lm-stats { grid-template-columns: repeat(3, 1fr); } .lm-grid2 { grid-template-columns: 1fr; } }
        @media (max-width: 600px) { .lm-stats { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="lm-page">
    <div class="lm-head">
        <div>
            <h1><i class="fas fa-heart-pulse"></i> Live Monitor</h1>
            <p>Every request to the website and the desktop app, and every error, as it happens.</p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="lm-live" id="liveBadge"><span class="dot"></span> <span id="liveText">Live</span></span>
            <button class="lm-btn" id="pauseBtn"><i class="fas fa-pause"></i> Pause</button>
            <a class="lm-btn" href="dbadmin/dbadmin.php" style="text-decoration:none"><i class="fas fa-database"></i> DB Admin</a>
        </div>
    </div>

    <div class="lm-stats" id="stats"></div>

    <div class="lm-tabs">
        <button class="lm-tab active" data-tab="flow"><i class="fas fa-stream"></i> Live Flow</button>
        <button class="lm-tab" data-tab="errors"><i class="fas fa-bug"></i> Errors <span class="n" id="errCount" style="display:none"></span></button>
        <button class="lm-tab" data-tab="activity"><i class="fas fa-users"></i> Activity</button>
        <button class="lm-tab" data-tab="settings"><i class="fas fa-sliders-h"></i> Settings</button>
    </div>

    <!-- Live flow -->
    <div class="lm-panel active" id="panel-flow">
        <div class="lm-card">
            <div class="lm-filters">
                <div><label>Show</label><select id="fKind"><option value="">Everything</option><option value="request">Requests</option><option value="errors">Errors only</option><option value="client_error">Desktop app errors</option></select></div>
                <div><label>Level</label><select id="fLevel"><option value="">All</option><option value="problems">Warnings &amp; errors</option><option value="error">Errors</option><option value="fatal">Fatal</option><option value="warning">Warnings</option><option value="notice">Notices</option></select></div>
                <div><label>From</label><select id="fSource"><option value="">Web &amp; desktop</option><option value="web">Website</option><option value="desktop">Desktop app</option></select></div>
                <div><label>Portal</label><select id="fPortal"><option value="">All</option><option value="superadmin">Super Admin</option><option value="admin">Admin</option><option value="member">Member</option><option value="auth">Sign in</option><option value="api">API</option><option value="public">Public</option></select></div>
                <div><label>Search</label><input id="fQ" placeholder="Page, user, message, IP"></div>
                <button class="lm-btn" id="fReset">Reset</button>
                <span style="flex:1"></span>
                <span class="muted" id="flowInfo" style="color:var(--text-secondary);font-size:12px"></span>
            </div>
            <div class="lm-table-wrap">
                <table class="lm-table">
                    <thead><tr><th>Time</th><th>Level</th><th>From</th><th>Who</th><th>Page / Message</th><th>Status</th><th>Time taken</th></tr></thead>
                    <tbody id="flowBody"><tr><td colspan="7" class="lm-empty">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Errors -->
    <div class="lm-panel" id="panel-errors">
        <div class="lm-card">
            <h3><i class="fas fa-bug"></i> Errors of the last 7 days <span class="muted" style="font-weight:400;font-size:12px;color:var(--text-secondary)">same error grouped · click for details</span></h3>
            <div class="lm-table-wrap">
                <table class="lm-table">
                    <thead><tr><th>Last seen</th><th>Level</th><th>From</th><th>Times</th><th>Message</th><th>Where</th></tr></thead>
                    <tbody id="errBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Activity -->
    <div class="lm-panel" id="panel-activity">
        <div class="lm-card">
            <h3><i class="fas fa-chart-column"></i> Last 30 minutes <span class="muted" style="font-weight:400;font-size:12px;color:var(--text-secondary)">requests per minute · red = errors</span></h3>
            <div class="lm-bars" id="bars"></div>
        </div>
        <div class="lm-grid2">
            <div class="lm-card"><h3><i class="fas fa-user-clock"></i> Active users (last 15 minutes)</h3>
                <table class="lm-table"><thead><tr><th>User</th><th>From</th><th>Requests</th><th>Last page</th><th>Last seen</th></tr></thead><tbody id="usersBody"></tbody></table></div>
            <div class="lm-card"><h3><i class="fas fa-fire"></i> Busiest pages (24 hours)</h3>
                <table class="lm-table"><thead><tr><th>Page</th><th>Hits</th><th>Avg</th><th>Max</th><th>Problems</th></tr></thead><tbody id="pagesBody"></tbody></table></div>
        </div>
        <div class="lm-card"><h3><i class="fas fa-hourglass-half"></i> Slowest pages (24 hours, average)</h3>
            <table class="lm-table"><thead><tr><th>Page</th><th>Average</th><th>Slowest</th><th>Hits</th></tr></thead><tbody id="slowBody"></tbody></table></div>
    </div>

    <!-- Settings -->
    <div class="lm-panel" id="panel-settings">
        <div class="lm-card">
            <h3><i class="fas fa-sliders-h"></i> Monitor settings</h3>
            <div class="lm-filters" style="align-items:center">
                <label style="display:flex;gap:6px;align-items:center;font-size:14px;color:var(--text-primary)"><input type="checkbox" id="sEnabled"> Monitor enabled</label>
                <label style="display:flex;gap:6px;align-items:center;font-size:14px;color:var(--text-primary)"><input type="checkbox" id="sRequests"> Record every request (not only errors)</label>
                <div><label>Keep entries for (days)</label><input type="number" id="sDays" min="1" max="365" style="width:90px"></div>
                <button class="lm-btn primary" id="saveSettings"><i class="fas fa-save"></i> Save</button>
            </div>
            <p style="color:var(--text-secondary);font-size:13px;margin:6px 0 0">Passwords, tokens and codes are never recorded – only the names of submitted fields. Old entries are removed automatically.</p>
        </div>
        <div class="lm-card">
            <h3><i class="fas fa-broom"></i> Clear the log</h3>
            <div class="lm-filters">
                <button class="lm-btn" data-clear="older"><i class="fas fa-calendar-minus"></i> Older than 1 day</button>
                <button class="lm-btn" data-clear="requests"><i class="fas fa-stream"></i> All requests</button>
                <button class="lm-btn" data-clear="errors"><i class="fas fa-bug"></i> All errors</button>
                <button class="lm-btn danger" data-clear="all"><i class="fas fa-trash"></i> Everything</button>
            </div>
            <p id="logInfo" style="color:var(--text-secondary);font-size:13px;margin:0"></p>
        </div>
    </div>
</div>

<div class="lm-modal" id="modal"><div class="box" id="modalBox"></div></div>

<script>
(function () {
    const API = 'api/monitor.php';
    const TOKEN = <?php echo json_encode($_SESSION['rb_token']); ?>;
    let lastId = 0, paused = false, timer = null, statsTimer = null, rows = [];
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const time = t => t ? t.slice(11, 19) : '';
    const day = t => t ? t.slice(8, 10) + '-' + t.slice(5, 7) + ' ' + t.slice(11, 16) : '';
    const ms = v => v == null ? '' : (v >= 1000 ? (v / 1000).toFixed(1) + ' s' : v + ' ms');
    const who = r => r.UserName ? esc(r.UserName) + '<div class="muted">' + esc(r.UserType || '') + '</div>' : '<span class="muted">guest</span>';
    const portalName = {superadmin: 'Super Admin', admin: 'Admin', member: 'Member', auth: 'Sign in', api: 'API', public: 'Public', desktop: 'Desktop', cron: 'Cron'};

    async function api(action, params = {}, body = null) {
        const url = API + '?' + new URLSearchParams(Object.assign({action}, params));
        const opt = body ? {method: 'POST', headers: {'X-RB-Token': TOKEN}, body: new URLSearchParams(Object.assign({rb_token: TOKEN}, body))} : {};
        const r = await fetch(url, opt);
        if (r.status === 401) { location.href = 'login.php'; throw new Error('signed out'); }
        return r.json();
    }

    function filters() {
        return {kind: $('fKind').value, level: $('fLevel').value, source: $('fSource').value, portal: $('fPortal').value, q: $('fQ').value.trim()};
    }

    function rowHtml(r, isNew) {
        const err = r.Kind !== 'request';
        const main = err
            ? '<div class="msg"><b>' + esc(r.Message) + '</b></div><div class="muted">' + esc(r.Page || '') + (r.File ? ' · ' + esc(r.File.split('/').slice(-2).join('/')) + ':' + esc(r.Line) : '') + '</div>'
            : '<div>' + esc(r.Method) + ' <b>' + esc(r.Page) + '</b></div><div class="muted">' + esc(portalName[r.Portal] || r.Portal || '') + (r.Query ? ' · ' + esc(r.Query.slice(0, 120)) : '') + '</div>';
        return '<tr data-id="' + r.ID + '" class="' + (isNew ? 'new' : '') + '"><td><b>' + time(r.LoggedAt) + '</b><div class="muted">' + esc(r.LoggedAt.slice(0, 10)) + '</div></td>'
            + '<td><span class="lv ' + esc(r.Level) + '">' + esc(err ? (r.Kind === 'client_error' ? 'app ' : '') + r.Level : r.Level) + '</span></td>'
            + '<td><span class="src ' + esc(r.Source) + '">' + (r.Source === 'desktop' ? '<i class="fas fa-desktop"></i> Desktop' : '<i class="fas fa-globe"></i> Web') + '</span></td>'
            + '<td>' + who(r) + '</td><td>' + main + '</td><td>' + (r.Status ? esc(r.Status) : '') + '</td><td>' + ms(r.DurationMs) + '</td></tr>';
    }

    function render(fresh) {
        const body = $('flowBody');
        if (!rows.length) { body.innerHTML = '<tr><td colspan="7" class="lm-empty">Nothing recorded yet for these filters.</td></tr>'; return; }
        body.innerHTML = rows.map(r => rowHtml(r, fresh.has(r.ID))).join('');
    }

    async function loadFlow(reset) {
        try {
            const f = filters();
            if (reset) { lastId = 0; rows = []; }
            const d = await api('feed', Object.assign(f, lastId ? {since_id: lastId} : {}, {limit: 200}));
            if (!d.success) throw new Error(d.error);
            const fresh = new Set();
            if (lastId) d.rows.forEach(r => fresh.add(r.ID));
            rows = (lastId ? d.rows.concat(rows) : d.rows).slice(0, 400);
            if (d.rows.length || reset) render(fresh);
            if (rows.length) lastId = Math.max(lastId, ...rows.map(r => +r.ID));
            else lastId = d.last_id;
            $('flowInfo').textContent = 'Updated ' + d.server_time.slice(11) + ' · ' + rows.length + ' shown' + (d.settings.monitor_enabled !== '1' ? ' · MONITOR IS OFF (Settings)' : '');
        } catch (e) { $('flowInfo').textContent = 'Could not refresh: ' + e.message; }
    }

    async function loadStats() {
        try {
            const d = await api('stats');
            if (!d.success) return;
            const t = d.tiles;
            $('stats').innerHTML = [
                ['Requests / minute', t.req_1m, (t.req_1h || 0) + ' in the last hour', ''],
                ['Requests today', t.req_24h, (t.desktop_req_24h || 0) + ' from the desktop app', 'ok'],
                ['Errors (1 hour)', t.err_1h, (t.err_24h || 0) + ' in 24 hours', +t.err_1h ? 'bad' : 'ok'],
                ['Serious errors (24h)', t.fatal_24h, 'error and fatal', +t.fatal_24h ? 'bad' : 'ok'],
                ['Desktop app errors', t.desktop_err_24h, 'last 24 hours', +t.desktop_err_24h ? 'warn' : 'desk'],
                ['Average speed', ms(t.avg_ms_1h || 0), 'per request, last hour', +t.avg_ms_1h > 1500 ? 'warn' : ''],
            ].map(([l, v, s, c]) => '<div class="lm-stat ' + c + '"><div class="l">' + l + '</div><div class="v">' + esc(v ?? 0) + '</div><div class="s">' + esc(s) + '</div></div>').join('');
            const n = +t.err_24h || 0;
            $('errCount').style.display = n ? '' : 'none';
            $('errCount').textContent = n;
            $('errBody').innerHTML = d.error_groups.length ? d.error_groups.map(g => '<tr data-id="' + g.LastID + '"><td><b>' + day(g.LastSeen) + '</b><div class="muted">first ' + day(g.FirstSeen) + '</div></td>'
                + '<td><span class="lv ' + esc(g.Level) + '">' + esc(g.Level) + '</span></td><td><span class="src ' + esc(g.Source) + '">' + (g.Source === 'desktop' ? 'Desktop' : 'Web') + '</span></td>'
                + '<td><b>' + g.Times + '&times;</b></td><td class="msg">' + esc(g.Message) + '</td><td class="muted">' + esc(g.LastPage || '') + (g.File ? '<br>' + esc(g.File.split('/').slice(-2).join('/')) + ':' + esc(g.Line) : '') + '</td></tr>').join('')
                : '<tr><td colspan="6" class="lm-empty"><i class="fas fa-check-circle" style="color:#10b981"></i> No errors in the last 7 days.</td></tr>';
            $('usersBody').innerHTML = d.active_users.length ? d.active_users.map(u => '<tr><td><b>' + esc(u.UserName) + '</b><div class="muted">' + esc(u.UserType) + '</div></td><td><span class="src ' + esc(u.Source) + '">' + (u.Source === 'desktop' ? 'Desktop' : 'Web') + '</span></td><td>' + u.Requests + '</td><td>' + esc(u.LastPage) + '</td><td>' + time(u.LastSeen) + '</td></tr>').join('')
                : '<tr><td colspan="5" class="lm-empty">Nobody signed in has been active in the last 15 minutes.</td></tr>';
            $('pagesBody').innerHTML = d.top_pages.map(p => '<tr><td><b>' + esc(p.Page) + '</b><div class="muted">' + esc(portalName[p.Portal] || p.Portal) + '</div></td><td>' + p.Hits + '</td><td>' + ms(+p.AvgMs) + '</td><td>' + ms(+p.MaxMs) + '</td><td>' + (+p.Problems ? '<span class="lv warning">' + p.Problems + '</span>' : '-') + '</td></tr>').join('') || '<tr><td colspan="5" class="lm-empty">No requests yet.</td></tr>';
            $('slowBody').innerHTML = d.slow_pages.map(p => '<tr><td><b>' + esc(p.Page) + '</b><div class="muted">' + esc(portalName[p.Portal] || p.Portal) + '</div></td><td>' + ms(+p.AvgMs) + '</td><td>' + ms(+p.MaxMs) + '</td><td>' + p.Hits + '</td></tr>').join('') || '<tr><td colspan="4" class="lm-empty">No requests yet.</td></tr>';
            const pm = d.per_minute, max = Math.max(1, ...pm.map(m => +m.Requests + +m.Errors));
            $('bars').innerHTML = pm.length ? pm.map(m => '<div class="b" title="' + m.Minute + ': ' + m.Requests + ' requests, ' + m.Errors + ' errors" style="height:' + ((+m.Requests + +m.Errors) / max * 100) + '%"><div class="e" style="height:' + (+m.Errors / Math.max(1, +m.Requests + +m.Errors) * 100) + '%"></div></div>').join('') : '<div class="lm-empty" style="width:100%">No activity in the last 30 minutes.</div>';
            const s = d.settings;
            if (document.activeElement.closest && !document.activeElement.closest('#panel-settings')) {
                $('sEnabled').checked = s.monitor_enabled === '1'; $('sRequests').checked = s.monitor_log_requests === '1'; $('sDays').value = s.monitor_retention_days;
            }
            $('logInfo').textContent = (t.total_rows || 0) + ' entries in the log' + (t.oldest ? ', oldest from ' + day(t.oldest) : '') + '.';
        } catch (e) { /* next round */ }
    }

    async function showEntry(id) {
        const d = await api('entry', {id});
        if (!d.success) { alert(d.error); return; }
        const e = d.entry;
        const kv = [['When', e.LoggedAt], ['Type', e.Kind + ' · ' + e.Level], ['From', (e.Source === 'desktop' ? 'Desktop app' : 'Website') + ' · ' + (portalName[e.Portal] || e.Portal || '')],
            ['Page', (e.Method || '') + ' ' + (e.Page || '')], ['Query / form fields', e.Query || '-'], ['Status', e.Status || '-'], ['Time taken', ms(e.DurationMs) + (e.MemoryKb ? ' · ' + Math.round(e.MemoryKb / 1024) + ' MB memory' : '')],
            ['User', e.UserName ? e.UserName + ' (' + e.UserType + ' #' + e.UserID + ')' : 'guest'], ['IP', e.IP || '-'], ['Browser / app', e.UserAgent || '-']];
        if (e.File) kv.splice(4, 0, ['File', e.File + ':' + e.Line]);
        $('modalBox').innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><h3 style="margin:0;color:var(--text-primary)">'
            + (e.Kind === 'request' ? '<i class="fas fa-stream"></i> Request' : '<i class="fas fa-bug"></i> ' + (e.Kind === 'client_error' ? 'Desktop app error' : 'Website error')) + ' #' + e.ID + '</h3>'
            + '<button class="lm-btn" onclick="document.getElementById(\'modal\').classList.remove(\'open\')">Close</button></div>'
            + (e.Message ? '<div class="lm-pre" style="background:#7f1d1d;margin-bottom:12px">' + esc(e.Message) + '</div>' : '')
            + '<div class="lm-kv">' + kv.map(([k, v]) => '<b>' + esc(k) + '</b><span>' + esc(v) + '</span>').join('') + '</div>'
            + (e.Trace ? '<h4 style="color:var(--text-primary);margin:8px 0">Trace</h4><div class="lm-pre">' + esc(e.Trace) + '</div>' : '')
            + (d.related.length ? '<h4 style="color:var(--text-primary);margin:14px 0 8px">Also in this request</h4><table class="lm-table">' + d.related.map(r => '<tr data-id="' + r.ID + '"><td><span class="lv ' + esc(r.Level) + '">' + esc(r.Kind === 'request' ? 'request' : r.Level) + '</span></td><td class="msg">' + esc(r.Message || 'the request itself') + '</td><td class="muted">' + esc((r.File || '').split('/').pop()) + (r.Line ? ':' + r.Line : '') + '</td></tr>').join('') + '</table>' : '');
        $('modal').classList.add('open');
    }

    document.addEventListener('click', e => {
        const tr = e.target.closest('tr[data-id]');
        if (tr && !e.target.closest('a, button')) showEntry(tr.dataset.id);
        if (e.target.id === 'modal') $('modal').classList.remove('open');
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') $('modal').classList.remove('open'); });
    document.querySelectorAll('.lm-tab').forEach(t => t.addEventListener('click', () => {
        document.querySelectorAll('.lm-tab').forEach(x => x.classList.toggle('active', x === t));
        document.querySelectorAll('.lm-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + t.dataset.tab));
    }));
    ['fKind', 'fLevel', 'fSource', 'fPortal'].forEach(id => $(id).addEventListener('change', () => loadFlow(true)));
    let qTimer; $('fQ').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(() => loadFlow(true), 400); });
    $('fReset').onclick = () => { ['fKind', 'fLevel', 'fSource', 'fPortal'].forEach(id => $(id).value = ''); $('fQ').value = ''; loadFlow(true); };
    $('pauseBtn').onclick = () => {
        paused = !paused;
        $('pauseBtn').innerHTML = paused ? '<i class="fas fa-play"></i> Resume' : '<i class="fas fa-pause"></i> Pause';
        $('liveBadge').classList.toggle('paused', paused);
        $('liveText').textContent = paused ? 'Paused' : 'Live';
        if (!paused) { loadFlow(false); loadStats(); }
    };
    $('saveSettings').onclick = async () => {
        const d = await api('settings', {}, {monitor_enabled: $('sEnabled').checked ? 1 : 0, monitor_log_requests: $('sRequests').checked ? 1 : 0, monitor_retention_days: $('sDays').value});
        alert(d.message || d.error); loadStats();
    };
    document.querySelectorAll('[data-clear]').forEach(b => b.addEventListener('click', async () => {
        const what = b.dataset.clear;
        const label = {older: 'entries older than 1 day', requests: 'all recorded requests', errors: 'all recorded errors', all: 'the whole log'}[what];
        if (!confirm('Remove ' + label + '?')) return;
        const d = await api('clear', {}, {what, days: 1});
        alert(d.message || d.error); loadFlow(true); loadStats();
    }));

    loadFlow(true); loadStats();
    timer = setInterval(() => { if (!paused && !document.hidden) loadFlow(false); }, 3000);
    statsTimer = setInterval(() => { if (!paused && !document.hidden) loadStats(); }, 15000);
})();
</script>
</body>
</html>
