<?php
/**
 * Custom Report Builder - design reports on the official letterhead.
 * Choose a data source (or type rows by hand), pick and arrange columns, filter, sort, group,
 * total, preview live, save as a reusable template and export to PDF / Excel / CSV.
 */
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/logo-helper.php';
require_once 'fpdf.php';
require_once 'includes/letterhead-pdf.php';
require_once 'includes/report-builder-lib.php';

if (empty($_SESSION['rb_token'])) {
    $_SESSION['rb_token'] = bin2hex(random_bytes(16));
}

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');
$page_title = 'Report Builder';

$boot = [
    'token' => $_SESSION['rb_token'],
    'user' => $_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin',
    'templateId' => (int)($_GET['template'] ?? 0),
    'preset' => preg_replace('/[^a-z_]/', '', (string)($_GET['preset'] ?? '')),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Builder - Super Admin</title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        :root {
            --rb-indigo: #312e81;
            --rb-indigo-soft: #eef0ff;
            --rb-gold: #c49a2c;
            --rb-surface: var(--bg-secondary-solid, #fff);
            --rb-field: var(--bg-tertiary, #f8f9fa);
        }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .rb-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .rb-header h1 { margin: 0; font-size: 26px; color: var(--text-primary); display: flex; align-items: center; gap: 12px; }
        .rb-header h1 i { color: var(--rb-gold); }
        .rb-header p { margin: 4px 0 0; color: var(--text-secondary); font-size: 14px; }
        .rb-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .rb-actions .btn { white-space: nowrap; }
        .rb-btn-ghost { background: var(--rb-surface); color: var(--text-primary); border: 1px solid var(--border-color); }
        .rb-btn-ghost:hover { border-color: var(--primary-color); color: var(--primary-color); }
        .rb-btn-pdf { background: linear-gradient(135deg, #3f3a9e, var(--rb-indigo)); color: #fff; }
        .rb-btn-xls { background: #0f7a4a; color: #fff; }
        .rb-docname { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-top: 6px; }
        .rb-docname b { color: var(--text-primary); }
        .rb-dirty { color: var(--rb-gold); font-weight: 600; display: none; }
        .rb-dirty.show { display: inline; }

        .rb-banner { display: none; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; margin-bottom: 16px;
            background: rgba(196, 154, 44, 0.12); border: 1px solid rgba(196, 154, 44, 0.45); color: var(--text-primary); font-size: 14px; }
        .rb-banner.show { display: flex; }
        .rb-banner .rb-grow { flex: 1; }

        .rb-layout { display: grid; grid-template-columns: 440px minmax(0, 1fr); gap: 20px; align-items: start; }
        .rb-panel { background: var(--rb-surface); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: var(--shadow); position: sticky; top: 16px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; overflow: hidden; }
        .rb-tabs { display: flex; border-bottom: 1px solid var(--border-color); background: var(--rb-field); }
        .rb-tab { flex: 1; padding: 13px 6px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; font-size: 13px; color: var(--text-secondary); display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .rb-tab i { font-size: 15px; }
        .rb-tab:hover { color: var(--text-primary); }
        .rb-tab.active { color: var(--primary-color); border-bottom-color: var(--rb-gold); background: var(--rb-surface); }
        .rb-tab-body { overflow-y: auto; padding: 18px; flex: 1; }
        .rb-pane { display: none; }
        .rb-pane.active { display: block; }
        .rb-section { margin-bottom: 20px; }
        .rb-section > h3 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin: 0 0 10px; display: flex; justify-content: space-between; align-items: center; }
        .rb-section > h3 small { text-transform: none; letter-spacing: 0; font-weight: 500; }
        .rb-hint { font-size: 12px; color: var(--text-muted); margin: 6px 0 0; line-height: 1.5; }
        .rb-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 5px; }
        .rb-field + .rb-field { margin-top: 12px; }
        .rb-row > .rb-field { margin-top: 0; }
        .rb-input, .rb-select, .rb-textarea {
            width: 100%; padding: 9px 11px; border-radius: 9px; border: 1px solid var(--border-color);
            background: var(--rb-surface); color: var(--text-primary); font-size: 14px; font-family: inherit; box-sizing: border-box;
        }
        .rb-input:focus, .rb-select:focus, .rb-textarea:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px var(--focus-shadow, rgba(102, 126, 234, .15)); }
        .rb-textarea { resize: vertical; min-height: 70px; }
        .rb-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .rb-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .rb-chip { padding: 5px 10px; border-radius: 999px; border: 1px solid var(--border-color); background: var(--rb-field); color: var(--text-secondary); font-size: 12px; font-weight: 600; cursor: pointer; }
        .rb-chip:hover, .rb-chip.on { border-color: var(--primary-color); color: var(--primary-color); }
        .rb-chip.on { background: rgba(102, 126, 234, 0.1); }

        .rb-sources { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .rb-source { text-align: left; padding: 11px; border-radius: 11px; border: 1px solid var(--border-color); background: var(--rb-surface); cursor: pointer; color: var(--text-primary); display: flex; gap: 10px; align-items: flex-start; }
        .rb-source i { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; background: var(--rb-field); color: var(--primary-color); flex-shrink: 0; }
        .rb-source b { display: block; font-size: 13px; }
        .rb-source span { display: block; font-size: 11px; color: var(--text-muted); margin-top: 2px; line-height: 1.35; }
        .rb-source:hover { border-color: var(--primary-color); }
        .rb-source.on { border-color: var(--rb-gold); box-shadow: 0 0 0 1px var(--rb-gold); }
        .rb-source.on i { background: var(--rb-indigo); color: var(--rb-gold); }

        .rb-checks { display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .rb-check { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: var(--text-primary); cursor: pointer; }
        .rb-check input { width: 16px; height: 16px; accent-color: var(--primary-color); }
        .rb-switch { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 0; border-bottom: 1px dashed var(--border-color); font-size: 13px; color: var(--text-primary); cursor: pointer; }
        .rb-switch:last-child { border-bottom: none; }
        .rb-switch input { width: 36px; height: 20px; appearance: none; background: var(--border-color); border-radius: 999px; position: relative; cursor: pointer; flex-shrink: 0; transition: background .15s; }
        .rb-switch input::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.3); }
        .rb-switch input:checked { background: var(--primary-color); }
        .rb-switch input:checked::after { transform: translateX(16px); }
        .rb-switch small { display: block; color: var(--text-muted); font-size: 11px; }

        .rb-members { border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; }
        .rb-members-head { display: flex; gap: 6px; padding: 8px; border-bottom: 1px solid var(--border-color); background: var(--rb-field); }
        .rb-members-head .rb-input { padding: 6px 9px; font-size: 13px; }
        .rb-members-list { max-height: 170px; overflow-y: auto; padding: 6px 10px; }
        .rb-members-list .rb-check { display: flex; padding: 4px 0; }
        .rb-mini { padding: 5px 9px; font-size: 12px; border-radius: 7px; border: 1px solid var(--border-color); background: var(--rb-surface); color: var(--text-secondary); cursor: pointer; white-space: nowrap; }
        .rb-mini:hover { border-color: var(--primary-color); color: var(--primary-color); }

        .rb-col { display: flex; gap: 8px; padding: 10px; border: 1px solid var(--border-color); border-radius: 11px; background: var(--rb-surface); margin-bottom: 8px; }
        .rb-col.is-hidden { opacity: .55; }
        .rb-col.sortable-ghost { opacity: .35; border-style: dashed; }
        .rb-handle { cursor: grab; color: var(--text-muted); padding-top: 8px; }
        .rb-col-main { flex: 1; min-width: 0; }
        .rb-col-top { display: flex; align-items: center; gap: 8px; }
        .rb-col-top .rb-input { font-weight: 600; padding: 7px 9px; }
        .rb-col-key { font-size: 11px; color: var(--text-muted); margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rb-col-key .ƒ { color: var(--rb-gold); font-weight: 700; }
        .rb-col-opts { display: grid; grid-template-columns: minmax(0, 1.25fr) auto 52px minmax(0, 1fr); gap: 6px; margin-top: 8px; align-items: center; }
        .rb-col-opts .rb-select, .rb-col-opts .rb-input { padding: 6px 7px; font-size: 12px; }
        .rb-seg { display: inline-flex; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; }
        .rb-seg button { border: none; background: var(--rb-surface); color: var(--text-muted); padding: 6px 8px; cursor: pointer; font-size: 12px; }
        .rb-seg button + button { border-left: 1px solid var(--border-color); }
        .rb-seg button.on { background: var(--primary-color); color: #fff; }
        .rb-col-actions { display: flex; flex-direction: column; gap: 4px; }
        .rb-icon { width: 30px; height: 30px; border-radius: 8px; border: 1px solid transparent; background: none; color: var(--text-muted); cursor: pointer; }
        .rb-icon:hover { background: var(--rb-field); color: var(--text-primary); border-color: var(--border-color); }
        .rb-icon.danger:hover { color: #dc2626; }
        .rb-formula { margin-top: 8px; font-family: Consolas, monospace; font-size: 12px; }
        .rb-formula.bad { border-color: #dc2626; }
        .rb-add { display: flex; gap: 6px; margin-top: 4px; }
        .rb-fchips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
        .rb-fchip { font-family: Consolas, monospace; font-size: 11px; padding: 3px 7px; border-radius: 6px; border: 1px dashed var(--border-color); background: var(--rb-field); color: var(--text-secondary); cursor: pointer; }
        .rb-fchip:hover { border-color: var(--rb-gold); color: var(--text-primary); }

        .rb-grid-wrap { overflow: auto; max-height: 340px; border: 1px solid var(--border-color); border-radius: 10px; }
        .rb-grid { border-collapse: collapse; width: 100%; font-size: 12px; }
        .rb-grid th { position: sticky; top: 0; background: var(--rb-indigo); color: #fff; padding: 6px; font-weight: 600; text-align: left; white-space: nowrap; }
        .rb-grid td { border-bottom: 1px solid var(--border-color); padding: 2px; }
        .rb-grid td input { width: 100%; min-width: 80px; border: 1px solid transparent; background: transparent; color: var(--text-primary); padding: 5px 6px; font-size: 12px; border-radius: 5px; box-sizing: border-box; }
        .rb-grid td input:focus { outline: none; border-color: var(--primary-color); background: var(--rb-surface); }
        .rb-grid td.rb-num { width: 30px; text-align: center; color: var(--text-muted); }
        .rb-sortrule { display: grid; grid-template-columns: 1fr 110px 30px; gap: 6px; margin-bottom: 6px; }

        /* Preview */
        .rb-preview { min-width: 0; }
        .rb-preview-bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
        .rb-preview-bar h2 { margin: 0; font-size: 15px; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .rb-status { font-size: 13px; color: var(--text-secondary); display: flex; align-items: center; gap: 8px; }
        .rb-spin { display: none; color: var(--primary-color); }
        .rb-spin.show { display: inline-block; }
        /* Preview window: fixed height with its own side and bottom scroll bars */
        .rb-stage {
            background: #e9ebf3; border-radius: 16px; padding: 24px; border: 1px solid var(--border-color);
            height: calc(100vh - 140px); min-height: 460px; overflow: scroll;
            scrollbar-width: auto; scrollbar-color: #8a93b8 #d9dcea;
        }
        [data-theme="dark"] .rb-stage { background: #10131f; scrollbar-color: #4a5470 #1b2033; }
        .rb-stage::-webkit-scrollbar { width: 14px; height: 14px; }
        .rb-stage::-webkit-scrollbar-track { background: #d9dcea; border-radius: 0 16px 16px 0; }
        .rb-stage::-webkit-scrollbar-thumb { background: #8a93b8; border-radius: 10px; border: 3px solid #d9dcea; }
        .rb-stage::-webkit-scrollbar-thumb:hover { background: var(--rb-indigo); }
        .rb-stage::-webkit-scrollbar-corner { background: #d9dcea; }
        [data-theme="dark"] .rb-stage::-webkit-scrollbar-track,
        [data-theme="dark"] .rb-stage::-webkit-scrollbar-corner { background: #1b2033; }
        [data-theme="dark"] .rb-stage::-webkit-scrollbar-thumb { background: #4a5470; border-color: #1b2033; }
        /* The page keeps its real paper width; wide reports scroll sideways instead of squeezing */
        .rb-paper { background: #fff; color: #1e293b; margin: 0 auto; width: 820px; box-shadow: 0 10px 40px rgba(16, 24, 64, .18); position: relative; font-family: Helvetica, Arial, sans-serif; padding: 0 0 18px; }
        .rb-paper.landscape { width: 1160px; }
        .rb-zoom { display: inline-flex; align-items: center; gap: 4px; }
        .rb-zoom button { width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--border-color); background: var(--rb-surface); color: var(--text-secondary); cursor: pointer; }
        .rb-zoom button:hover { border-color: var(--primary-color); color: var(--primary-color); }
        .rb-zoom span { min-width: 44px; text-align: center; font-size: 12px; color: var(--text-secondary); font-variant-numeric: tabular-nums; }
        .rb-paper .lh-band { height: 7px; background: var(--rb-indigo); border-bottom: 2px solid var(--rb-gold); }
        .rb-paper .lh-band.bottom { position: absolute; left: 0; right: 0; bottom: 0; height: 6px; border-bottom: none; border-top: 2px solid var(--rb-gold); }
        .rb-paper .lh { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 18px 28px 10px; }
        .rb-paper .lh img { max-height: 58px; max-width: 170px; object-fit: contain; }
        .rb-paper .lh-org { text-align: right; flex: 1; }
        .rb-paper .lh.nologo .lh-org { text-align: center; }
        .rb-paper .lh-org b { display: block; font-size: 24px; color: var(--rb-indigo); letter-spacing: .01em; }
        .rb-paper .lh-org i { display: block; color: var(--rb-gold); font-size: 11px; margin: 3px 0; }
        .rb-paper .lh-org span { display: block; color: #64748b; font-size: 11px; line-height: 1.5; }
        .rb-paper .lh.pos-right { flex-direction: row-reverse; }
        .rb-paper .lh.pos-top { flex-direction: column; gap: 6px; }
        .rb-paper .lh.pos-top img { max-height: 80px; max-width: 320px; }
        .rb-paper .lh-rule.single { border-top-width: 1.5px; }
        .rb-paper .doc-title.plain { background: none; border-bottom: none; padding: 6px 10px 2px; }
        .rb-paper .doc-title.plain::after { content: ''; display: block; width: 40%; margin: 6px auto 0; border-bottom: 2px solid var(--rb-gold); }

        /* Letterhead editor */
        .rb-lh-layout { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 16px; }
        .rb-lh-list { display: flex; flex-direction: column; gap: 6px; }
        .rb-lh-item { text-align: left; padding: 9px 10px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--rb-surface); color: var(--text-primary); cursor: pointer; font-size: 13px; font-weight: 600; }
        .rb-lh-item small { display: block; font-weight: 500; color: var(--text-muted); font-size: 11px; margin-top: 2px; }
        .rb-lh-item.on { border-color: var(--rb-gold); box-shadow: 0 0 0 1px var(--rb-gold); }
        .rb-lh-item:hover { border-color: var(--primary-color); }
        .rb-lh-form .rb-row { margin-bottom: 10px; }
        .rb-lh-form .rb-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .rb-lh-form .rb-row3 > .rb-field { margin-top: 0; }
        .rb-lh-form h4 { margin: 14px 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
        .rb-lh-form input[type="color"] { width: 46px; height: 34px; padding: 2px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--rb-surface); cursor: pointer; vertical-align: middle; }
        .rb-swatches { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .rb-swatch { width: 26px; height: 26px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px var(--border-color); cursor: pointer; padding: 0; }
        .rb-lh-preview { margin-top: 14px; border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; background: #e9ebf3; padding: 12px; }
        .rb-lh-preview .rb-paper { width: 100% !important; zoom: 1 !important; padding-bottom: 8px; }
        .rb-lh-logo { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .rb-lh-logo img { max-height: 40px; max-width: 120px; border: 1px solid var(--border-color); border-radius: 6px; background: #fff; padding: 2px; }
        @media (max-width: 760px) { .rb-lh-layout { grid-template-columns: 1fr; } .rb-lh-form .rb-row3 { grid-template-columns: 1fr; } }
        .rb-paper .lh-rule { margin: 4px 28px 0; border-top: 3px solid var(--rb-indigo); }
        .rb-paper .lh-rule2 { margin: 2px 28px 0; border-top: 1px solid var(--rb-gold); }
        .rb-paper .doc-title { margin: 14px 28px 0; background: var(--rb-indigo-soft); color: var(--rb-indigo); text-align: center; font-weight: 700; font-size: 16px; padding: 10px; border-bottom: 2px solid var(--rb-gold); text-transform: uppercase; }
        .rb-paper .doc-details { margin: 10px 28px 12px; display: grid; grid-template-columns: auto 1fr auto auto; gap: 3px 12px; font-size: 11.5px; }
        .rb-paper .doc-details b { color: #1e293b; }
        .rb-paper .doc-details .r { text-align: right; }
        .rb-paper .doc-table-wrap { margin: 0 28px; }
        .rb-paper table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .rb-paper th { background: var(--rb-indigo); color: #fff; padding: 6px 5px; font-weight: 700; border: 1px solid var(--rb-indigo); text-align: center; word-wrap: break-word; text-transform: none; letter-spacing: 0; font-size: inherit; }
        .rb-paper td { border: 1px solid #d6dbe8; padding: 5px; word-wrap: break-word; vertical-align: middle; }
        /* The preview is a printed page: it keeps paper colours even when the portal is in dark mode */
        .rb-paper tr, .rb-paper td { background: #fff !important; color: #1e293b !important; }
        .rb-paper th { color: #fff !important; background: var(--rb-indigo) !important; }
        .rb-paper tr.zebra td { background: #f8f9fd !important; }
        .rb-paper tr.grp td { background: #f6f1e1 !important; color: var(--rb-indigo) !important; font-weight: 700; }
        .rb-paper tr.sub td { background: #faf8f0 !important; color: var(--rb-indigo) !important; font-weight: 700; }
        .rb-paper tr.tot td { background: var(--rb-indigo-soft) !important; color: var(--rb-indigo) !important; font-weight: 700; border-color: var(--rb-indigo); padding-top: 8px; padding-bottom: 8px; }
        .rb-paper tr:hover td { filter: none; transform: none; }
        .rb-paper .a-L { text-align: left; } .rb-paper .a-C { text-align: center; } .rb-paper .a-R { text-align: right; }
        .rb-paper .doc-empty { text-align: center; color: #64748b; font-style: italic; padding: 18px; border: 1px solid #d6dbe8; }
        .rb-paper .doc-more { margin: 8px 28px 0; font-size: 11px; color: #64748b; font-style: italic; }
        .rb-paper .tiles { margin: 18px 28px 0; display: flex; gap: 8px; }
        .rb-paper .tile { flex: 1; background: var(--rb-indigo-soft); border-left: 4px solid var(--rb-gold); padding: 9px 12px; min-width: 0; }
        .rb-paper .tile small { display: block; font-size: 9.5px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
        .rb-paper .tile b { display: block; font-size: 15px; color: var(--rb-indigo); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rb-paper .notes { margin: 16px 28px 0; font-size: 11.5px; white-space: pre-wrap; }
        .rb-paper .notes b { display: block; color: var(--rb-indigo); margin-bottom: 3px; }
        .rb-paper .sigs { display: flex; justify-content: space-between; margin: 46px 40px 0; font-size: 11.5px; }
        .rb-paper .sig { width: 210px; text-align: center; border-top: 1px solid #1e293b; padding-top: 5px; }
        .rb-paper .sig small { display: block; color: #64748b; font-size: 10px; }
        .rb-paper .doc-foot { margin: 22px 28px 10px; border-top: 1px solid var(--rb-gold); padding-top: 5px; display: flex; justify-content: space-between; font-size: 9.5px; color: #64748b; font-style: italic; }
        .rb-error { display: none; margin-bottom: 10px; padding: 10px 14px; border-radius: 10px; background: #fdecec; color: #991b1b; font-size: 13px; }
        .rb-error.show { display: block; }

        /* Modals */
        .rb-modal { position: fixed; inset: 0; background: rgba(10, 12, 30, .55); display: none; align-items: flex-start; justify-content: center; padding: 8vh 16px 16px; z-index: 3000; }
        .rb-modal.show { display: flex; }
        .rb-dialog { background: var(--rb-surface); color: var(--text-primary); width: min(760px, 100%); max-height: 84vh; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.35); display: flex; flex-direction: column; overflow: hidden; }
        .rb-dialog.small { width: min(460px, 100%); }
        .rb-dialog-head { padding: 16px 20px; background: linear-gradient(135deg, #3f3a9e, var(--rb-indigo)); color: #fff; border-bottom: 2px solid var(--rb-gold); display: flex; justify-content: space-between; align-items: center; }
        .rb-dialog-head h3 { margin: 0; font-size: 17px; display: flex; gap: 10px; align-items: center; }
        .rb-dialog-head button { background: none; border: none; color: #fff; font-size: 18px; cursor: pointer; }
        .rb-dialog-body { padding: 18px 20px; overflow-y: auto; }
        .rb-dialog-foot { padding: 12px 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: var(--rb-field); }
        .rb-presets { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
        .rb-preset { text-align: left; padding: 14px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--rb-surface); cursor: pointer; color: var(--text-primary); }
        .rb-preset:hover { border-color: var(--rb-gold); box-shadow: 0 4px 14px rgba(0,0,0,.08); }
        .rb-preset i { color: var(--rb-gold); font-size: 18px; }
        .rb-preset b { display: block; margin: 8px 0 3px; font-size: 14px; }
        .rb-preset span { font-size: 12px; color: var(--text-muted); }
        .rb-saved { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 11px; margin-bottom: 8px; }
        .rb-saved:hover { border-color: var(--primary-color); }
        .rb-saved .rb-grow { flex: 1; min-width: 0; cursor: pointer; }
        .rb-saved b { display: block; font-size: 14px; }
        .rb-saved span { font-size: 12px; color: var(--text-muted); }
        .rb-saved .pin.on { color: var(--rb-gold); }
        .rb-empty { text-align: center; padding: 26px; color: var(--text-muted); font-size: 14px; }
        .rb-toast { position: fixed; right: 20px; bottom: 20px; z-index: 4000; background: var(--rb-surface); color: var(--text-primary); border: 1px solid var(--border-color); border-left: 4px solid var(--primary-color); padding: 12px 16px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.2); font-size: 14px; display: none; }
        .rb-toast.show { display: block; }
        .rb-toast.error { border-left-color: #dc2626; }
        .rb-toast.success { border-left-color: #059669; }

        /* Modules */
        .rb-src-tools { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
        .rb-src-tools .rb-input { padding: 7px 10px; font-size: 13px; }
        .rb-src-tools .rb-check { white-space: nowrap; font-size: 12px; color: var(--text-secondary); }
        .rb-module { margin-bottom: 12px; }
        .rb-module-head { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; color: var(--text-primary); margin: 0 0 6px; }
        .rb-module-head i { color: var(--rb-gold); width: 16px; text-align: center; }
        .rb-module-head span { color: var(--text-muted); font-weight: 500; }
        .rb-sources .rb-source { padding: 9px; }
        .rb-source .rb-rows { display: inline-block; margin-left: 4px; padding: 0 6px; border-radius: 999px; background: var(--rb-field); color: var(--text-muted); font-size: 10px; font-weight: 700; vertical-align: 1px; }
        .rb-source.empty { opacity: .6; }
        .rb-source i.rb-star { display: inline; width: auto; height: auto; background: none !important; color: var(--rb-gold) !important; font-size: 10px; margin-left: 4px; vertical-align: 1px; }
        .rb-source b { line-height: 1.3; }

        /* Condition builder */
        .rb-dialog.wide { width: min(940px, 100%); }
        .rb-build-btn { margin-top: 6px; display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; font-size: 12px; font-weight: 600; border-radius: 8px; border: 1px dashed var(--rb-gold); background: rgba(196, 154, 44, 0.08); color: var(--text-primary); cursor: pointer; }
        .rb-build-btn:hover { background: rgba(196, 154, 44, 0.18); }
        .rb-build-btn i { color: var(--rb-gold); }
        .rb-calc-hint { margin-top: 6px; font-size: 12px; color: var(--text-muted); }
        .rb-wz-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 14px; color: var(--text-primary); padding: 12px; border-radius: 11px; background: var(--rb-field); border: 1px solid var(--border-color); margin-bottom: 14px; }
        .rb-wz-head .rb-select, .rb-wz-head .rb-input { width: auto; min-width: 140px; padding: 7px 9px; font-size: 13px; }
        .rb-wz-head b { font-weight: 600; }
        .rb-wz-top { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-bottom: 10px; flex-wrap: wrap; }
        .rb-wz-top .rb-select { width: auto; padding: 5px 8px; font-size: 13px; }
        .rb-wz-group { border: 1px solid var(--border-color); border-left: 4px solid var(--primary-color); border-radius: 11px; padding: 10px 12px; margin-bottom: 8px; background: var(--rb-surface); }
        .rb-wz-group-head { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); margin-bottom: 8px; }
        .rb-wz-group-head .rb-select { width: auto; padding: 4px 7px; font-size: 12px; }
        .rb-wz-group-head .rb-grow { flex: 1; }
        .rb-wz-join { text-align: center; font-size: 11px; font-weight: 800; letter-spacing: .1em; color: var(--rb-gold); margin: 2px 0 8px; }
        .rb-wz-rule { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.1fr) 92px minmax(0, 1.6fr) 30px; gap: 6px; align-items: center; margin-bottom: 6px; }
        .rb-wz-rule .rb-select, .rb-wz-rule .rb-input { padding: 6px 8px; font-size: 12.5px; }
        .rb-wz-rule .rb-pair { display: flex; gap: 4px; }
        .rb-wz-rule-join { font-size: 10.5px; font-weight: 700; color: var(--text-muted); margin: -2px 0 4px 4px; letter-spacing: .06em; }
        .rb-wz-formula { margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: #0f172a; color: #e2e8f0; font-family: Consolas, monospace; font-size: 12.5px; word-break: break-all; min-height: 20px; }
        .rb-wz-formula .lbl { display: block; font-family: inherit; color: #94a3b8; font-size: 11px; margin-bottom: 4px; }
        .rb-wz-formula textarea { display: block; width: 100%; box-sizing: border-box; resize: vertical; min-height: 40px; padding: 6px 8px; border: 1px solid #334155; border-radius: 8px;
            background: #1e293b; color: #e2e8f0; font-family: Consolas, monospace; font-size: 12.5px; font-weight: 600; line-height: 1.45; }
        .rb-wz-formula textarea:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 2px rgba(129, 140, 248, .35); }
        .rb-wz-formula .rb-wz-edited { display: block; margin-top: 5px; color: #fbbf24; font-family: inherit; font-size: 11px; }
        .rb-wz-result { margin-top: 8px; font-size: 13px; color: var(--text-secondary); }
        .rb-wz-result b { color: var(--primary-color); }
        @media (max-width: 760px) {
            .rb-wz-rule { grid-template-columns: 1fr 1fr; }
        }

        /* Column checklist */
        .rb-colpick { border: 1px solid var(--border-color); border-radius: 11px; overflow: hidden; margin-bottom: 14px; }
        .rb-colpick-head { display: flex; gap: 6px; padding: 8px; border-bottom: 1px solid var(--border-color); background: var(--rb-field); flex-wrap: wrap; }
        .rb-colpick-head .rb-input { flex: 1; min-width: 120px; padding: 6px 9px; font-size: 13px; }
        .rb-colpick-list { max-height: 260px; overflow-y: auto; padding: 6px 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px; }
        .rb-colpick-list .rb-check { padding: 4px 0; font-size: 12.5px; min-width: 0; }
        .rb-colpick-list .rb-check span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rb-colpick-list .rb-type { color: var(--text-muted); font-size: 10px; margin-left: auto; padding-left: 4px; }

        /* All application tables */
        .rb-tablepick { margin-top: 10px; padding: 12px; border: 1px dashed var(--border-color); border-radius: 11px; }
        .rb-tablepick.on { border-style: solid; border-color: var(--rb-gold); box-shadow: 0 0 0 1px var(--rb-gold); }
        .rb-tablepick label { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px; }
        .rb-tablepick label i { color: var(--primary-color); }

        /* Conditions */
        .rb-cond { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, 1fr) 30px; gap: 6px; margin-bottom: 6px; align-items: center; }
        .rb-cond .rb-select, .rb-cond .rb-input { padding: 6px 7px; font-size: 12px; }
        .rb-cond .rb-between { display: flex; gap: 4px; }
        .rb-match { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-secondary); margin-bottom: 8px; }
        .rb-match select { width: auto; padding: 4px 6px; font-size: 12px; }

        /* Formulas & maths */
        .rb-issue { display: none; margin-top: 5px; font-size: 11.5px; color: #dc2626; font-weight: 600; }
        .rb-issue.show { display: block; }
        .rb-formula.bad { border-color: #dc2626 !important; }
        .rb-calcline { padding: 10px; border: 1px solid var(--border-color); border-radius: 11px; margin-bottom: 8px; background: var(--rb-surface); }
        .rb-calcline-top { display: grid; grid-template-columns: minmax(0, 1fr) 120px 30px; gap: 6px; align-items: center; }
        .rb-calcline-top .rb-input, .rb-calcline-top .rb-select { padding: 7px 8px; font-size: 13px; }
        .rb-calc-value { margin-top: 6px; font-size: 12px; color: var(--text-secondary); }
        .rb-calc-value b { color: var(--primary-color); }
        .rb-funcs { border: 1px solid var(--border-color); border-radius: 11px; overflow: hidden; }
        .rb-funcs-search { padding: 8px; border-bottom: 1px solid var(--border-color); background: var(--rb-field); }
        .rb-funcs-search .rb-input { padding: 6px 9px; font-size: 13px; }
        .rb-funcs-list { max-height: 300px; overflow-y: auto; }
        .rb-funcs-cat { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); padding: 8px 10px 4px; background: var(--rb-field); }
        .rb-func { display: block; width: 100%; text-align: left; border: none; border-bottom: 1px solid var(--border-color); background: var(--rb-surface); padding: 7px 10px; cursor: pointer; color: var(--text-primary); }
        .rb-func:hover { background: rgba(102, 126, 234, 0.08); }
        .rb-func code { font-family: Consolas, monospace; font-size: 12px; color: var(--primary-color); font-weight: 700; }
        .rb-func span { display: block; font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
        .rb-examples { display: grid; gap: 6px; }
        .rb-example { text-align: left; border: 1px dashed var(--border-color); border-radius: 9px; background: var(--rb-field); padding: 8px 10px; cursor: pointer; color: var(--text-primary); font-size: 12px; }
        .rb-example:hover { border-color: var(--rb-gold); }
        .rb-example code { display: block; font-family: Consolas, monospace; font-size: 11.5px; color: var(--primary-color); margin-top: 3px; word-break: break-all; }
        .rb-paper .calcs { margin: 14px 28px 0; max-width: 520px; border-top: 2px solid var(--rb-gold); }
        .rb-paper .calcs-head { background: var(--rb-indigo-soft); color: var(--rb-indigo); font-weight: 700; font-size: 11.5px; padding: 6px 10px; letter-spacing: .04em; }
        .rb-paper .calc-row { display: flex; justify-content: space-between; gap: 12px; padding: 6px 10px; border-bottom: 1px solid #d6dbe8; font-size: 12px; }
        .rb-paper .calc-row:nth-child(odd) { background: #f8f9fd; }
        .rb-paper .calc-row b { color: var(--rb-indigo); white-space: nowrap; }

        @media (max-width: 1100px) {
            .rb-layout { grid-template-columns: 1fr; }
            .rb-panel { position: static; max-height: none; }
        }
        @media (max-width: 560px) {
            .rb-sources { grid-template-columns: 1fr; }
            .rb-col-opts { grid-template-columns: 1fr 1fr; }
            .rb-stage { padding: 10px; height: 70vh; min-height: 360px; }
        }
        .rb-sectionbar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 8px; border: 1px solid var(--border-color); border-radius: 10px 10px 0 0; border-bottom: none; background: var(--rb-field); }
        .rb-sectionbar .lbl { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; margin-right: 2px; }
        .rb-seg { border: 1px solid var(--border-color); background: var(--rb-surface); color: var(--text-primary); border-radius: 7px; padding: 4px 10px; font-size: 12.5px; cursor: pointer; white-space: nowrap; }
        .rb-seg.on { background: linear-gradient(135deg, #7c3aed, var(--rb-indigo)); color: #fff; border-color: transparent; }
        .rb-seg-add { border-style: dashed; color: var(--rb-indigo); }
        .rb-seg-x { margin-left: 2px; color: #b4232a; border: none; background: none; cursor: pointer; font-size: 12px; }
        .rb-sec-menu { position: relative; display: inline-block; }
        .rb-sec-menu .menu { display: none; position: absolute; z-index: 50; top: 100%; left: 0; min-width: 190px; background: var(--rb-surface); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.18); padding: 4px; margin-top: 2px; }
        .rb-sec-menu.open .menu { display: block; }
        .rb-sec-menu .menu button { display: block; width: 100%; text-align: left; border: none; background: none; color: var(--text-primary); padding: 7px 10px; border-radius: 6px; cursor: pointer; font-size: 13px; }
        .rb-sec-menu .menu button:hover { background: var(--rb-indigo-soft); color: var(--rb-indigo); }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container">

        <div class="rb-header">
            <div>
                <h1><i class="fas fa-file-signature"></i> Custom Report Builder</h1>
                <p>Design your own reports on the official letterhead — choose the data, columns, rows and layout.</p>
                <div class="rb-docname"><i class="fas fa-file-alt"></i> <b id="rbDocName">Untitled report</b> <span class="rb-dirty" id="rbDirty">• Unsaved changes</span></div>
            </div>
            <div class="rb-actions">
                <button type="button" class="btn rb-btn-ghost" id="rbOpenBtn" title="Open a saved report or start from a preset"><i class="fas fa-folder-open"></i> Open</button>
                <button type="button" class="btn rb-btn-ghost" id="rbSaveBtn" title="Save (Ctrl+S)"><i class="fas fa-save"></i> Save</button>
                <button type="button" class="btn rb-btn-ghost" id="rbSaveAsBtn" title="Save a copy"><i class="fas fa-copy"></i> Save as</button>
                <button type="button" class="btn rb-btn-ghost" data-export="csv" title="Download CSV"><i class="fas fa-file-csv"></i> CSV</button>
                <button type="button" class="btn rb-btn-xls" data-export="xlsx" title="Download Excel"><i class="fas fa-file-excel"></i> Excel</button>
                <button type="button" class="btn rb-btn-pdf" data-export="pdf" title="Open the PDF on letterhead"><i class="fas fa-file-pdf"></i> Generate PDF</button>
            </div>
        </div>

        <div class="rb-banner" id="rbDraftBanner">
            <i class="fas fa-history" style="color: var(--rb-gold)"></i>
            <span class="rb-grow">You have unsaved changes from <b id="rbDraftTime"></b>.</span>
            <button type="button" class="rb-mini" id="rbDraftRestore">Restore</button>
            <button type="button" class="rb-mini" id="rbDraftDiscard">Discard</button>
        </div>

        <div class="rb-layout">
            <!-- ── Settings panel ─────────────────────────────────────── -->
            <aside class="rb-panel">
                <div class="rb-sectionbar" id="rbSections"></div>
                <div class="rb-tabs" role="tablist">
                    <button type="button" class="rb-tab active" data-tab="data"><i class="fas fa-database"></i>1. Data</button>
                    <button type="button" class="rb-tab" data-tab="columns"><i class="fas fa-columns"></i>2. Columns</button>
                    <button type="button" class="rb-tab" data-tab="rows"><i class="fas fa-bars"></i>3. Rows</button>
                    <button type="button" class="rb-tab" data-tab="maths"><i class="fas fa-square-root-alt"></i>4. Maths</button>
                    <button type="button" class="rb-tab" data-tab="layout"><i class="fas fa-palette"></i>5. Layout</button>
                </div>
                <div class="rb-tab-body">
                    <!-- Data -->
                    <div class="rb-pane active" data-pane="data">
                        <div class="rb-section">
                            <h3>Report title</h3>
                            <input type="text" class="rb-input" id="rbTitle" maxlength="120" placeholder="e.g. Collection Register">
                        </div>
                        <div class="rb-section">
                            <h3>Data source</h3>
                            <div class="rb-sources" id="rbSources"></div>
                        </div>
                        <div id="rbFilters"></div>
                    </div>

                    <!-- Columns -->
                    <div class="rb-pane" data-pane="columns">
                        <div class="rb-section" id="rbColPickSection">
                            <h3>All columns in this module <small id="rbColPickCount"></small></h3>
                            <div id="rbColPick"></div>
                        </div>
                        <div class="rb-section">
                            <h3>Columns <small>Drag <i class="fas fa-grip-vertical"></i> to reorder</small></h3>
                            <div id="rbColumns"></div>
                            <div id="rbAddColumn"></div>
                        </div>
                        <div class="rb-section" id="rbFormulaHelp"></div>
                    </div>

                    <!-- Rows -->
                    <div class="rb-pane" data-pane="rows">
                        <div class="rb-section" id="rbManualSection"></div>
                        <div class="rb-section">
                            <h3>Sort rows by</h3>
                            <div id="rbSort"></div>
                            <button type="button" class="rb-mini" id="rbAddSort"><i class="fas fa-plus"></i> Add sort level</button>
                        </div>
                        <div class="rb-section">
                            <h3>Group rows</h3>
                            <div class="rb-field">
                                <select class="rb-select" id="rbGroupBy"></select>
                            </div>
                            <label class="rb-switch">Show subtotal after each group <input type="checkbox" data-layout="group_subtotals"></label>
                            <label class="rb-switch" id="rbSummaryOnlyRow"><span>Summary rows only<small>One line per group with its totals, no detail rows</small></span><input type="checkbox" data-layout="summary_only"></label>
                        </div>
                        <div class="rb-section">
                            <h3>Row options</h3>
                            <label class="rb-switch"><span>Serial number column<small>Adds an "S.No" column at the start</small></span><input type="checkbox" data-layout="row_numbers"></label>
                            <label class="rb-switch"><span>Show zero amounts as "-"</span><input type="checkbox" data-layout="zero_dash"></label>
                            <label class="rb-switch"><span>Alternate row shading</span><input type="checkbox" data-layout="zebra"></label>
                        </div>
                    </div>

                    <!-- Maths -->
                    <div class="rb-pane" data-pane="maths">
                        <div class="rb-section">
                            <h3>Calculations under the table</h3>
                            <p class="rb-hint" style="margin:0 0 10px">Each line is worked out over all rows in the report. <b>{Field}</b> on its own means the field's total, so <code>{Inflow} - {Outflow}</code> gives the closing balance.</p>
                            <div id="rbCalcs"></div>
                            <div class="rb-add">
                                <button type="button" class="btn btn-primary btn-sm" id="rbAddCalc"><i class="fas fa-plus"></i> Add calculation</button>
                                <select class="rb-select" id="rbQuickCalc" style="flex:1"></select>
                            </div>
                        </div>
                        <div class="rb-section">
                            <h3>Examples <small>click to add</small></h3>
                            <div class="rb-examples" id="rbExamples"></div>
                        </div>
                        <div class="rb-section">
                            <h3>Fields <small>click to insert into the formula you're editing</small></h3>
                            <div class="rb-fchips" id="rbMathFields"></div>
                        </div>
                        <div class="rb-section">
                            <h3>Fields from other modules <small>use data from any table</small></h3>
                            <select class="rb-select" id="rbMathModule"></select>
                            <div class="rb-fchips" id="rbMathModuleFields"></div>
                            <p class="rb-hint">A field like <code>{expenses.Amount}</code> on its own is that module's total. Use <code>SUMIF({expenses.Amount}, {expenses.Category} = "Events")</code> for part of it, or add <code>BETWEEN({expenses.ExpenseDate}, DATEFROM(), DATETO())</code> to keep to the report's dates.</p>
                        </div>
                        <div class="rb-section">
                            <h3>Functions</h3>
                            <div class="rb-funcs">
                                <div class="rb-funcs-search"><input type="search" class="rb-input" id="rbFuncSearch" placeholder="Find a function… e.g. round, interest, days"></div>
                                <div class="rb-funcs-list" id="rbFuncList"></div>
                            </div>
                            <p class="rb-hint">Operators: <code>+ - * / % ^</code> (power), <code>&amp;</code> joins text, compare with <code>= &lt;&gt; &lt; &lt;= &gt; &gt;=</code>. Text goes in quotes: <code>"Collection"</code>.</p>
                        </div>
                    </div>

                    <!-- Layout -->
                    <div class="rb-pane" data-pane="layout">
                        <div class="rb-section">
                            <h3>Letterhead</h3>
                            <div class="rb-add">
                                <select class="rb-select" id="rbLetterheadSelect" aria-label="Letterhead for this report"></select>
                                <button type="button" class="btn btn-primary btn-sm" id="rbEditLetterheads" style="white-space:nowrap"><i class="fas fa-pen-to-square"></i> Edit letterheads</button>
                            </div>
                            <p class="rb-hint">Change the organisation name, logo, address, contact details and colours. The default letterhead is also used by the Distribution Statement.</p>
                        </div>
                        <div class="rb-section">
                            <h3>Heading</h3>
                            <div class="rb-field"><label for="rbTitle2">Report title (printed under the letterhead)</label><input type="text" class="rb-input" id="rbTitle2" maxlength="120"></div>
                            <div class="rb-field"><label for="rbSubtitle">Report type line</label><input type="text" class="rb-input" id="rbSubtitle" maxlength="120" placeholder="e.g. Member-wise distribution & interest"></div>
                        </div>
                        <div class="rb-section">
                            <h3>Page</h3>
                            <div class="rb-row">
                                <div class="rb-field"><label>Orientation</label>
                                    <div class="rb-seg" id="rbOrientation" style="display:flex">
                                        <button type="button" data-v="P" style="flex:1"><i class="fas fa-file"></i> Portrait</button>
                                        <button type="button" data-v="L" style="flex:1"><i class="fas fa-file fa-rotate-90"></i> Landscape</button>
                                    </div>
                                </div>
                                <div class="rb-field"><label for="rbDateFormat">Date format</label>
                                    <select class="rb-select" id="rbDateFormat">
                                        <option value="d-m-Y">31-12-2026</option>
                                        <option value="d/m/Y">31/12/2026</option>
                                        <option value="d M Y">31 Dec 2026</option>
                                        <option value="Y-m-d">2026-12-31</option>
                                    </select>
                                </div>
                            </div>
                            <div class="rb-field"><label for="rbFontSize">Table text size: <b id="rbFontSizeLabel"></b> pt</label>
                                <input type="range" id="rbFontSize" min="6.5" max="11" step="0.5" style="width:100%">
                            </div>
                        </div>
                        <div class="rb-section">
                            <h3>Below the table</h3>
                            <label class="rb-switch"><span>Summary boxes<small>Entries count and each totalled column</small></span><input type="checkbox" data-layout="summary_tiles"></label>
                            <label class="rb-switch"><span>Signature lines</span><input type="checkbox" data-layout="signatures"></label>
                            <div class="rb-row" id="rbSigLabels" style="margin-top:10px">
                                <div class="rb-field"><label for="rbSigLeft">Left signature</label><input type="text" class="rb-input" id="rbSigLeft" maxlength="40"></div>
                                <div class="rb-field"><label for="rbSigRight">Right signature</label><input type="text" class="rb-input" id="rbSigRight" maxlength="40"></div>
                            </div>
                            <div class="rb-field" style="margin-top:12px"><label for="rbNotes">Notes / terms (printed after the table)</label><textarea class="rb-textarea" id="rbNotes" maxlength="1500" placeholder="e.g. Payments are due on the 5th of every month."></textarea></div>
                            <div class="rb-field"><label for="rbFooterNote">Footer text</label><input type="text" class="rb-input" id="rbFooterNote" maxlength="80"></div>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- ── Live preview ───────────────────────────────────────── -->
            <section class="rb-preview">
                <div class="rb-preview-bar">
                    <h2><i class="fas fa-eye" style="color: var(--rb-gold)"></i> Live preview</h2>
                    <span class="rb-status"><i class="fas fa-circle-notch fa-spin rb-spin" id="rbSpin"></i><span id="rbStatus">Loading…</span>
                        <span class="rb-zoom" aria-label="Zoom">
                            <button type="button" id="rbZoomOut" title="Zoom out"><i class="fas fa-minus"></i></button>
                            <span id="rbZoomLabel">100%</span>
                            <button type="button" id="rbZoomIn" title="Zoom in"><i class="fas fa-plus"></i></button>
                            <button type="button" id="rbZoomFit" title="Fit the page width"><i class="fas fa-expand"></i></button>
                        </span>
                    </span>
                </div>
                <div class="rb-error" id="rbError"></div>
                <div class="rb-stage"><div class="rb-paper" id="rbPaper"></div></div>
            </section>
        </div>
    </div>

    <!-- Open / start modal -->
    <div class="rb-modal" id="rbOpenModal" role="dialog" aria-modal="true" aria-labelledby="rbOpenTitle">
        <div class="rb-dialog">
            <div class="rb-dialog-head"><h3 id="rbOpenTitle"><i class="fas fa-folder-open"></i> Open a report</h3><button type="button" data-close aria-label="Close">&times;</button></div>
            <div class="rb-dialog-body">
                <div class="rb-section">
                    <h3>Start from a ready-made design</h3>
                    <div class="rb-presets" id="rbPresetList"></div>
                </div>
                <div class="rb-section">
                    <h3>My saved reports <small><input type="search" class="rb-input" id="rbSavedSearch" placeholder="Search…" style="width:180px;padding:5px 9px;font-size:12px"></small></h3>
                    <div id="rbSavedList"><div class="rb-empty">Loading…</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Save modal -->
    <div class="rb-modal" id="rbSaveModal" role="dialog" aria-modal="true" aria-labelledby="rbSaveTitle">
        <div class="rb-dialog small">
            <div class="rb-dialog-head"><h3 id="rbSaveTitle"><i class="fas fa-save"></i> Save report design</h3><button type="button" data-close aria-label="Close">&times;</button></div>
            <div class="rb-dialog-body">
                <div class="rb-field"><label for="rbSaveName">Name</label><input type="text" class="rb-input" id="rbSaveName" maxlength="150"></div>
                <div class="rb-field"><label for="rbSaveDesc">Description (optional)</label><input type="text" class="rb-input" id="rbSaveDesc" maxlength="255"></div>
                <p class="rb-hint">Saved designs keep the data source, filters, columns, rows and layout, so you can generate the same report again with one click.</p>
            </div>
            <div class="rb-dialog-foot">
                <button type="button" class="btn rb-btn-ghost" data-close>Cancel</button>
                <button type="button" class="btn btn-primary" id="rbSaveConfirm"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>

    <!-- Paste modal (manual table) -->
    <div class="rb-modal" id="rbPasteModal" role="dialog" aria-modal="true" aria-labelledby="rbPasteTitle">
        <div class="rb-dialog small">
            <div class="rb-dialog-head"><h3 id="rbPasteTitle"><i class="fas fa-paste"></i> Paste rows</h3><button type="button" data-close aria-label="Close">&times;</button></div>
            <div class="rb-dialog-body">
                <p class="rb-hint" style="margin-top:0">Copy cells from Excel or Google Sheets and paste them here. Each line becomes a row; cells map to the columns in order.</p>
                <textarea class="rb-textarea" id="rbPasteText" style="min-height:180px;font-family:Consolas,monospace;font-size:12px" placeholder="1&#9;Hall rent&#9;15000"></textarea>
                <label class="rb-check" style="margin-top:10px"><input type="checkbox" id="rbPasteHeader"> First line contains column names</label>
                <label class="rb-check" style="margin-top:6px"><input type="checkbox" id="rbPasteReplace"> Replace the existing rows</label>
            </div>
            <div class="rb-dialog-foot">
                <button type="button" class="btn rb-btn-ghost" data-close>Cancel</button>
                <button type="button" class="btn btn-primary" id="rbPasteConfirm"><i class="fas fa-paste"></i> Add rows</button>
            </div>
        </div>
    </div>

    <!-- Condition builder -->
    <div class="rb-modal" id="rbWizardModal" role="dialog" aria-modal="true" aria-labelledby="rbWizardTitle">
        <div class="rb-dialog wide">
            <div class="rb-dialog-head"><h3 id="rbWizardTitle"><i class="fas fa-filter"></i> Build with conditions</h3><button type="button" data-close aria-label="Close">&times;</button></div>
            <div class="rb-dialog-body" id="rbWizardBody"></div>
            <div class="rb-dialog-foot">
                <button type="button" class="btn rb-btn-ghost" data-close>Cancel</button>
                <button type="button" class="btn btn-primary" id="rbWizardApply"><i class="fas fa-check"></i> Use this formula</button>
            </div>
        </div>
    </div>

    <!-- Letterhead editor -->
    <div class="rb-modal" id="rbLhModal" role="dialog" aria-modal="true" aria-labelledby="rbLhTitle">
        <div class="rb-dialog wide">
            <div class="rb-dialog-head"><h3 id="rbLhTitle"><i class="fas fa-stamp"></i> Edit letterheads</h3><button type="button" data-close aria-label="Close">&times;</button></div>
            <div class="rb-dialog-body">
                <div class="rb-lh-layout">
                    <div>
                        <div class="rb-lh-list" id="rbLhList"></div>
                        <button type="button" class="rb-mini" id="rbLhNew" style="margin-top:8px;width:100%"><i class="fas fa-plus"></i> New letterhead</button>
                    </div>
                    <div class="rb-lh-form" id="rbLhForm">
                        <div class="rb-row">
                            <div class="rb-field"><label for="lhLabel">Letterhead name (for you)</label><input type="text" class="rb-input" id="lhLabel" maxlength="100" placeholder="e.g. Chitti Fund, Event Committee"></div>
                            <div class="rb-field"><label>&nbsp;</label><label class="rb-check" style="height:38px"><input type="checkbox" id="lhDefault"> Default letterhead</label></div>
                        </div>
                        <h4>Organisation</h4>
                        <div class="rb-field"><label for="lh_name">Name</label><input type="text" class="rb-input" data-lh="name" id="lh_name" maxlength="120"></div>
                        <div class="rb-row" style="margin-top:10px">
                            <div class="rb-field"><label for="lh_tagline">Tagline</label><input type="text" class="rb-input" data-lh="tagline" id="lh_tagline" maxlength="160"></div>
                            <div class="rb-field"><label for="lh_extra">Extra line (e.g. Regd. No.)</label><input type="text" class="rb-input" data-lh="extra" id="lh_extra" maxlength="160"></div>
                        </div>
                        <div class="rb-field"><label for="lh_address">Address</label><input type="text" class="rb-input" data-lh="address" id="lh_address" maxlength="200"></div>
                        <div class="rb-row3" style="margin-top:10px">
                            <div class="rb-field"><label for="lh_phone">Phone</label><input type="text" class="rb-input" data-lh="phone" id="lh_phone" maxlength="60"></div>
                            <div class="rb-field"><label for="lh_email">Email</label><input type="text" class="rb-input" data-lh="email" id="lh_email" maxlength="100"></div>
                            <div class="rb-field"><label for="lh_website">Website</label><input type="text" class="rb-input" data-lh="website" id="lh_website" maxlength="100"></div>
                        </div>
                        <p class="rb-hint" style="margin-top:0">Leave a line empty to leave it off the letterhead.</p>

                        <h4>Logo</h4>
                        <div class="rb-lh-logo">
                            <select class="rb-select" data-lh="logo_choice" id="lh_logo_choice" style="width:auto">
                                <option value="system">System logo (from Settings)</option>
                                <option value="custom">My own image</option>
                                <option value="none">No logo</option>
                            </select>
                            <input type="file" id="lh_logo_file" accept="image/png,image/jpeg,image/gif" style="display:none">
                            <button type="button" class="rb-mini" id="lh_logo_upload"><i class="fas fa-upload"></i> Upload image</button>
                            <img id="lh_logo_img" alt="" style="display:none">
                        </div>
                        <div class="rb-row3" style="margin-top:10px">
                            <div class="rb-field"><label for="lh_logo_position">Logo position</label>
                                <select class="rb-select" data-lh="logo_position" id="lh_logo_position"><option value="left">Left</option><option value="right">Right</option><option value="top">Centred above the name</option></select></div>
                            <div class="rb-field"><label for="lh_logo_height">Logo size: <b id="lh_logo_height_v"></b> mm</label><input type="range" data-lh="logo_height" id="lh_logo_height" min="10" max="35" step="1" style="width:100%"></div>
                            <div class="rb-field"><label for="lh_align">Text alignment</label>
                                <select class="rb-select" data-lh="align" id="lh_align"><option value="auto">Automatic</option><option value="left">Left</option><option value="center">Centre</option><option value="right">Right</option></select></div>
                        </div>

                        <h4>Look</h4>
                        <div class="rb-row3">
                            <div class="rb-field"><label for="lh_primary">Main colour</label><input type="color" data-lh="primary" id="lh_primary">
                                <div class="rb-swatches" data-swatch-for="primary"></div></div>
                            <div class="rb-field"><label for="lh_accent">Accent colour</label><input type="color" data-lh="accent" id="lh_accent">
                                <div class="rb-swatches" data-swatch-for="accent"></div></div>
                            <div class="rb-field"><label for="lh_name_size">Name size: <b id="lh_name_size_v"></b> pt</label><input type="range" data-lh="name_size" id="lh_name_size" min="12" max="28" step="0.5" style="width:100%">
                                <label class="rb-check" style="margin-top:6px"><input type="checkbox" data-lh="name_upper" id="lh_name_upper"> CAPITAL LETTERS</label></div>
                        </div>
                        <div class="rb-checks">
                            <label class="rb-check"><input type="checkbox" data-lh="bands" id="lh_bands"> Coloured bands at the top and bottom</label>
                            <label class="rb-check"><input type="checkbox" data-lh="double_rule" id="lh_double_rule"> Double line under the letterhead</label>
                            <label class="rb-check">Title style
                                <select class="rb-select" data-lh="title_style" id="lh_title_style" style="width:auto;padding:4px 8px"><option value="band">Shaded band</option><option value="plain">Plain with underline</option></select></label>
                        </div>

                        <div class="rb-lh-preview"><div class="rb-paper" id="rbLhPreview"></div></div>
                    </div>
                </div>
            </div>
            <div class="rb-dialog-foot">
                <button type="button" class="btn rb-btn-ghost" id="rbLhDelete" style="margin-right:auto;color:#dc2626"><i class="fas fa-trash"></i> Delete</button>
                <button type="button" class="btn rb-btn-ghost" data-close>Close</button>
                <button type="button" class="btn rb-btn-ghost" id="rbLhSaveAs"><i class="fas fa-copy"></i> Save as new</button>
                <button type="button" class="btn btn-primary" id="rbLhSave"><i class="fas fa-save"></i> Save &amp; use for this report</button>
            </div>
        </div>
    </div>

    <div class="rb-toast" id="rbToast" role="status"></div>

    <form id="rbExportForm" method="POST" action="generate-custom-report.php" target="_blank" style="display:none">
        <input type="hidden" name="config">
        <input type="hidden" name="format">
    </form>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
    <script src="theme-manager.js"></script>
    <script>
    (function () {
        'use strict';
        const BOOT = <?php echo json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const DRAFT_KEY = 'rb_draft_v1';
        const NUMERIC = ['amount', 'number', 'integer', 'percent'];
        const FORMAT_LABELS = { text: 'Text', amount: 'Amount (₹)', number: 'Number', integer: 'Whole no.', percent: 'Percent', date: 'Date', datetime: 'Date & time' };
        const TOTAL_LABELS = { none: 'No total', sum: 'Sum', avg: 'Average', count: 'Count', min: 'Minimum', max: 'Maximum', recalc: 'Recalculate' };
        const OPS = { eq: 'equals', ne: 'is not', gt: 'greater than', gte: 'at least', lt: 'less than', lte: 'at most', contains: 'contains', not_contains: 'does not contain', starts: 'starts with', ends: 'ends with', empty: 'is blank', not_empty: 'is not blank', in: 'is one of (a, b, c)', between: 'between' };

        const state = { meta: null, config: null, templateId: 0, name: '', description: '', dirty: false, active: 0 };
        let previewTimer = null;
        let previewSeq = 0;
        let sortable = null;

        const $ = id => document.getElementById(id);
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const clone = o => JSON.parse(JSON.stringify(o));
        const store = {
            get(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
            set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage unavailable */ } },
            del(k) { try { localStorage.removeItem(k); } catch (e) { /* storage unavailable */ } }
        };

        // ── API ──────────────────────────────────────────────────────────
        async function api(action, body, params = '') {
            const opts = { credentials: 'same-origin', headers: { 'X-RB-Token': BOOT.token } };
            if (body !== undefined) {
                opts.method = 'POST';
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            const res = await fetch('api/report-builder.php?action=' + action + params, opts);
            const data = await res.json().catch(() => ({ error: 'Unexpected response from the server.' }));
            if (!res.ok || data.error) throw new Error(data.error || ('Request failed (' + res.status + ')'));
            return data;
        }

        function toast(msg, type = 'info') {
            const t = $('rbToast');
            t.textContent = msg;
            t.className = 'rb-toast show ' + type;
            clearTimeout(toast.timer);
            toast.timer = setTimeout(() => t.classList.remove('show'), 3200);
        }

        // ── Helpers ──────────────────────────────────────────────────────
        const source = () => state.meta.sources[state.config.source];
        const isManual = () => state.config.source === 'manual';
        const fieldLabel = key => source().fields[key]?.label ?? key;

        function defaultColumnsFor(src) {
            return state.meta.sources[src].default_columns.map(k => ({ key: k, label: state.meta.sources[src].fields[k].label }));
        }

        function blankConfig(src = 'transactions') {
            return {
                title: 'Custom Report', subtitle: '', source: src,
                filters: { date_from: '', date_to: '', year_id: 0, member_ids: [], payment_mode: '', status: '', category: '', search: '', nonzero: [] },
                columns: src === 'manual'
                    ? [{ key: 'col1', label: 'S.No', format: 'integer', align: 'C', width: 0.6 }, { key: 'col2', label: 'Particulars', format: 'text', width: 3 }, { key: 'col3', label: 'Amount (Rs.)', format: 'amount', total: 'sum' }]
                    : defaultColumnsFor(src),
                manual_rows: src === 'manual' ? [['1', '', ''], ['2', '', ''], ['3', '', '']] : [],
                sort: [], group_by: '', layout: {}
            };
        }

        /** Fill every option the UI edits, so controls always have a value */
        function withDefaults(cfg) {
            const c = clone(cfg);
            c.filters = Object.assign({
                date_from: '', date_to: '', date_field: state.meta.sources[c.source]?.default_date_field || '', year_id: 0, member_ids: [],
                payment_mode: '', status: '', category: '', search: '', nonzero: [], conditions: [], match: 'all'
            }, c.filters || {});
            c.layout = Object.assign({
                orientation: 'P', font_size: 8.5, date_format: 'd-m-Y', zero_dash: true, zebra: true, row_numbers: false,
                summary_tiles: true, group_subtotals: true, summary_only: false, signatures: true, sig_left: 'Prepared By', sig_right: 'Authorised Signatory',
                notes: '', footer_note: 'Computer-generated report'
            }, c.layout || {});
            c.columns = (c.columns || []).map(col => {
                const type = col.key && col.key.startsWith('calc_') ? (col.format || 'amount') : (state.meta.sources[c.source]?.fields[col.key]?.type || col.format || 'text');
                const format = col.format || type;
                const numeric = NUMERIC.includes(format);
                return Object.assign({ label: '', format, align: numeric ? 'R' : (format === 'date' || format === 'datetime' ? 'C' : 'L'), width: 1, total: 'none', formula: '', hidden: false }, col, { format });
            });
            c.calculations = (c.calculations || []).map(x => Object.assign({ label: '', formula: '', format: 'amount' }, x));
            c.letterhead_id = +c.letterhead_id || 0;
            c.columns.forEach(col => { if (!col.label) col.label = col.key.startsWith('calc_') ? 'Calculated' : (state.meta.sources[c.source]?.fields[col.key]?.label || col.key); });
            c.manual_rows = c.manual_rows || [];
            c.sort = c.sort || [];
            c.group_by = c.group_by || '';
            return c;
        }

        // ── Sections (multiple tables in one report) ─────────────────────
        const SECTION_KEYS = ['heading', 'source', 'filters', 'columns', 'manual_rows', 'sort', 'group_by', 'calculations'];
        const cloneSafe = v => (v === undefined ? undefined : clone(v));
        function extractSection(cfg) {
            const s = {}; SECTION_KEYS.forEach(k => { s[k] = cloneSafe(cfg[k]); }); return s;
        }
        function currentGlobals() {
            return { title: state.config.title, subtitle: state.config.subtitle, layout: clone(state.config.layout), letterhead_id: state.config.letterhead_id };
        }
        function stashSection() { state.config.sections[state.active] = extractSection(state.config); }
        function loadSection(i) {
            const g = currentGlobals();
            const secs = state.config.sections;
            state.config = withDefaults(Object.assign({}, g, clone(secs[i])));
            state.config.sections = secs;
            state.active = i;
        }
        function fullConfig() { stashSection(); return state.config; }
        function sectionLabel(sec, i) {
            const h = (sec.heading || '').trim();
            if (h) return h;
            return (state.meta.sources[sec.source]?.label) || ('Table ' + (i + 1));
        }
        function switchSection(i) {
            if (i === state.active) return;
            stashSection(); loadSection(i); renderAll(); schedulePreview(0);
        }
        function sectionFromConfig(cfg) {
            const c = (cfg && Array.isArray(cfg.sections) && cfg.sections.length) ? cfg.sections[0] : (cfg || {});
            const s = {}; SECTION_KEYS.forEach(k => { s[k] = cloneSafe(c[k]); }); return s;
        }
        function addSection(sec) {
            stashSection();
            state.config.sections.push(sec);
            loadSection(state.config.sections.length - 1);
            renderAll(); changed({ rerender: false });
        }
        function removeSection() {
            if (state.config.sections.length <= 1) return;
            if (!confirm('Remove this table from the report?')) return;
            const secs = state.config.sections;
            secs.splice(state.active, 1);
            state.active = Math.max(0, state.active - 1);
            state.config.sections = secs;
            loadSection(state.active); renderAll(); changed({ rerender: false });
        }
        function renameSection() {
            const cur = (state.config.sections[state.active].heading) || state.config.heading || '';
            const v = prompt('Heading for this table (blank = use the data-source name):', cur);
            if (v === null) return;
            state.config.heading = v.trim();
            renderSectionBar(); changed();
        }
        function renderSectionBar() {
            const bar = $('rbSections');
            if (!bar || !state.config) return;
            const secs = state.config.sections || [];
            const presetItems = (state.meta.presets || []).map(p => `<button type="button" data-preset="${esc(p.key)}">${esc(p.name)}</button>`).join('');
            bar.innerHTML = `<span class="lbl">Tables</span>`
                + secs.map((s, i) => `<button type="button" class="rb-seg${i === state.active ? ' on' : ''}" data-sec="${i}">${i + 1}. ${esc(sectionLabel(s, i).slice(0, 24))}</button>`).join('')
                + `<span class="rb-sec-menu" id="rbAddSecMenu"><button type="button" class="rb-seg rb-seg-add" id="rbAddSecBtn"><i class="fas fa-plus"></i> Add table</button>
                     <div class="menu">
                        <button type="button" data-add="blank"><i class="fas fa-table"></i> Blank table (customize)</button>
                        ${presetItems ? `<div style="font-size:11px;color:var(--text-secondary);padding:4px 10px 2px">From a preset</div>${presetItems}` : ''}
                        <button type="button" data-add="saved"><i class="fas fa-folder-open"></i> From a saved report…</button>
                     </div></span>`
                + (secs.length > 1 ? `<button type="button" class="rb-seg" id="rbRenameSec">Rename</button><button type="button" class="rb-seg-x" id="rbRemoveSec" title="Remove this table"><i class="fas fa-trash"></i></button>` : '');
            bar.querySelectorAll('[data-sec]').forEach(b => b.onclick = () => switchSection(+b.dataset.sec));
            const menu = $('rbAddSecMenu');
            $('rbAddSecBtn').onclick = e => { e.stopPropagation(); menu.classList.toggle('open'); };
            document.addEventListener('click', () => menu && menu.classList.remove('open'), { once: true });
            bar.querySelectorAll('[data-add]').forEach(b => b.onclick = () => {
                menu.classList.remove('open');
                if (b.dataset.add === 'blank') addSection(extractSection(withDefaults(blankConfig('transactions'))));
                else if (b.dataset.add === 'saved') addSectionFromSaved();
            });
            bar.querySelectorAll('[data-preset]').forEach(b => b.onclick = () => {
                menu.classList.remove('open');
                const p = (state.meta.presets || []).find(x => x.key === b.dataset.preset);
                if (p) addSection(sectionFromConfig(withDefaults(p.config)));
            });
            if ($('rbRenameSec')) $('rbRenameSec').onclick = renameSection;
            if ($('rbRemoveSec')) $('rbRemoveSec').onclick = removeSection;
        }
        async function addSectionFromSaved() {
            try {
                const d = await api('templates');
                const rows = d.templates || [];
                if (!rows.length) { toast('No saved reports yet.', 'info'); return; }
                const names = rows.map((r, i) => (i + 1) + '. ' + r.Name).join('\n');
                const pick = prompt('Add which saved report as a table?\n\n' + names + '\n\nEnter the number:', '1');
                const idx = parseInt(pick, 10) - 1;
                if (isNaN(idx) || !rows[idx]) return;
                const t = await api('template', undefined, '&id=' + rows[idx].TemplateID);
                addSection(sectionFromConfig(t.template.Config));
            } catch (e) { toast(e.message, 'error'); }
        }

        // ── State changes ────────────────────────────────────────────────
        function load(cfg, { templateId = 0, name = '', description = '' } = {}) {
            const raw = clone(cfg || {});
            let secs = (Array.isArray(raw.sections) && raw.sections.length) ? raw.sections : [extractSection(raw)];
            state.active = 0;
            state.config = withDefaults(Object.assign({}, raw, secs[0]));
            state.config.sections = secs.map(s => clone(s));
            state.templateId = templateId;
            state.name = name;
            state.description = description;
            setDirty(false);
            renderAll();
            schedulePreview(0);
        }

        function changed({ rerender = false, preview = true } = {}) {
            setDirty(true);
            if (state.config && state.config.sections) stashSection();
            store.set(DRAFT_KEY, { config: state.config, templateId: state.templateId, name: state.name, at: Date.now() });
            if (rerender) renderAll();
            if (preview) schedulePreview();
        }

        function setDirty(d) {
            state.dirty = d;
            $('rbDirty').classList.toggle('show', d);
            $('rbDocName').textContent = state.name || state.config?.title || 'Untitled report';
            if (!d) store.del(DRAFT_KEY);
        }

        // ── Rendering: Data tab ──────────────────────────────────────────
        function renderAll() {
            $('rbTitle').value = state.config.title;
            renderSectionBar();
            renderSources();
            renderFilters();
            renderColumns();
            renderRows();
            renderMaths();
            renderLayout();
        }

        const MODULE_ICONS = { 'Chitti Fund': 'fa-coins', 'Members': 'fa-users', 'Accounting': 'fa-calculator', 'Events': 'fa-calendar-check', 'Administration': 'fa-user-shield', 'System': 'fa-gears', 'Other tables': 'fa-table', 'Your own data': 'fa-keyboard' };
        const ui = { sourceQuery: '', showEmpty: store.get('rb_show_empty') === true };

        /** Every module of the application as cards, grouped, searchable */
        function renderSources() {
            const q = ui.sourceQuery.trim().toLowerCase();
            const all = Object.entries(state.meta.sources);
            const emptyCount = all.filter(([, s]) => !s.curated && !s.rows).length;
            const visible = all.filter(([key, s]) => {
                if (key === state.config.source) return true;
                if (q) return (s.label + ' ' + s.description + ' ' + s.module + ' ' + key).toLowerCase().includes(q);
                return s.curated || s.rows || ui.showEmpty;
            });
            const modules = state.meta.modules.filter(m => visible.some(([, s]) => s.module === m))
                .concat([...new Set(visible.map(([, s]) => s.module))].filter(m => !state.meta.modules.includes(m)));
            const card = ([key, s]) => `
                <button type="button" class="rb-source${key === state.config.source ? ' on' : ''}${!s.curated && !s.rows ? ' empty' : ''}" data-source="${esc(key)}" title="${esc(s.description)}">
                    <i class="fas ${esc(s.icon)}"></i>
                    <div><b>${esc(s.label)}${s.curated ? '<i class="fas fa-star rb-star" title="Ready-made report with calculated totals"></i>' : ''}${s.rows !== null && s.rows !== undefined ? `<span class="rb-rows">${s.rows.toLocaleString('en-IN')}</span>` : ''}</b><span>${esc(s.description)}</span></div>
                </button>`;
            $('rbSources').innerHTML = `
                <div class="rb-src-tools" style="grid-column: 1 / -1">
                    <input type="search" class="rb-input" id="rbSourceSearch" placeholder="Find a module: events, budget, notifications…" value="${esc(ui.sourceQuery)}">
                    <label class="rb-check"><input type="checkbox" id="rbShowEmpty"${ui.showEmpty ? ' checked' : ''}> Show empty (${emptyCount})</label>
                </div>
                ${modules.map(m => {
                    const items = visible.filter(([, s]) => s.module === m);
                    return `<div class="rb-module" style="grid-column: 1 / -1">
                        <div class="rb-module-head"><i class="fas ${MODULE_ICONS[m] || 'fa-folder'}"></i>${esc(m)} <span>(${items.length})</span></div>
                        <div class="rb-sources">${items.map(card).join('')}</div>
                    </div>`;
                }).join('') || '<div class="rb-empty" style="grid-column: 1 / -1">No module matches your search.</div>'}
                <p class="rb-hint" style="grid-column: 1 / -1"><i class="fas fa-star" style="color:var(--rb-gold)"></i> = ready-made report with totals worked out. The numbers show how many rows each module has. Related names (member, event, academic year) are added to every table automatically.</p>`;
        }

        function quickRanges() {
            const today = new Date();
            const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            const y = today.getFullYear(), m = today.getMonth();
            const fyStart = m >= 3 ? y : y - 1;
            const active = state.meta.years.find(yr => yr.Status === 'Active');
            const ranges = [
                ['This month', iso(new Date(y, m, 1)), iso(new Date(y, m + 1, 0))],
                ['Last month', iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))],
                ['Last 3 months', iso(new Date(y, m - 2, 1)), iso(new Date(y, m + 1, 0))],
                ['This year', y + '-01-01', y + '-12-31'],
                ['Financial year', fyStart + '-04-01', (fyStart + 1) + '-03-31'],
            ];
            if (active) ranges.push(['Academic year ' + active.YearName, active.StartDate, active.EndDate]);
            ranges.push(['All dates', '', '']);
            return ranges;
        }

        function renderFilters() {
            const s = source();
            const f = state.config.filters;
            const flt = s.filters;
            let html = '';
            if (isManual()) {
                $('rbFilters').innerHTML = '<div class="rb-section"><p class="rb-hint">A manual table uses the rows you type or paste in step 3 (Rows).</p></div>' + conditionsHtml();
                return;
            }
            if (flt.date) {
                const dateFields = s.date_fields || [];
                html += `<div class="rb-section"><h3>Date range</h3>
                    ${dateFields.length > 1 ? `<div class="rb-field" style="margin-bottom:10px"><label>Which date</label><select class="rb-select" data-filter="date_field">${dateFields.map(d => `<option value="${esc(d.key)}"${f.date_field === d.key ? ' selected' : ''}>${esc(d.label)}</option>`).join('')}</select></div>` : ''}
                    <div class="rb-row">
                        <div class="rb-field"><label for="rbFrom">From</label><input type="date" class="rb-input" id="rbFrom" data-filter="date_from" value="${esc(f.date_from)}"></div>
                        <div class="rb-field"><label for="rbTo">To</label><input type="date" class="rb-input" id="rbTo" data-filter="date_to" value="${esc(f.date_to)}"></div>
                    </div>
                    <div class="rb-chips">${quickRanges().map(r => `<button type="button" class="rb-chip${f.date_from === r[1] && f.date_to === r[2] ? ' on' : ''}" data-range="${esc(r[1])}|${esc(r[2])}">${esc(r[0])}</button>`).join('')}</div>
                </div>`;
            }
            const selects = [];
            if (flt.year) selects.push(['year_id', 'Academic year', [['0', 'All years'], ...state.meta.years.map(y => [String(y.YearID), y.YearName + (y.Status === 'Active' ? ' (Active)' : '')])]]);
            if (flt.payment_mode) selects.push(['payment_mode', 'Payment mode', [['', 'All modes'], ...state.meta.payment_modes.map(p => [p, p])]]);
            if (flt.status) {
                const list = state.config.source === 'members' ? state.meta.statuses.members : state.meta.statuses.expenses;
                selects.push(['status', 'Status', [['', 'All statuses'], ...list.map(p => [p, p])]]);
            }
            if (flt.category) selects.push(['category', 'Category', [['', 'All categories'], ...state.meta.categories.map(p => [p, p])]]);
            if (selects.length) {
                html += '<div class="rb-section"><h3>Filters</h3><div class="rb-row">' + selects.map(([key, label, opts]) => `
                    <div class="rb-field"><label>${esc(label)}</label><select class="rb-select" data-filter="${key}">
                        ${opts.map(([v, t]) => `<option value="${esc(v)}"${String(f[key]) === v ? ' selected' : ''}>${esc(t)}</option>`).join('')}
                    </select></div>`).join('') + '</div></div>';
            }
            if (flt.member) {
                const picked = new Set(f.member_ids.map(Number));
                html += `<div class="rb-section"><h3>Members <small id="rbMemberCount">${picked.size ? picked.size + ' selected' : 'All members'}</small></h3>
                    <div class="rb-members">
                        <div class="rb-members-head">
                            <input type="search" class="rb-input" id="rbMemberSearch" placeholder="Find a member…">
                            <button type="button" class="rb-mini" data-members="all">All</button>
                            <button type="button" class="rb-mini" data-members="none">Clear</button>
                        </div>
                        <div class="rb-members-list" id="rbMemberList">
                            ${state.meta.members.map(m => `<label class="rb-check" data-name="${esc(m.MemberName.toLowerCase())}"><input type="checkbox" value="${m.MemberID}"${picked.has(+m.MemberID) ? ' checked' : ''}> ${esc(m.MemberName)} <span style="color:var(--text-muted);font-size:11px">#${m.MemberID}${m.Status !== 'Active' ? ' · ' + esc(m.Status) : ''}</span></label>`).join('')}
                        </div>
                    </div>
                    <p class="rb-hint">Leave everything unticked to include all members.</p></div>`;
            }
            if (s.nonzero.length) {
                html += `<div class="rb-section"><h3>Only include rows with</h3><div class="rb-checks">
                    ${s.nonzero.map(k => `<label class="rb-check"><input type="checkbox" data-nonzero="${k}"${f.nonzero.includes(k) ? ' checked' : ''}> ${esc(s.fields[k].label)}</label>`).join('')}
                    </div><p class="rb-hint">A row is kept when any ticked amount is not zero.</p></div>`;
            }
            if (flt.search) {
                html += `<div class="rb-section"><h3>Text contains</h3><input type="search" class="rb-input" data-filter="search" value="${esc(f.search)}" placeholder="Name, remark, receipt no…"></div>`;
            }
            $('rbFilters').innerHTML = html + conditionsHtml();
        }

        /** Advanced conditions on any field or calculated column */
        function conditionsHtml() {
            const f = state.config.filters;
            const choices = sortChoices();
            const rows = f.conditions.map((c, i) => {
                const noValue = c.op === 'empty' || c.op === 'not_empty';
                return `<div class="rb-cond" data-cond="${i}">
                    <select class="rb-select" data-condprop="field" aria-label="Field">${choices.map(([k, l]) => `<option value="${esc(k)}"${c.field === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
                    <select class="rb-select" data-condprop="op" aria-label="Condition">${Object.entries(OPS).map(([k, l]) => `<option value="${k}"${c.op === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
                    ${noValue ? '<span></span>' : c.op === 'between'
                        ? `<div class="rb-between"><input type="text" class="rb-input" data-condprop="value" value="${esc(c.value)}" placeholder="from"><input type="text" class="rb-input" data-condprop="value2" value="${esc(c.value2 || '')}" placeholder="to"></div>`
                        : `<input type="text" class="rb-input" data-condprop="value" value="${esc(c.value)}" placeholder="value">`}
                    <button type="button" class="rb-icon danger" data-delcond title="Remove condition"><i class="fas fa-times"></i></button>
                </div>`;
            }).join('');
            return `<div class="rb-section" id="rbConditions"><h3>Conditions <small>on any column</small></h3>
                ${f.conditions.length > 1 ? `<div class="rb-match">Keep rows that match <select class="rb-select" data-filter="match"><option value="all"${f.match === 'all' ? ' selected' : ''}>all</option><option value="any"${f.match === 'any' ? ' selected' : ''}>any</option></select> of these conditions</div>` : ''}
                ${rows || '<p class="rb-hint" style="margin:0 0 8px">e.g. Amount at least 1000, Status is not Cancelled, Event Name contains "Vinayaka". Dates as YYYY-MM-DD.</p>'}
                <button type="button" class="rb-mini" id="rbAddCond"${choices.length ? '' : ' disabled'}><i class="fas fa-plus"></i> Add condition</button>
            </div>`;
        }

        // ── Rendering: Columns tab ───────────────────────────────────────
        function columnHtml(col, i) {
            const isCalc = col.key.startsWith('calc_');
            const showFormula = isCalc || (isManual() && col.formula);
            const keyText = isManual() ? (col.formula ? '<span class="ƒ">ƒ</span> calculated' : 'typed values') : (isCalc ? '<span class="ƒ">ƒ</span> formula' : esc(fieldLabel(col.key)));
            return `<div class="rb-col${col.hidden ? ' is-hidden' : ''}" data-i="${i}">
                <span class="rb-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                <div class="rb-col-main">
                    <div class="rb-col-top"><input type="text" class="rb-input" data-col="label" value="${esc(col.label)}" maxlength="80" aria-label="Column heading"></div>
                    <div class="rb-col-key">${keyText}</div>
                    ${showFormula ? `<input type="text" class="rb-input rb-formula" data-col="formula" value="${esc(col.formula)}" placeholder="{DistributedAmount} + {InterestAmount}" aria-label="Formula" spellcheck="false">
                        <div class="rb-issue" data-issue-col="${esc(col.key)}"></div>
                        <button type="button" class="rb-build-btn" data-wizard-col="${i}"><i class="fas fa-filter"></i> ${col.builder ? 'Edit conditions' : 'Build with conditions (if/then, or look up any module)'}</button>` : ''}
                    <div class="rb-col-opts">
                        <select class="rb-select" data-col="format" aria-label="Format">${Object.entries(FORMAT_LABELS).map(([v, t]) => `<option value="${v}"${col.format === v ? ' selected' : ''}>${t}</option>`).join('')}</select>
                        <div class="rb-seg" aria-label="Alignment">${['L', 'C', 'R'].map(a => `<button type="button" data-align="${a}" class="${col.align === a ? 'on' : ''}" title="${{ L: 'Left', C: 'Centre', R: 'Right' }[a]}"><i class="fas fa-align-${{ L: 'left', C: 'center', R: 'right' }[a]}"></i></button>`).join('')}</div>
                        <input type="number" class="rb-input" data-col="width" value="${col.width}" min="0.4" max="6" step="0.1" title="Relative width" aria-label="Relative width">
                        <select class="rb-select" data-col="total" aria-label="Total" title="${col.total === 'recalc' ? 'Total row works the formula out again from the column totals (right for percentages and ratios)' : 'What to show in the total row'}">${Object.entries(TOTAL_LABELS).filter(([v]) => v !== 'recalc' || showFormula).map(([v, t]) => `<option value="${v}"${col.total === v ? ' selected' : ''}>${t}</option>`).join('')}</select>
                    </div>
                </div>
                <div class="rb-col-actions">
                    <button type="button" class="rb-icon" data-colact="hide" title="${col.hidden ? 'Show in report' : 'Hide from report (keep for formulas)'}"><i class="fas fa-eye${col.hidden ? '-slash' : ''}"></i></button>
                    ${isManual() ? `<button type="button" class="rb-icon" data-colact="formula" title="Toggle formula"><i class="fas fa-square-root-alt"></i></button>` : ''}
                    <button type="button" class="rb-icon danger" data-colact="remove" title="Remove column"><i class="fas fa-trash"></i></button>
                </div>
            </div>`;
        }

        function renderColumns() {
            const cols = state.config.columns;
            $('rbColumns').innerHTML = cols.length ? cols.map(columnHtml).join('') : '<div class="rb-empty">No columns yet — add one below.</div>';
            if (sortable) sortable.destroy();
            if (window.Sortable && cols.length) {
                sortable = Sortable.create($('rbColumns'), {
                    handle: '.rb-handle', animation: 150,
                    onEnd(e) {
                        if (e.oldIndex === e.newIndex) return;
                        const [col] = state.config.columns.splice(e.oldIndex, 1);
                        state.config.columns.splice(e.newIndex, 0, col);
                        if (isManual()) {
                            state.config.manual_rows.forEach(r => { const [v] = r.splice(e.oldIndex, 1); r.splice(e.newIndex, 0, v ?? ''); });
                            renumberManual();
                        }
                        changed({ rerender: true });
                    }
                });
            }

            if (isManual()) {
                $('rbAddColumn').innerHTML = `<div class="rb-add"><button type="button" class="btn btn-primary btn-sm" id="rbAddManualCol"><i class="fas fa-plus"></i> Add column</button></div>`;
            } else {
                $('rbAddColumn').innerHTML = `
                    <div class="rb-add" style="flex-wrap:wrap">
                        <button type="button" class="btn btn-primary btn-sm" id="rbAddFormula"><i class="fas fa-square-root-alt"></i> Add calculated column</button>
                        <button type="button" class="rb-mini" id="rbTotalAll" title="Show a total for every amount and number column"><i class="fas fa-calculator"></i> Total all amounts</button>
                        <button type="button" class="rb-mini" id="rbResetCols"><i class="fas fa-undo"></i> Reset to default</button>
                    </div>`;
            }
            renderColPick();
            renderFormulaHelp();
        }

        /** Tick-box list of every field the module has */
        let colPickQuery = '';
        function renderColPick() {
            if (isManual()) { $('rbColPickSection').style.display = 'none'; return; }
            $('rbColPickSection').style.display = '';
            const used = new Set(state.config.columns.map(c => c.key));
            const fields = Object.entries(source().fields);
            const q = colPickQuery.trim().toLowerCase();
            const shown = fields.filter(([k, f]) => !q || (f.label + ' ' + k).toLowerCase().includes(q));
            $('rbColPickCount').textContent = fields.filter(([k]) => used.has(k)).length + ' of ' + fields.length + ' shown';
            $('rbColPick').innerHTML = `<div class="rb-colpick">
                <div class="rb-colpick-head">
                    <input type="search" class="rb-input" id="rbColPickSearch" placeholder="Find a column…" value="${esc(colPickQuery)}">
                    <button type="button" class="rb-mini" data-colpick="all">Tick all</button>
                    <button type="button" class="rb-mini" data-colpick="none">Untick all</button>
                </div>
                <div class="rb-colpick-list">${shown.map(([k, f]) => `
                    <label class="rb-check" title="${esc(f.label)} ({${esc(k)}})"><input type="checkbox" data-pick="${esc(k)}"${used.has(k) ? ' checked' : ''}><span>${esc(f.label)}</span><span class="rb-type">${esc((FORMAT_LABELS[f.type] || f.type).replace(' (₹)', ''))}</span></label>`).join('') || '<span class="rb-hint">No column matches.</span>'}
                </div>
            </div>`;
        }

        /** Many columns don't fit portrait: switch to landscape and smaller text */
        function fitWideReport() {
            const visible = state.config.columns.filter(c => !c.hidden).length + (state.config.layout.row_numbers ? 1 : 0);
            if (visible > 8 && state.config.layout.orientation === 'P') {
                state.config.layout.orientation = 'L';
                if (visible > 11) state.config.layout.font_size = Math.min(state.config.layout.font_size, 7);
                renderLayout();
                toast(visible + ' columns: switched the page to landscape so they fit. Change it in step 5 (Layout).');
            }
        }

        /** Fields usable in formulas: numbers first, then dates and text, then calculated columns */
        function formulaRefs() {
            if (isManual()) return state.config.columns.map(c => [c.key, c.label, c.format]);
            const order = { amount: 0, number: 0, integer: 1, percent: 1, date: 2, datetime: 2, text: 3 };
            const fields = Object.entries(source().fields).map(([k, f]) => [k, f.label, f.type]).sort((a, b) => (order[a[2]] ?? 4) - (order[b[2]] ?? 4));
            const calcs = state.config.columns.filter(c => c.key.startsWith('calc_')).map(c => [c.key, c.label + ' (calculated)', c.format]);
            return fields.concat(calcs);
        }

        function fieldChipsHtml() {
            const refs = formulaRefs();
            return refs.length
                ? refs.map(([k, l, t]) => `<button type="button" class="rb-fchip" data-ref="${esc(k)}" title="${esc(l)} · ${esc(FORMAT_LABELS[t] || t)}">{${esc(k)}}</button>`).join('')
                : '<span class="rb-hint">Add a column first.</span>';
        }

        function renderFormulaHelp() {
            const hasFormula = state.config.columns.some(c => c.key.startsWith('calc_') || (isManual() && c.formula));
            $('rbFormulaHelp').innerHTML = (hasFormula || !isManual()) ? `
                <h3>Calculated columns</h3>
                <p class="rb-hint" style="margin-top:0">Write maths with fields in braces, e.g. <code>{DistributedAmount} + {InterestAmount}</code>,
                <code>ROUND({Amount} * 18 / 100, 2)</code> or <code>IF({TransactionType} = "Collection", {Amount}, 0)</code>.
                Running balances: <code>RUNNING({Inflow}) - RUNNING({Outflow})</code>. All functions are listed in step 4 (Maths).</p>
                <div class="rb-fchips">${fieldChipsHtml()}</div>` : '';
        }

        /** Show formula problems reported by the server next to the inputs */
        function applyIssues(issues) {
            document.querySelectorAll('[data-issue-col]').forEach(el => {
                const msg = issues?.columns?.[el.dataset.issueCol] || '';
                el.textContent = msg;
                el.classList.toggle('show', !!msg);
                el.previousElementSibling?.classList.toggle('bad', !!msg);
            });
            document.querySelectorAll('[data-issue-calc]').forEach(el => {
                const msg = issues?.calculations?.[el.dataset.issueCalc] || '';
                el.textContent = msg;
                el.classList.toggle('show', !!msg);
                el.parentElement.querySelector('.rb-formula')?.classList.toggle('bad', !!msg);
            });
        }

        // ── Rendering: Rows tab ──────────────────────────────────────────
        function renumberManual() {
            // Keep an "S.No" style first column numbered when it is a whole-number column
            const first = state.config.columns[0];
            if (first && first.format === 'integer' && /^s\.?\s*no/i.test(first.label)) {
                state.config.manual_rows.forEach((r, i) => { r[0] = String(i + 1); });
            }
        }

        function renderManual() {
            if (!isManual()) { $('rbManualSection').innerHTML = ''; return; }
            const cols = state.config.columns;
            const rows = state.config.manual_rows;
            $('rbManualSection').innerHTML = `
                <h3>Table rows <small>${rows.length} row${rows.length === 1 ? '' : 's'}</small></h3>
                <div class="rb-grid-wrap"><table class="rb-grid">
                    <thead><tr><th>#</th>${cols.map(c => `<th>${esc(c.label)}${c.formula ? ' ƒ' : ''}</th>`).join('')}<th></th></tr></thead>
                    <tbody>${rows.map((r, ri) => `<tr data-r="${ri}"><td class="rb-num">${ri + 1}</td>${cols.map((c, ci) => `<td><input type="text" data-cell="${ci}" value="${esc(r[ci] ?? '')}"${c.formula ? ' disabled placeholder="calculated"' : ''}></td>`).join('')}<td><button type="button" class="rb-icon danger" data-delrow="${ri}" title="Delete row"><i class="fas fa-times"></i></button></td></tr>`).join('')}</tbody>
                </table></div>
                <div class="rb-add" style="margin-top:8px">
                    <button type="button" class="btn btn-primary btn-sm" id="rbAddRow"><i class="fas fa-plus"></i> Add row</button>
                    <button type="button" class="rb-mini" id="rbPasteRows"><i class="fas fa-paste"></i> Paste from Excel</button>
                    <button type="button" class="rb-mini" id="rbClearRows"><i class="fas fa-eraser"></i> Clear</button>
                </div>
                <p class="rb-hint">Tip: press Enter in the last row to add a new row. Amount columns accept values like 1,500 or ₹1500.</p>`;
        }

        function sortChoices() {
            const list = [];
            if (!isManual()) Object.entries(source().fields).forEach(([k, f]) => list.push([k, f.label]));
            state.config.columns.forEach(c => { if (isManual() || c.key.startsWith('calc_')) list.push([c.key, c.label]); });
            return list;
        }

        function renderRows() {
            renderManual();
            const choices = sortChoices();
            $('rbSort').innerHTML = state.config.sort.map((s, i) => `
                <div class="rb-sortrule" data-sort="${i}">
                    <select class="rb-select" data-sortkey>${choices.map(([k, l]) => `<option value="${esc(k)}"${s.key === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
                    <select class="rb-select" data-sortdir><option value="asc"${s.dir === 'asc' ? ' selected' : ''}>A → Z / Low → High</option><option value="desc"${s.dir === 'desc' ? ' selected' : ''}>Z → A / High → Low</option></select>
                    <button type="button" class="rb-icon danger" data-delsort title="Remove"><i class="fas fa-times"></i></button>
                </div>`).join('') || '<p class="rb-hint" style="margin:0 0 8px">Default order.</p>';
            $('rbAddSort').style.display = state.config.sort.length >= 3 ? 'none' : '';
            $('rbGroupBy').innerHTML = '<option value="">No grouping</option>' + choices.map(([k, l]) => `<option value="${esc(k)}"${state.config.group_by === k ? ' selected' : ''}>${esc(l)}</option>`).join('');
            $('rbSummaryOnlyRow').style.display = state.config.group_by ? '' : 'none';
            syncLayoutSwitches();
        }

        // ── Rendering: Maths tab ─────────────────────────────────────────
        let lastCalcValues = [];

        function renderMaths() {
            const calcs = state.config.calculations;
            const fmtOptions = sel => Object.entries(FORMAT_LABELS).filter(([v]) => v !== 'date' && v !== 'datetime').map(([v, t]) => `<option value="${v}"${sel === v ? ' selected' : ''}>${t}</option>`).join('');
            $('rbCalcs').innerHTML = calcs.map((c, i) => `
                <div class="rb-calcline" data-calc="${i}">
                    <div class="rb-calcline-top">
                        <input type="text" class="rb-input" data-calcprop="label" value="${esc(c.label)}" placeholder="Label, e.g. Closing balance" maxlength="80" aria-label="Label">
                        <select class="rb-select" data-calcprop="format" aria-label="Format">${fmtOptions(c.format)}</select>
                        <button type="button" class="rb-icon danger" data-delcalc title="Remove"><i class="fas fa-times"></i></button>
                    </div>
                    <input type="text" class="rb-input rb-formula" data-calcprop="formula" value="${esc(c.formula)}" placeholder="e.g. SUM({CollectionAmount}) - SUM({DistributedAmount})" style="margin-top:6px" spellcheck="false" aria-label="Formula">
                    <div class="rb-issue" data-issue-calc="${i}"></div>
                    ${c.formula.trim() === '' ? '<div class="rb-calc-hint">Type a formula, or build it from conditions with the button below.</div>' : ''}
                    <div class="rb-calc-value" data-calcval="${i}">${lastCalcValues[i] !== undefined && c.formula.trim() !== '' ? '= <b>' + esc(lastCalcValues[i]) + '</b>' : ''}</div>
                    <button type="button" class="rb-build-btn" data-wizard-calc="${i}"><i class="fas fa-filter"></i> ${c.builder ? 'Edit conditions' : 'Build with conditions'}</button>
                </div>`).join('') || '<p class="rb-hint" style="margin:0 0 8px">No calculations yet.</p>';

            // Quick add: totals / averages of every number field
            const nums = formulaRefs().filter(([, , t]) => NUMERIC.includes(t));
            const quick = [['', '＋ Quick add…'], ['COUNT()|Number of rows|integer', 'Number of rows']];
            nums.forEach(([k, l]) => {
                const totalLabel = /^total\b/i.test(l) ? l : 'Total ' + l;
                quick.push([`SUM({${k}})|${totalLabel}|amount`, 'Total of ' + l]);
                quick.push([`AVG({${k}})|Average ${l}|amount`, 'Average of ' + l]);
                quick.push([`MAX({${k}})|Highest ${l}|amount`, 'Highest ' + l]);
            });
            if (nums.length >= 2) {
                quick.push([`SUM({${nums[0][0]}}) - SUM({${nums[1][0]}})|${nums[0][1]} minus ${nums[1][1]}|amount`, nums[0][1] + ' − ' + nums[1][1]]);
                quick.push([`PCT(SUM({${nums[1][0]}}), SUM({${nums[0][0]}}))|${nums[1][1]} as % of ${nums[0][1]}|percent`, nums[1][1] + ' as % of ' + nums[0][1]]);
            }
            $('rbQuickCalc').innerHTML = quick.map(([v, t]) => `<option value="${esc(v)}">${esc(t)}</option>`).join('');

            const n1 = nums[0]?.[0] || 'Amount', n2 = nums[1]?.[0] || n1;
            const examples = [
                ['Difference of two totals', `SUM({${n1}}) - SUM({${n2}})`, 'amount'],
                ['Percentage', `PCT(SUM({${n2}}), SUM({${n1}}))`, 'percent'],
                ['Average per row, rounded', `ROUND(AVG({${n1}}), 2)`, 'amount'],
                ['Rows above ₹1,000', `COUNTIF({${n1}} > 1000)`, 'integer'],
                ['Simple interest: ₹1,00,000 at 2% for 12 months', 'INTEREST(100000, 2, 12)', 'amount'],
                ['Monthly instalment (EMI) for ₹50,000 at 1.5% for 10 months', 'ROUND(EMI(50000, 1.5, 10), 2)', 'amount'],
                ['Days since the start of the year', 'DAYS(TODAY(), "' + new Date().getFullYear() + '-01-01")', 'integer'],
            ];
            $('rbExamples').innerHTML = examples.map(([l, f, fmt]) => `<button type="button" class="rb-example" data-example="${esc(f)}" data-label="${esc(l)}" data-format="${fmt}">${esc(l)}<code>${esc(f)}</code></button>`).join('');
            $('rbMathFields').innerHTML = fieldChipsHtml();
            renderMathModule();
            renderFunctions();
        }

        let mathModule = 'expenses';
        function renderMathModule() {
            if (!moduleEntry(mathModule)[1]) mathModule = (Object.values(state.meta.sources).find(s => s.ref) || {}).ref || '';
            $('rbMathModule').innerHTML = moduleOptions(mathModule, false);
            const fields = moduleFields(mathModule);
            $('rbMathModuleFields').innerHTML = fields.map(([k, l, t]) => `<button type="button" class="rb-fchip" data-ref="${esc(k)}" title="${esc(l)} · ${esc(FORMAT_LABELS[t] || t)}">{${esc(k)}}</button>`).join('');
        }
        $('rbMathModule').addEventListener('change', e => { mathModule = e.target.value; renderMathModule(); });
        $('rbMathModuleFields').addEventListener('click', e => {
            const chip = e.target.closest('[data-ref]');
            if (chip) insertIntoFormula('{' + chip.dataset.ref + '}');
        });

        function renderFunctions() {
            const q = ($('rbFuncSearch').value || '').trim().toLowerCase();
            const list = state.meta.functions.filter(f => !q || (f.name + ' ' + f.description + ' ' + f.category).toLowerCase().includes(q));
            let html = '';
            let cat = '';
            list.forEach(f => {
                if (f.category !== cat) { cat = f.category; html += `<div class="rb-funcs-cat">${esc(cat)}</div>`; }
                html += `<button type="button" class="rb-func" data-func="${esc(f.name)}" title="Insert ${esc(f.name)}"><code>${esc(f.syntax)}</code><span>${esc(f.description)}</span></button>`;
            });
            $('rbFuncList').innerHTML = html || '<div class="rb-empty">No function matches.</div>';
        }

        // ── Rendering: Layout tab ────────────────────────────────────────
        function syncLayoutSwitches() {
            document.querySelectorAll('[data-layout]').forEach(el => { el.checked = !!state.config.layout[el.dataset.layout]; });
        }

        function renderLayout() {
            const l = state.config.layout;
            $('rbTitle2').value = state.config.title;
            $('rbSubtitle').value = state.config.subtitle;
            $('rbDateFormat').value = l.date_format;
            $('rbFontSize').value = l.font_size;
            $('rbFontSizeLabel').textContent = l.font_size;
            $('rbSigLeft').value = l.sig_left;
            $('rbSigRight').value = l.sig_right;
            $('rbNotes').value = l.notes;
            $('rbFooterNote').value = l.footer_note;
            $('rbSigLabels').style.display = l.signatures ? '' : 'none';
            renderLetterheadSelect();
            $('rbOrientation').querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === l.orientation));
            syncLayoutSwitches();
        }

        // ── Preview ──────────────────────────────────────────────────────
        function schedulePreview(delay = 350) {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(runPreview, delay);
        }

        async function runPreview() {
            const seq = ++previewSeq;
            $('rbSpin').classList.add('show');
            $('rbStatus').textContent = 'Updating…';
            try {
                const data = await api('preview', { config: fullConfig() });
                if (seq !== previewSeq) return;
                $('rbError').classList.remove('show');
                renderPaper(data);
                const sections = data.sections || [data];
                const active = sections[state.active] || sections[0];
                applyIssues(active.issues || data.issues);
                lastCalcValues = [];
                (active.calculations || []).forEach(c => { lastCalcValues[c.index] = c.value; });
                document.querySelectorAll('[data-calcval]').forEach(el => {
                    const v = lastCalcValues[+el.dataset.calcval];
                    el.innerHTML = v !== undefined ? '= <b>' + esc(v) + '</b>' : '';
                });
                const n = sections.reduce((a, s) => a + (s.row_count || 0), 0);
                const problems = Object.keys(active.issues?.columns || {}).length + Object.keys(active.issues?.calculations || {}).length;
                $('rbStatus').textContent = n.toLocaleString('en-IN') + ' record' + (n === 1 ? '' : 's')
                    + (sections.length > 1 ? ' · ' + sections.length + ' tables' : '')
                    + (sections.some(s => s.preview_limited) ? ' · preview shows the first 250' : '') + (sections.some(s => s.truncated) ? ' · capped at 20,000' : '')
                    + (problems ? ' · ' + problems + ' formula problem' + (problems === 1 ? '' : 's') : '');
            } catch (e) {
                if (seq !== previewSeq) return;
                $('rbError').textContent = e.message;
                $('rbError').classList.add('show');
                $('rbStatus').textContent = 'Preview unavailable';
            } finally {
                if (seq === previewSeq) $('rbSpin').classList.remove('show');
            }
        }

        // ── Letterhead drawing (preview + editor) ────────────────────────
        function letterheadFor(id) {
            const list = state.meta.letterheads || [];
            return list.find(x => x.id === +id && +id > 0) || list.find(x => x.is_default) || list[0];
        }
        function tint(hex, amount) {
            const n = parseInt(hex.slice(1), 16);
            const mix = c => Math.round(c + (255 - c) * amount);
            return 'rgb(' + mix(n >> 16 & 255) + ',' + mix(n >> 8 & 255) + ',' + mix(n & 255) + ')';
        }
        function letterheadVars(lh) {
            return `--rb-indigo:${lh.primary};--rb-gold:${lh.accent};--rb-indigo-soft:${tint(lh.primary, 0.9)}`;
        }
        function contactLine(lh) {
            return [lh.phone && 'Phone: ' + lh.phone, lh.email && 'Email: ' + lh.email, lh.website].filter(Boolean).join('   |   ');
        }
        /** Top of the first page: bands, logo + organisation block, rule and title */
        function letterheadTopHtml(lh, logoUrl, title) {
            const pos = logoUrl ? lh.logo_position : 'none';
            const autoAlign = pos === 'left' ? 'right' : (pos === 'right' ? 'left' : 'center');
            const align = lh.align === 'auto' ? autoAlign : lh.align;
            const name = lh.name_upper ? (lh.name || '').toUpperCase() : (lh.name || '');
            const contact = contactLine(lh);
            const logoPx = Math.round(lh.logo_height * 2.65);
            return `${lh.bands ? '<div class="lh-band"></div>' : '<div style="height:6px"></div>'}
                <div class="lh pos-${pos}">
                    ${logoUrl ? `<img src="${esc(logoUrl)}" alt="" style="max-height:${logoPx}px">` : ''}
                    <div class="lh-org" style="text-align:${align}">
                        <b style="font-size:${(lh.name_size * 1.26).toFixed(1)}px">${esc(name)}</b>
                        ${lh.tagline ? `<i>${esc(lh.tagline)}</i>` : ''}
                        ${lh.address ? `<span>${esc(lh.address)}</span>` : ''}
                        ${lh.extra ? `<span>${esc(lh.extra)}</span>` : ''}
                        ${contact ? `<span>${esc(contact)}</span>` : ''}
                    </div>
                </div>
                ${lh.double_rule ? '<div class="lh-rule"></div><div class="lh-rule2"></div>' : '<div class="lh-rule single"></div>'}
                <div class="doc-title${lh.title_style === 'plain' ? ' plain' : ''}">${esc(title)}</div>`;
        }

        function sectionTableHtml(sec, l, multi, idx) {
            const cols = sec.columns || [];
            const weights = cols.reduce((a, c) => a + c.width, 0) || 1;
            const fontPx = (l.font_size * 1.333).toFixed(1);
            let zebra = false;
            const body = (sec.items || []).map(it => {
                if (it.type === 'group') { zebra = false; return `<tr class="grp"><td colspan="${cols.length}">${esc(it.label)}</td></tr>`; }
                let cls = it.type === 'subtotal' ? 'sub' : 'tot';
                if (it.type === 'row') { cls = l.zebra && zebra ? 'zebra' : ''; zebra = !zebra; }
                return `<tr class="${cls}">${it.cells.map((c, i) => `<td class="a-${cols[i].align}">${esc(c)}</td>`).join('')}</tr>`;
            }).join('');
            const heading = (sec.heading || '').trim() || (multi ? 'Table ' + (idx + 1) : '');
            return `
                ${heading ? `<div class="doc-title plain" style="margin-top:${idx ? 14 : 8}px;text-align:left;font-size:15px">${esc(heading)}</div>` : ''}
                ${multi && sec.filters_text ? `<div style="font-size:11px;color:#64748b;margin:0 0 4px">${esc(sec.filters_text)}</div>` : ''}
                <div class="doc-table-wrap">
                    ${cols.length ? `<table style="font-size:${fontPx}px">
                        <colgroup>${cols.map(c => `<col style="width:${(c.width / weights * 100).toFixed(2)}%">`).join('')}</colgroup>
                        <thead><tr>${cols.map(c => `<th>${esc(c.label)}</th>`).join('')}</tr></thead>
                        <tbody>${body}</tbody>
                    </table>` : ''}
                    ${sec.row_count ? '' : '<div class="doc-empty">No records match the selected filters.</div>'}
                </div>
                ${sec.preview_limited ? `<div class="doc-more">… ${(sec.row_count - 250).toLocaleString('en-IN')} more rows are included in the PDF / Excel.</div>` : ''}
                ${(sec.tiles || []).length ? `<div class="tiles">${sec.tiles.map(t => `<div class="tile"><small>${esc(t[0])}</small><b title="${esc(t[1])}">${esc(t[1])}</b></div>`).join('')}</div>` : ''}
                ${(sec.calculations || []).length ? `<div class="calcs"><div class="calcs-head">CALCULATIONS</div>${sec.calculations.map(c => `<div class="calc-row"><span>${esc(c.label)}</span><b>${esc(c.value)}</b></div>`).join('')}</div>` : ''}`;
        }

        function renderPaper(data) {
            const cfg = data.config;
            const l = cfg.layout;
            const lhEntry = letterheadFor(cfg.letterhead_id);
            const lh = lhEntry.config;
            const o = { name: lh.name };
            const paper = $('rbPaper');
            paper.style.setProperty('--rb-indigo', lh.primary);
            paper.style.setProperty('--rb-gold', lh.accent);
            paper.style.setProperty('--rb-indigo-soft', tint(lh.primary, 0.9));
            paper.classList.toggle('landscape', l.orientation === 'L');
            const sections = data.sections || [data];
            const multi = sections.length > 1;
            const first = sections[0] || {};
            const maxCols = sections.reduce((a, s) => Math.max(a, (s.columns || []).length), 1);
            const totalRows = sections.reduce((a, s) => a + (s.row_count || 0), 0);
            const basePx = l.orientation === 'L' ? 1160 : 820;
            const minColPx = Math.round(l.font_size * 9);
            paper.style.width = Math.max(basePx, maxCols * minColPx + 56) + 'px';
            const today = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            paper.innerHTML = `
                ${letterheadTopHtml(lh, lhEntry.logo_url, cfg.title)}
                <div class="doc-details">
                    <b>Report Period:</b><span>${esc(first.period || 'All dates')}</span><b>Ref. No:</b><span class="r">SRC/RPT/…</span>
                    <b>Report Type:</b><span>${esc(cfg.subtitle || state.meta.sources[cfg.source].label)}</span><b>Date of Issue:</b><span class="r">${esc(today)}</span>
                    <b>${multi ? 'Tables:' : 'Filters:'}</b><span>${multi ? (sections.length + ' sections') : esc(first.filters_text || '')}</span><b>Records:</b><span class="r">${totalRows.toLocaleString('en-IN')}</span>
                </div>
                ${sections.map((s, i) => sectionTableHtml(s, l, multi, i)).join('')}
                ${l.notes ? `<div class="notes"><b>Notes</b>${esc(l.notes)}</div>` : ''}
                ${l.signatures ? `<div class="sigs"><div class="sig"><b>${esc(l.sig_left)}</b><small>${esc(BOOT.user)}</small></div><div class="sig"><b>${esc(l.sig_right)}</b><small>For ${esc(o.name)}</small></div></div>` : ''}
                <div class="doc-foot"><span>${esc(l.footer_note)} | Ref: SRC/RPT/…</span><span>Page 1 of …</span></div>
                ${lh.bands ? '<div class="lh-band bottom"></div>' : ''}`;
        }

        // ── Preview zoom ─────────────────────────────────────────────────
        let zoom = parseFloat(store.get('rb_zoom')) || 1;
        function applyZoom(z) {
            zoom = Math.min(2, Math.max(0.3, Math.round(z * 100) / 100));
            $('rbPaper').style.zoom = zoom;
            $('rbZoomLabel').textContent = Math.round(zoom * 100) + '%';
            store.set('rb_zoom', zoom);
        }
        $('rbZoomIn').addEventListener('click', () => applyZoom(zoom + 0.1));
        $('rbZoomOut').addEventListener('click', () => applyZoom(zoom - 0.1));
        $('rbZoomFit').addEventListener('click', () => {
            const stage = document.querySelector('.rb-stage');
            const available = stage.clientWidth - 48; // stage padding
            const paperWidth = parseFloat($('rbPaper').style.width) || 820; // unzoomed width
            applyZoom(available / paperWidth);
        });
        applyZoom(zoom);

        // ── Events: Data tab ─────────────────────────────────────────────
        $('rbTitle').addEventListener('input', e => { state.config.title = e.target.value; $('rbTitle2').value = e.target.value; changed(); });
        $('rbTitle2').addEventListener('input', e => { state.config.title = e.target.value; $('rbTitle').value = e.target.value; changed(); });

        function switchSource(src) {
            if (!src || src === state.config.source || !state.meta.sources[src]) return;
            const keep = state.config;
            const fresh = withDefaults(blankConfig(src));
            fresh.title = keep.title;
            fresh.subtitle = ''; // the "Report type" line described the old data, so it starts blank
            fresh.layout = Object.assign({}, keep.layout, { summary_only: false });
            ['date_from', 'date_to', 'year_id', 'member_ids', 'search'].forEach(k => { fresh.filters[k] = keep.filters[k]; });
            state.config = fresh;
            changed({ rerender: true });
            toast('Switched to ' + state.meta.sources[src].label + ' — columns were reset to the defaults.');
        }
        $('rbSources').addEventListener('click', e => {
            const btn = e.target.closest('[data-source]');
            if (btn) switchSource(btn.dataset.source);
        });
        $('rbSources').addEventListener('change', e => {
            if (e.target.id === 'rbShowEmpty') {
                ui.showEmpty = e.target.checked;
                store.set('rb_show_empty', ui.showEmpty);
                renderSources();
            }
        });
        $('rbSources').addEventListener('input', e => {
            if (e.target.id !== 'rbSourceSearch') return;
            ui.sourceQuery = e.target.value;
            const pos = e.target.selectionStart;
            renderSources();
            const input = $('rbSourceSearch');
            input.focus();
            input.setSelectionRange(pos, pos);
        });

        $('rbFilters').addEventListener('change', e => {
            const t = e.target;
            const f = state.config.filters;
            const cond = t.closest('[data-cond]');
            if (cond && t.dataset.condprop) {
                const c = f.conditions[+cond.dataset.cond];
                c[t.dataset.condprop] = t.value;
                if (t.dataset.condprop === 'op') renderFilters(); // value boxes depend on the operator
                changed();
                return;
            }
            if (t.dataset.filter) {
                f[t.dataset.filter] = t.dataset.filter === 'year_id' ? Number(t.value) : t.value;
                if (t.dataset.filter.startsWith('date_')) renderFilters();
                changed();
            } else if (t.dataset.nonzero) {
                f.nonzero = [...document.querySelectorAll('[data-nonzero]:checked')].map(x => x.dataset.nonzero);
                changed();
            } else if (t.closest('#rbMemberList')) {
                f.member_ids = [...document.querySelectorAll('#rbMemberList input:checked')].map(x => Number(x.value));
                $('rbMemberCount').textContent = f.member_ids.length ? f.member_ids.length + ' selected' : 'All members';
                changed();
            }
        });
        $('rbFilters').addEventListener('input', e => {
            const cond = e.target.closest('[data-cond]');
            if (cond && (e.target.dataset.condprop === 'value' || e.target.dataset.condprop === 'value2')) {
                state.config.filters.conditions[+cond.dataset.cond][e.target.dataset.condprop] = e.target.value;
                changed();
                return;
            }
            if (e.target.dataset.filter === 'search') { state.config.filters.search = e.target.value; changed(); }
            if (e.target.id === 'rbMemberSearch') {
                const q = e.target.value.trim().toLowerCase();
                document.querySelectorAll('#rbMemberList [data-name]').forEach(l => { l.style.display = l.dataset.name.includes(q) ? '' : 'none'; });
            }
        });
        $('rbFilters').addEventListener('click', e => {
            if (e.target.closest('#rbAddCond')) {
                const first = sortChoices()[0];
                state.config.filters.conditions.push({ field: first[0], op: 'eq', value: '', value2: '' });
                renderFilters();
                changed({ preview: false });
                setTimeout(() => { const v = document.querySelectorAll('#rbConditions [data-condprop="field"]'); v[v.length - 1]?.focus(); }, 0);
                return;
            }
            const delCond = e.target.closest('[data-delcond]');
            if (delCond) {
                state.config.filters.conditions.splice(+delCond.closest('[data-cond]').dataset.cond, 1);
                renderFilters();
                changed();
                return;
            }
            const chip = e.target.closest('[data-range]');
            if (chip) {
                const [from, to] = chip.dataset.range.split('|');
                state.config.filters.date_from = from;
                state.config.filters.date_to = to;
                renderFilters();
                changed();
            }
            const mb = e.target.closest('[data-members]');
            if (mb) {
                const visible = [...document.querySelectorAll('#rbMemberList [data-name]')].filter(l => l.style.display !== 'none');
                visible.forEach(l => { l.querySelector('input').checked = mb.dataset.members === 'all'; });
                state.config.filters.member_ids = [...document.querySelectorAll('#rbMemberList input:checked')].map(x => Number(x.value));
                $('rbMemberCount').textContent = state.config.filters.member_ids.length ? state.config.filters.member_ids.length + ' selected' : 'All members';
                changed();
            }
        });

        // ── Events: Columns tab ──────────────────────────────────────────
        let lastFormulaInput = null;
        $('rbColumns').addEventListener('input', e => {
            const row = e.target.closest('.rb-col');
            const prop = e.target.dataset.col;
            if (!row || !prop) return;
            const col = state.config.columns[+row.dataset.i];
            if (prop === 'width') col.width = Math.max(0.4, Math.min(6, parseFloat(e.target.value) || 1));
            else col[prop] = e.target.value;
            if (prop === 'formula' && col.builder) {
                delete col.builder; // typing by hand takes over from the condition builder
                const btn = row.querySelector('[data-wizard-col]');
                if (btn) btn.innerHTML = '<i class="fas fa-filter"></i> Build with conditions (if/then, or look up any module)';
            }
            if (prop === 'label' && isManual()) renderManual();
            changed();
        });
        $('rbColumns').addEventListener('change', e => {
            const row = e.target.closest('.rb-col');
            const prop = e.target.dataset.col;
            if (!row || !prop || prop === 'label' || prop === 'formula' || prop === 'width') return;
            const col = state.config.columns[+row.dataset.i];
            col[prop] = e.target.value;
            if (prop === 'format') {
                col.align = NUMERIC.includes(col.format) ? 'R' : (col.format === 'date' || col.format === 'datetime' ? 'C' : 'L');
                if (!NUMERIC.includes(col.format) && !['count'].includes(col.total)) col.total = 'none';
                renderColumns();
            }
            changed();
        });
        document.addEventListener('focusin', e => { if (e.target.classList && e.target.classList.contains('rb-formula')) lastFormulaInput = e.target; });
        $('rbColumns').addEventListener('click', e => {
            const row = e.target.closest('.rb-col');
            if (!row) return;
            const i = +row.dataset.i;
            const col = state.config.columns[i];
            const alignBtn = e.target.closest('[data-align]');
            if (alignBtn) { col.align = alignBtn.dataset.align; renderColumns(); changed(); return; }
            const act = e.target.closest('[data-colact]')?.dataset.colact;
            if (act === 'hide') { col.hidden = !col.hidden; renderColumns(); changed(); }
            if (act === 'formula') { col.formula = col.formula ? '' : '{' + (formulaRefs()[0]?.[0] || 'col1') + '}'; if (col.formula && !NUMERIC.includes(col.format)) { col.format = 'amount'; col.align = 'R'; } changed({ rerender: true }); }
            if (act === 'remove') {
                state.config.columns.splice(i, 1);
                if (isManual()) state.config.manual_rows.forEach(r => r.splice(i, 1));
                if (state.config.group_by === col.key) state.config.group_by = '';
                state.config.sort = state.config.sort.filter(s => s.key !== col.key);
                changed({ rerender: true });
            }
        });

        function nextCalcKey() {
            let n = 1;
            while (state.config.columns.some(c => c.key === 'calc_' + n)) n++;
            return 'calc_' + n;
        }

        function newFieldColumn(key) {
            return withDefaults({ source: state.config.source, columns: [{ key }] }).columns[0];
        }
        function removeColumnAt(i) {
            const [col] = state.config.columns.splice(i, 1);
            if (state.config.group_by === col.key) state.config.group_by = '';
            state.config.sort = state.config.sort.filter(s => s.key !== col.key);
        }
        $('rbColPick').addEventListener('change', e => {
            const key = e.target.dataset.pick;
            if (!key) return;
            const i = state.config.columns.findIndex(c => c.key === key);
            if (e.target.checked && i < 0) state.config.columns.push(newFieldColumn(key));
            if (!e.target.checked && i >= 0) removeColumnAt(i);
            renderColumns();
            renderRows();
            fitWideReport();
            changed();
        });
        $('rbColPick').addEventListener('click', e => {
            const act = e.target.closest('[data-colpick]')?.dataset.colpick;
            if (!act) return;
            const keys = [...document.querySelectorAll('#rbColPick [data-pick]')].map(x => x.dataset.pick); // respects the search box
            if (act === 'all') {
                keys.forEach(k => { if (!state.config.columns.some(c => c.key === k)) state.config.columns.push(newFieldColumn(k)); });
                fitWideReport();
            } else {
                keys.forEach(k => { const i = state.config.columns.findIndex(c => c.key === k); if (i >= 0) removeColumnAt(i); });
            }
            renderColumns();
            renderRows();
            changed();
        });
        $('rbColPick').addEventListener('input', e => {
            if (e.target.id !== 'rbColPickSearch') return;
            colPickQuery = e.target.value;
            const pos = e.target.selectionStart;
            renderColPick();
            const input = $('rbColPickSearch');
            input.focus();
            input.setSelectionRange(pos, pos);
        });

        $('rbAddColumn').addEventListener('click', e => {
            const id = e.target.closest('button')?.id;
            const cols = state.config.columns;
            if (id === 'rbTotalAll') {
                let n = 0;
                cols.forEach(c => { if ((c.format === 'amount' || c.format === 'number') && c.total === 'none') { c.total = 'sum'; n++; } });
                renderColumns();
                changed();
                toast(n ? 'Added a total to ' + n + ' column' + (n === 1 ? '' : 's') + '.' : 'Every amount column already has a total.', n ? 'success' : 'info');
                return;
            }
            if (id === 'rbAddFieldBtn') {
                const key = $('rbAddField').value;
                if (key) cols.push(withDefaults({ source: state.config.source, columns: [{ key }] }).columns[0]);
                changed({ rerender: true });
            } else if (id === 'rbAddAll') {
                const used = new Set(cols.map(c => c.key));
                Object.keys(source().fields).filter(k => !used.has(k)).forEach(key => cols.push(withDefaults({ source: state.config.source, columns: [{ key }] }).columns[0]));
                changed({ rerender: true });
            } else if (id === 'rbAddFormula') {
                const refs = formulaRefs();
                cols.push({ key: nextCalcKey(), label: 'Calculated', format: 'amount', align: 'R', width: 1, total: 'sum', formula: refs.length >= 2 ? `{${refs[0][0]}} + {${refs[1][0]}}` : (refs.length ? `{${refs[0][0]}}` : '0'), hidden: false });
                changed({ rerender: true });
                setTimeout(() => { const inputs = document.querySelectorAll('[data-col="formula"]'); inputs[inputs.length - 1]?.focus(); }, 0);
            } else if (id === 'rbResetCols') {
                state.config.columns = withDefaults({ source: state.config.source, columns: defaultColumnsFor(state.config.source) }).columns;
                changed({ rerender: true });
            } else if (id === 'rbAddManualCol') {
                cols.push({ key: 'col' + (cols.length + 1), label: 'Column ' + (cols.length + 1), format: 'text', align: 'L', width: 1, total: 'none', formula: '', hidden: false });
                state.config.manual_rows.forEach(r => r.push(''));
                changed({ rerender: true });
            }
        });

        /**
         * Insert text into the formula being edited: the last focused formula box if it is on screen,
         * otherwise the first one in the open tab; on the Maths tab a new calculation line is created.
         * $caretBack moves the cursor left after inserting (to land inside "ROUND(|)").
         */
        function insertIntoFormula(text, caretBack = 0) {
            const visible = el => el && document.body.contains(el) && el.offsetParent !== null;
            let input = visible(lastFormulaInput) ? lastFormulaInput : [...document.querySelectorAll('.rb-pane.active .rb-formula')].find(visible);
            if (!input) {
                if (document.querySelector('.rb-pane.active')?.dataset.pane === 'maths') {
                    state.config.calculations.push({ label: 'New calculation', formula: '', format: 'amount' });
                    renderMaths();
                    input = [...document.querySelectorAll('[data-calcprop="formula"]')].pop();
                } else {
                    toast('Add a calculated column first, then click to insert.');
                    return;
                }
            }
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? start;
            input.value = input.value.slice(0, start) + text + input.value.slice(end);
            input.focus();
            const caret = start + text.length - caretBack;
            input.setSelectionRange(caret, caret);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        $('rbFormulaHelp').addEventListener('click', e => {
            const chip = e.target.closest('[data-ref]');
            if (chip) insertIntoFormula('{' + chip.dataset.ref + '}');
        });
        $('rbMathFields').addEventListener('click', e => {
            const chip = e.target.closest('[data-ref]');
            if (chip) insertIntoFormula('{' + chip.dataset.ref + '}');
        });
        $('rbFuncList').addEventListener('click', e => {
            const fn = e.target.closest('[data-func]');
            if (!fn) return;
            const name = fn.dataset.func;
            const noArgs = ['TODAY', 'ROWNUM', 'GCOUNT'].includes(name);
            insertIntoFormula(name + '()', noArgs ? 0 : 1);
        });
        $('rbFuncSearch').addEventListener('input', renderFunctions);

        // ── Condition builder ────────────────────────────────────────────
        const AGGS = { sum: 'Sum', avg: 'Average', count: 'Count of rows', min: 'Smallest', max: 'Largest', pct: '% of the overall sum' };
        const valueCache = {};
        let wiz = null;

        const newRule = () => ({ field: '', op: 'eq', vtype: 'value', value: '', value2: '', vfield: '' });

        /** Modules other reports can pull data from, and their fields as {ref.Field} */
        const moduleEntry = ref => Object.entries(state.meta.sources).find(([, s]) => s.ref === ref) || [null, null];
        function moduleFields(ref) {
            const [, s] = moduleEntry(ref);
            return s ? Object.entries(s.fields).map(([k, f]) => [ref + '.' + k, f.label, f.type]) : [];
        }
        function moduleOptions(selected, includeThis) {
            const groups = {};
            Object.values(state.meta.sources).filter(s => s.ref).forEach(s => { (groups[s.module] = groups[s.module] || []).push(s); });
            const order = state.meta.modules.filter(m => groups[m]).concat(Object.keys(groups).filter(m => !state.meta.modules.includes(m)));
            return (includeThis ? `<option value="">this report (${esc(source().label)})</option>` : '')
                + order.map(m => `<optgroup label="${esc(m)}">${groups[m].map(s => `<option value="${esc(s.ref)}"${s.ref === selected ? ' selected' : ''}>${esc(s.label)}${s.rows ? ' (' + s.rows.toLocaleString('en-IN') + ')' : ''}</option>`).join('')}</optgroup>`).join('');
        }
        const isLookup = () => wiz.target.kind === 'calc' || wiz.colMode === 'lookup';

        /** This report's own fields (a column never tests itself) */
        function reportFields() {
            const self = wiz && wiz.target.kind === 'column' ? state.config.columns[wiz.target.index]?.key : null;
            return formulaRefs().filter(([k]) => k !== self);
        }
        /** Fields the conditions test: the chosen module's, or this report's */
        function wizardFields() {
            return isLookup() && wiz.module ? moduleFields(wiz.module) : reportFields();
        }
        const fieldType = key => (wizardFields().concat(reportFields()).find(([k]) => k === key) || [])[2] || 'text';

        function openWizard(kind, index) {
            const item = kind === 'calc' ? state.config.calculations[index] : state.config.columns[index];
            const fields = formulaRefs();
            const firstNum = (fields.find(([, , t]) => NUMERIC.includes(t)) || fields[0] || [''])[0];
            wiz = item.builder ? clone(item.builder) : {
                agg: 'sum', field: firstNum, thenKind: 'field', thenValue: firstNum, elseKind: 'value', elseValue: '0',
                module: '', colMode: 'if', sameRange: false,
                match: 'all', groups: [{ match: 'all', rules: [newRule()] }]
            };
            wiz.target = { kind, index };
            wiz.module = wiz.module || '';
            wiz.colMode = wiz.colMode || 'if';
            wiz.groups = wiz.groups && wiz.groups.length ? wiz.groups : [{ match: 'all', rules: [newRule()] }];
            wiz.groups.forEach(g => g.rules.forEach(r => { if (!r.field) r.field = (wizardFields()[0] || [''])[0]; }));
            // A formula edited by hand in this box is shown again as it was saved
            wiz.edited = !!(wiz.edited && item.formula);
            wiz.editedFormula = wiz.edited ? item.formula : '';
            $('rbWizardTitle').innerHTML = '<i class="fas fa-filter"></i> ' + (kind === 'calc' ? 'Calculation with conditions (from any module)' : 'Calculated column');
            wizKeepEdit = true;
            renderWizard();
            wizKeepEdit = false;
            openModal('rbWizardModal');
        }

        function fieldOptions(selected, onlyNumeric = false) {
            return wizardFields().filter(([, , t]) => !onlyNumeric || NUMERIC.includes(t))
                .map(([k, l]) => `<option value="${esc(k)}"${k === selected ? ' selected' : ''}>${esc(l)}</option>`).join('');
        }

        function valueInput(rule, gi, ri, which) {
            const t = fieldType(rule.field);
            const type = (t === 'date' || t === 'datetime') ? 'date' : (NUMERIC.includes(t) ? 'number' : 'text');
            const prop = which === 2 ? 'value2' : 'value';
            const list = type === 'text' ? ` list="rbVals_${gi}_${ri}"` : '';
            return `<input type="${type}"${type === 'number' ? ' step="any"' : ''} class="rb-input" data-wz="${prop}" data-g="${gi}" data-r="${ri}" value="${esc(rule[prop])}" placeholder="${which === 2 ? 'to' : (rule.op === 'in' ? 'a, b, c' : (rule.op === 'between' ? 'from' : 'value'))}"${list}>`;
        }

        function ruleHtml(rule, gi, ri, groupMatch) {
            const noValue = rule.op === 'empty' || rule.op === 'not_empty';
            const canCompareField = !['in', 'between', 'empty', 'not_empty'].includes(rule.op);
            if (!canCompareField) rule.vtype = 'value';
            let valueCell = '<span></span>';
            if (!noValue) {
                if (rule.vtype === 'field') {
                    // In a column lookup, a module field can be matched with this row's field (e.g. Member ID)
                    const opts = (wiz.target.kind === 'column' && wiz.module)
                        ? `<optgroup label="This row of the report">${reportFields().map(([k, l]) => `<option value="${esc(k)}"${k === rule.vfield ? ' selected' : ''}>${esc(l)}</option>`).join('')}</optgroup>
                           <optgroup label="${esc(moduleEntry(wiz.module)[1]?.label || wiz.module)}">${fieldOptions(rule.vfield)}</optgroup>`
                        : fieldOptions(rule.vfield);
                    valueCell = `<select class="rb-select" data-wz="vfield" data-g="${gi}" data-r="${ri}">${opts}</select>`;
                } else if (rule.op === 'between') {
                    valueCell = `<div class="rb-pair">${valueInput(rule, gi, ri, 1)}${valueInput(rule, gi, ri, 2)}</div>`;
                } else {
                    valueCell = valueInput(rule, gi, ri, 1);
                }
            }
            return `${ri > 0 ? `<div class="rb-wz-rule-join">${groupMatch === 'all' ? 'AND' : 'OR'}</div>` : ''}
                <div class="rb-wz-rule">
                    <select class="rb-select" data-wz="field" data-g="${gi}" data-r="${ri}" aria-label="Field">${fieldOptions(rule.field)}</select>
                    <select class="rb-select" data-wz="op" data-g="${gi}" data-r="${ri}" aria-label="Condition">${Object.entries(OPS).map(([k, l]) => `<option value="${k}"${rule.op === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
                    <select class="rb-select" data-wz="vtype" data-g="${gi}" data-r="${ri}" aria-label="Compare with"${noValue || !canCompareField ? ' disabled' : ''}>
                        <option value="value"${rule.vtype !== 'field' ? ' selected' : ''}>a value</option>
                        <option value="field"${rule.vtype === 'field' ? ' selected' : ''}>a field</option>
                    </select>
                    ${valueCell}
                    <button type="button" class="rb-icon danger" data-wzdel="${gi}:${ri}" title="Remove condition"><i class="fas fa-times"></i></button>
                </div>
                <datalist id="rbVals_${gi}_${ri}">${(valueCache[suggestionKey(rule.field)[2]] || []).map(v => `<option value="${esc(v)}">`).join('')}</datalist>`;
        }

        function renderWizard() {
            const isCalc = wiz.target.kind === 'calc';
            const numericFields = wizardFields().filter(([, , t]) => NUMERIC.includes(t)).map(([k]) => k);
            if (isLookup() && !numericFields.includes(wiz.field)) wiz.field = numericFields[0] || '';
            const thenElse = (prefix, kind, value) => `
                <select class="rb-select" data-wz="${prefix}Kind">
                    <option value="field"${kind === 'field' ? ' selected' : ''}>the field</option>
                    <option value="value"${kind === 'value' ? ' selected' : ''}>the value</option>
                    <option value="blank"${kind === 'blank' ? ' selected' : ''}>nothing (blank)</option>
                </select>
                ${kind === 'field' ? `<select class="rb-select" data-wz="${prefix}Value">${fieldOptions(value)}</select>`
                    : kind === 'value' ? `<input type="text" class="rb-input" data-wz="${prefix}Value" value="${esc(value)}" placeholder="number or text" style="max-width:150px">` : ''}`;
            const dateKey = wiz.module ? moduleEntry(wiz.module)[1]?.date_key : null;
            const lookupHead = `<div class="rb-wz-head"><b>${isCalc ? 'Calculate' : 'Show'}</b>
                        <select class="rb-select" data-wz="agg">${Object.entries(AGGS).map(([k, l]) => `<option value="${k}"${wiz.agg === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
                        ${wiz.agg === 'count' ? '' : `<b>of</b> <select class="rb-select" data-wz="aggField">${fieldOptions(wiz.field, true)}</select>`}
                        <b>from</b> <select class="rb-select" data-wz="module" style="min-width:200px">${moduleOptions(wiz.module, isCalc)}</select>
                        <b>for the rows where…</b>
                        ${dateKey ? `<label class="rb-check" style="width:100%;margin-top:4px"><input type="checkbox" data-wz="sameRange"${wiz.sameRange ? ' checked' : ''}> Only rows inside the report's date range (step 1)</label>` : ''}
                        ${!isCalc ? '<p class="rb-hint" style="width:100%;margin:4px 0 0">To match each row, add a condition such as <b>Member ID</b> equals <b>a field</b> → <b>Member ID</b> (this row).</p>' : ''}
                    </div>`;
            const colModeSwitch = isCalc ? '' : `<div class="rb-wz-top"><b>This column should</b>
                    <select class="rb-select" data-wz="colMode">
                        <option value="if"${wiz.colMode !== 'lookup' ? ' selected' : ''}>show a value: if … then … else</option>
                        <option value="lookup"${wiz.colMode === 'lookup' ? ' selected' : ''}>look up a total from another module</option>
                    </select></div>`;
            const head = colModeSwitch + (isLookup()
                ? lookupHead
                : `<div class="rb-wz-head"><b>For each row: when the conditions below match, show</b> ${thenElse('then', wiz.thenKind, wiz.thenValue)}
                        <b>otherwise show</b> ${thenElse('else', wiz.elseKind, wiz.elseValue)}</div>`);
            const groups = wiz.groups.map((g, gi) => `
                ${gi > 0 ? `<div class="rb-wz-join">${wiz.match === 'all' ? 'AND' : 'OR'}</div>` : ''}
                <div class="rb-wz-group">
                    <div class="rb-wz-group-head">
                        <span>Group ${gi + 1}: match</span>
                        <select class="rb-select" data-wz="gmatch" data-g="${gi}"><option value="all"${g.match === 'all' ? ' selected' : ''}>all (AND)</option><option value="any"${g.match === 'any' ? ' selected' : ''}>any (OR)</option></select>
                        <span class="rb-grow">of these conditions</span>
                        ${wiz.groups.length > 1 ? `<button type="button" class="rb-mini" data-wzdelgroup="${gi}"><i class="fas fa-trash"></i> Remove group</button>` : ''}
                    </div>
                    ${g.rules.map((r, ri) => ruleHtml(r, gi, ri, g.match)).join('') || '<p class="rb-hint" style="margin:0 0 6px">No conditions in this group.</p>'}
                    <button type="button" class="rb-mini" data-wzaddrule="${gi}"><i class="fas fa-plus"></i> Add condition</button>
                </div>`).join('');
            $('rbWizardBody').innerHTML = head + `
                ${wiz.groups.length > 1 ? `<div class="rb-wz-top">Rows must match <select class="rb-select" data-wz="match"><option value="all"${wiz.match === 'all' ? ' selected' : ''}>all groups (AND)</option><option value="any"${wiz.match === 'any' ? ' selected' : ''}>any group (OR)</option></select></div>` : ''}
                ${groups}
                <button type="button" class="rb-mini" id="rbWzAddGroup"><i class="fas fa-layer-group"></i> Add a group (for mixing AND with OR)</button>
                <div class="rb-wz-formula"><label class="lbl" for="rbWzFormula">Formula that will be used (you can edit it here)</label><textarea id="rbWzFormula" rows="2" spellcheck="false" aria-label="Formula"></textarea><span class="rb-wz-edited" id="rbWzEdited" hidden>Edited by hand – changing a condition above will rebuild the formula.</span></div>
                <div class="rb-wz-result" id="rbWzResult"></div>`;
            updateWizardFormula();
            loadSuggestions();
        }

        /** Suggest real values for text fields used in the conditions */
        /** [source key, plain field, cache key] for suggesting values of a (possibly module.) field */
        function suggestionKey(field) {
            const dot = (field || '').indexOf('.');
            const src = dot > 0 ? moduleEntry(field.slice(0, dot))[0] : state.config.source;
            const plain = dot > 0 ? field.slice(dot + 1) : field;
            return [src, plain, src + '|' + plain];
        }

        function loadSuggestions() {
            wiz.groups.forEach(g => g.rules.forEach(r => {
                const [src, plain, key] = suggestionKey(r.field);
                if (!src || src === 'manual' || !r.field || fieldType(r.field) !== 'text' || plain.startsWith('calc_') || valueCache[key]) return;
                valueCache[key] = [];
                api('values', { source: src, field: plain }).then(d => {
                    valueCache[key] = d.values;
                    document.querySelectorAll('datalist[id^="rbVals_"]').forEach(dl => {
                        const [, gi, ri] = dl.id.split('_');
                        const rule = wiz?.groups[+gi]?.rules[+ri];
                        if (rule && rule.field === r.field) dl.innerHTML = d.values.map(v => `<option value="${esc(v)}">`).join('');
                    });
                }).catch(() => {});
            }));
        }

        function lit(v, type) {
            const s = String(v ?? '').trim();
            const plain = s.replace(/,/g, '');
            if ((NUMERIC.includes(type) || type === 'number') && plain !== '' && !isNaN(plain)) return plain;
            if (type === 'auto' && plain !== '' && !isNaN(plain)) return plain;
            if (!s.includes('"')) return '"' + s + '"';
            if (!s.includes("'")) return "'" + s + "'";
            return '"' + s.replace(/"/g, '') + '"';
        }

        function ruleExpr(r) {
            if (!r.field) return '';
            const F = '{' + r.field + '}';
            const t = fieldType(r.field);
            const R = r.vtype === 'field' && r.vfield ? '{' + r.vfield + '}' : lit(r.value, t);
            switch (r.op) {
                case 'eq': return `${F} = ${R}`;
                case 'ne': return `${F} <> ${R}`;
                case 'gt': return `${F} > ${R}`;
                case 'gte': return `${F} >= ${R}`;
                case 'lt': return `${F} < ${R}`;
                case 'lte': return `${F} <= ${R}`;
                case 'contains': return `CONTAINS(${F}, ${R})`;
                case 'not_contains': return `NOT(CONTAINS(${F}, ${R}))`;
                case 'starts': return `STARTSWITH(${F}, ${R})`;
                case 'ends': return `ENDSWITH(${F}, ${R})`;
                case 'empty': return `ISBLANK(${F})`;
                case 'not_empty': return `NOT(ISBLANK(${F}))`;
                case 'in': {
                    const items = String(r.value).split(',').map(x => x.trim()).filter(Boolean);
                    return items.length ? `IN(${F}, ${items.map(x => lit(x, t)).join(', ')})` : '';
                }
                case 'between': return `BETWEEN(${F}, ${lit(r.value, t)}, ${lit(r.value2, t)})`;
            }
            return '';
        }

        function conditionExpr() {
            const join = (parts, match) => parts.length === 1 ? parts[0] : `${match === 'all' ? 'AND' : 'OR'}(${parts.join(', ')})`;
            const groups = wiz.groups.map(g => {
                const parts = g.rules.map(ruleExpr).filter(Boolean);
                return parts.length ? join(parts, g.match) : '';
            }).filter(Boolean);
            const C = groups.length ? join(groups, wiz.match) : '';
            // "Only rows inside the report's date range" always applies on top of the other conditions
            const dateKey = isLookup() && wiz.module && wiz.sameRange ? moduleEntry(wiz.module)[1]?.date_key : null;
            if (!dateKey) return C;
            const range = `BETWEEN({${wiz.module}.${dateKey}}, DATEFROM(), DATETO())`;
            return C ? `AND(${C}, ${range})` : range;
        }

        function wizardFormula() {
            const C = conditionExpr();
            if (isLookup()) {
                const F = '{' + wiz.field + '}';
                const fn = { sum: 'SUM', avg: 'AVG', min: 'MIN', max: 'MAX' }[wiz.agg];
                if (wiz.agg === 'count') {
                    if (C) return `COUNTIF(${C})`;
                    // Counting another module's rows: count its first field (normally the ID, never blank)
                    return wiz.module ? `COUNT({${(moduleFields(wiz.module)[0] || [''])[0]}})` : 'COUNT()';
                }
                if (wiz.agg === 'pct') return C ? `PCT(SUMIF(${F}, ${C}), SUM(${F}))` : `PCT(SUM(${F}), SUM(${F}))`;
                return C ? `${fn}IF(${F}, ${C})` : `${fn}(${F})`;
            }
            const side = (kind, value) => kind === 'field' ? '{' + value + '}' : (kind === 'blank' ? '""' : lit(value, 'auto'));
            const thenX = side(wiz.thenKind, wiz.thenValue);
            return C ? `IF(${C}, ${thenX}, ${side(wiz.elseKind, wiz.elseValue)})` : thenX;
        }

        let wizPreviewTimer = null;
        let wizKeepEdit = false;
        /** The formula the wizard will use: the box's text when edited by hand, otherwise built from the conditions */
        const wizCurrentFormula = () => (wiz.edited && wiz.editedFormula.trim()) ? wiz.editedFormula.trim() : wizardFormula();

        function updateWizardFormula() {
            // Changing a condition rebuilds the formula; only reopening the wizard keeps a hand-edited one
            if (!(wizKeepEdit && wiz.edited)) {
                wiz.edited = false;
                wiz.editedFormula = '';
                $('rbWzFormula').value = wizardFormula();
            } else {
                $('rbWzFormula').value = wiz.editedFormula;
            }
            $('rbWzEdited').hidden = !wiz.edited;
            showWizardResult();
        }

        function showWizardResult() {
            const formula = wizCurrentFormula();
            clearTimeout(wizPreviewTimer);
            if (wiz.target.kind !== 'calc') { $('rbWzResult').textContent = ''; return; }
            // Work the answer out now, over the report's current rows
            wizPreviewTimer = setTimeout(async () => {
                try {
                    const cfg = clone(state.config);
                    delete cfg.sections; // evaluate just the active section with this one formula
                    cfg.calculations = [{ label: 'Result', formula, format: wiz.agg === 'count' ? 'integer' : (wiz.agg === 'pct' ? 'percent' : 'amount') }];
                    const data = await api('preview', { config: cfg });
                    const sec0 = (data.sections && data.sections[0]) || data;
                    const msg = (sec0.issues || data.issues).calculations[0];
                    const scope = wiz.module
                        ? 'from the ' + (moduleEntry(wiz.module)[1]?.label || wiz.module) + ' module' + (wiz.sameRange ? ', inside the report\'s dates' : ', all of its rows')
                        : 'over the ' + data.row_count.toLocaleString('en-IN') + ' rows currently in the report, after the filters in step 1';
                    $('rbWzResult').innerHTML = msg ? '<span style="color:#dc2626">' + esc(msg) + '</span>' : 'Result: <b>' + esc(data.calculations[0]?.value ?? '-') + '</b> <span style="color:var(--text-muted)">(' + esc(scope) + ')</span>';
                } catch (e) {
                    $('rbWzResult').textContent = '';
                }
            }, 300);
        }

        $('rbWizardBody').addEventListener('change', e => {
            const t = e.target;
            const prop = t.dataset.wz;
            if (!prop) return;
            const rule = t.dataset.r !== undefined ? wiz.groups[+t.dataset.g].rules[+t.dataset.r] : null;
            if (rule) {
                rule[prop] = t.value;
                if (prop === 'field') { rule.value = ''; rule.value2 = ''; }
                if (['field', 'op', 'vtype'].includes(prop)) { renderWizard(); return; }
            } else if (prop === 'gmatch') {
                wiz.groups[+t.dataset.g].match = t.value;
                renderWizard();
                return;
            } else if (prop === 'module' || prop === 'colMode') {
                // A different module (or column mode) means different fields: start the conditions afresh
                wiz[prop] = t.value;
                if (prop === 'module') wiz.sameRange = false;
                const first = (wizardFields()[0] || [''])[0];
                wiz.field = (wizardFields().find(([, , ft]) => NUMERIC.includes(ft)) || [''])[0];
                wiz.groups = [{ match: 'all', rules: [Object.assign(newRule(), { field: first })] }];
                wiz.match = 'all';
                renderWizard();
                return;
            } else if (prop === 'sameRange') {
                wiz.sameRange = t.checked;
            } else {
                const map = { agg: 'agg', aggField: 'field', match: 'match', thenKind: 'thenKind', elseKind: 'elseKind', thenValue: 'thenValue', elseValue: 'elseValue' };
                wiz[map[prop]] = t.value;
                if (prop === 'thenKind' || prop === 'elseKind') {
                    const which = prop === 'thenKind' ? 'thenValue' : 'elseValue';
                    wiz[which] = t.value === 'field' ? (wizardFields()[0] || [''])[0] : (t.value === 'value' ? '0' : '');
                }
                if (['agg', 'match', 'thenKind', 'elseKind'].includes(prop)) { renderWizard(); return; }
            }
            updateWizardFormula();
        });
        $('rbWizardBody').addEventListener('input', e => {
            const t = e.target;
            if (t.id === 'rbWzFormula') {
                // Typed by hand: use exactly this text (the conditions above stay as they are)
                wiz.edited = t.value.trim() !== wizardFormula().trim();
                wiz.editedFormula = wiz.edited ? t.value : '';
                $('rbWzEdited').hidden = !wiz.edited;
                showWizardResult();
                return;
            }
            if (!t.dataset.wz || t.tagName === 'SELECT' || t.type === 'checkbox') return;
            if (t.dataset.r !== undefined) wiz.groups[+t.dataset.g].rules[+t.dataset.r][t.dataset.wz] = t.value;
            else wiz[t.dataset.wz] = t.value;
            updateWizardFormula();
        });
        $('rbWizardBody').addEventListener('click', e => {
            const add = e.target.closest('[data-wzaddrule]');
            if (add) {
                const r = newRule();
                r.field = (wizardFields()[0] || [''])[0];
                wiz.groups[+add.dataset.wzaddrule].rules.push(r);
                renderWizard();
                return;
            }
            const del = e.target.closest('[data-wzdel]');
            if (del) {
                const [gi, ri] = del.dataset.wzdel.split(':').map(Number);
                wiz.groups[gi].rules.splice(ri, 1);
                renderWizard();
                return;
            }
            const delGroup = e.target.closest('[data-wzdelgroup]');
            if (delGroup) {
                wiz.groups.splice(+delGroup.dataset.wzdelgroup, 1);
                renderWizard();
                return;
            }
            if (e.target.closest('#rbWzAddGroup')) {
                const r = newRule();
                r.field = (wizardFields()[0] || [''])[0];
                wiz.groups.push({ match: 'all', rules: [r] });
                if (wiz.groups.length === 2) wiz.match = 'any'; // the usual reason for a second group is an OR
                renderWizard();
            }
        });
        $('rbWizardApply').addEventListener('click', () => {
            const formula = wizCurrentFormula();
            const builder = clone(wiz);
            delete builder.target;
            delete builder.editedFormula; // the edited text itself is the saved formula
            builder.edited = !!wiz.edited;
            if (wiz.target.kind === 'calc') {
                const c = state.config.calculations[wiz.target.index];
                c.formula = formula;
                c.builder = builder;
                if (wiz.agg === 'count') c.format = 'integer';
                else if (wiz.agg === 'pct') c.format = 'percent';
                else if (c.format === 'integer' || c.format === 'percent' || c.format === 'text') c.format = 'amount';
                if (!c.label || c.label === 'New calculation') {
                    const moduleLabel = wiz.module ? ' — ' + (moduleEntry(wiz.module)[1]?.label || wiz.module) : '';
                    c.label = AGGS[wiz.agg] + (wiz.agg === 'count' ? '' : ' of ' + (wizardFields().find(([k]) => k === wiz.field)?.[1] || wiz.field)) + moduleLabel + (conditionExpr() ? ' (filtered)' : '');
                }
            } else if (isLookup()) {
                const col = state.config.columns[wiz.target.index];
                col.formula = formula;
                col.builder = builder;
                col.format = wiz.agg === 'count' ? 'integer' : (wiz.agg === 'pct' ? 'percent' : 'amount');
                col.align = 'R';
                if (!col.label || col.label === 'Calculated') {
                    col.label = AGGS[wiz.agg] + (wiz.agg === 'count' ? '' : ' of ' + (wizardFields().find(([k]) => k === wiz.field)?.[1] || wiz.field));
                }
            } else {
                const col = state.config.columns[wiz.target.index];
                col.formula = formula;
                col.builder = builder;
                const textResult = [wiz.thenKind, wiz.elseKind].some((k, i) => k === 'value' && isNaN(String([wiz.thenValue, wiz.elseValue][i]).replace(/,/g, '')))
                    || [['thenKind', 'thenValue'], ['elseKind', 'elseValue']].some(([k, v]) => wiz[k] === 'field' && !NUMERIC.includes(fieldType(wiz[v])));
                if (textResult) { col.format = 'text'; col.align = 'L'; if (col.total !== 'count') col.total = 'none'; }
            }
            closeModal('rbWizardModal');
            changed({ rerender: true });
            toast('Formula built from your conditions.', 'success');
        });
        $('rbCalcs').addEventListener('click', e => {
            const b = e.target.closest('[data-wizard-calc]');
            if (b) openWizard('calc', +b.dataset.wizardCalc);
        });
        $('rbColumns').addEventListener('click', e => {
            const b = e.target.closest('[data-wizard-col]');
            if (b) openWizard('column', +b.dataset.wizardCol);
        });

        // ── Events: Maths tab ────────────────────────────────────────────
        $('rbCalcs').addEventListener('input', e => {
            const line = e.target.closest('[data-calc]');
            const prop = e.target.dataset.calcprop;
            if (!line || !prop || prop === 'format') return;
            const calc = state.config.calculations[+line.dataset.calc];
            calc[prop] = e.target.value;
            if (prop === 'formula') {
                // Typing by hand takes over from the condition builder
                if (calc.builder) {
                    delete calc.builder;
                    const btn = line.querySelector('[data-wizard-calc]');
                    if (btn) btn.innerHTML = '<i class="fas fa-filter"></i> Build with conditions';
                }
                line.querySelector('.rb-calc-hint')?.remove();
            }
            changed();
        });
        $('rbCalcs').addEventListener('change', e => {
            const line = e.target.closest('[data-calc]');
            if (!line || e.target.dataset.calcprop !== 'format') return;
            state.config.calculations[+line.dataset.calc].format = e.target.value;
            changed();
        });
        $('rbCalcs').addEventListener('click', e => {
            const del = e.target.closest('[data-delcalc]');
            if (!del) return;
            const i = +del.closest('[data-calc]').dataset.calc;
            state.config.calculations.splice(i, 1);
            lastCalcValues.splice(i, 1);
            renderMaths();
            changed();
        });
        function addCalculation(label, formula, format) {
            state.config.calculations.push({ label, formula, format });
            renderMaths();
            changed();
            setTimeout(() => [...document.querySelectorAll('[data-calcprop="formula"]')].pop()?.focus(), 0);
        }
        $('rbAddCalc').addEventListener('click', () => addCalculation('', '', 'amount'));
        $('rbQuickCalc').addEventListener('change', e => {
            if (!e.target.value) return;
            const [formula, label, format] = e.target.value.split('|');
            e.target.value = '';
            addCalculation(label, formula, format);
        });
        $('rbExamples').addEventListener('click', e => {
            const ex = e.target.closest('[data-example]');
            if (ex) addCalculation(ex.dataset.label, ex.dataset.example, ex.dataset.format);
        });

        // ── Events: Rows tab ─────────────────────────────────────────────
        $('rbManualSection').addEventListener('input', e => {
            const cell = e.target.dataset.cell;
            if (cell === undefined) return;
            const r = +e.target.closest('tr').dataset.r;
            state.config.manual_rows[r][+cell] = e.target.value;
            changed();
        });
        $('rbManualSection').addEventListener('keydown', e => {
            if (e.key !== 'Enter' || e.target.dataset.cell === undefined) return;
            e.preventDefault();
            const tr = e.target.closest('tr');
            const r = +tr.dataset.r;
            if (r === state.config.manual_rows.length - 1) {
                addManualRow();
            }
            setTimeout(() => document.querySelector(`#rbManualSection tr[data-r="${r + 1}"] input[data-cell="${e.target.dataset.cell}"]`)?.focus(), 0);
        });
        function addManualRow() {
            state.config.manual_rows.push(state.config.columns.map(() => ''));
            renumberManual();
            changed();
            renderManual();
        }
        $('rbManualSection').addEventListener('click', e => {
            const del = e.target.closest('[data-delrow]');
            if (del) { state.config.manual_rows.splice(+del.dataset.delrow, 1); renumberManual(); changed(); renderManual(); return; }
            const id = e.target.closest('button')?.id;
            if (id === 'rbAddRow') addManualRow();
            if (id === 'rbPasteRows') openModal('rbPasteModal');
            if (id === 'rbClearRows' && confirm('Remove all rows from this table?')) { state.config.manual_rows = []; changed(); renderManual(); }
        });
        $('rbPasteConfirm').addEventListener('click', () => {
            const lines = $('rbPasteText').value.replace(/\r/g, '').split('\n').filter(l => l.trim() !== '');
            if (!lines.length) { toast('Paste some rows first.', 'error'); return; }
            const rows = lines.map(l => l.split(l.includes('\t') ? '\t' : ','));
            if ($('rbPasteHeader').checked) {
                const header = rows.shift();
                header.forEach((h, i) => {
                    if (i >= state.config.columns.length) {
                        state.config.columns.push({ key: 'col' + (i + 1), label: h.trim() || 'Column ' + (i + 1), format: 'text', align: 'L', width: 1, total: 'none', formula: '', hidden: false });
                        state.config.manual_rows.forEach(r => r.push(''));
                    } else if (h.trim()) {
                        state.config.columns[i].label = h.trim();
                    }
                });
            }
            const width = state.config.columns.length;
            const mapped = rows.map(r => Array.from({ length: width }, (_, i) => (r[i] ?? '').trim()));
            // Drop empty starter rows (only a serial number) so pasted rows follow on directly
            const kept = state.config.manual_rows.filter(r => r.slice(1).some(v => String(v ?? '').trim() !== ''));
            state.config.manual_rows = $('rbPasteReplace').checked ? mapped : kept.concat(mapped);
            renumberManual();
            closeModal('rbPasteModal');
            $('rbPasteText').value = '';
            changed({ rerender: true });
            toast(mapped.length + ' row' + (mapped.length === 1 ? '' : 's') + ' added.', 'success');
        });

        $('rbSort').addEventListener('change', e => {
            const rule = e.target.closest('[data-sort]');
            if (!rule) return;
            const s = state.config.sort[+rule.dataset.sort];
            if (e.target.hasAttribute('data-sortkey')) s.key = e.target.value;
            if (e.target.hasAttribute('data-sortdir')) s.dir = e.target.value;
            changed();
        });
        $('rbSort').addEventListener('click', e => {
            const del = e.target.closest('[data-delsort]');
            if (del) { state.config.sort.splice(+del.closest('[data-sort]').dataset.sort, 1); renderRows(); changed(); }
        });
        $('rbAddSort').addEventListener('click', () => {
            const first = sortChoices()[0];
            if (!first) return;
            state.config.sort.push({ key: first[0], dir: 'asc' });
            renderRows();
            changed();
        });
        $('rbGroupBy').addEventListener('change', e => {
            state.config.group_by = e.target.value;
            if (!e.target.value) state.config.layout.summary_only = false;
            renderRows();
            changed();
        });

        // ── Events: Layout tab ───────────────────────────────────────────
        document.querySelectorAll('[data-layout]').forEach(el => el.addEventListener('change', () => {
            state.config.layout[el.dataset.layout] = el.checked;
            // A summary line only shows totals, so make sure amount columns have one
            if (el.dataset.layout === 'summary_only' && el.checked && !state.config.columns.some(c => c.total !== 'none')) {
                let added = 0;
                state.config.columns.forEach(c => { if (c.format === 'amount' || c.format === 'number') { c.total = 'sum'; added++; } });
                if (added) {
                    renderColumns();
                    toast('Turned on Sum for ' + added + ' amount column' + (added === 1 ? '' : 's') + ' so each group line shows its total.');
                }
            }
            if (el.dataset.layout === 'signatures') $('rbSigLabels').style.display = el.checked ? '' : 'none';
            syncLayoutSwitches();
            changed();
        }));
        $('rbOrientation').addEventListener('click', e => {
            const b = e.target.closest('[data-v]');
            if (!b) return;
            state.config.layout.orientation = b.dataset.v;
            renderLayout();
            changed();
        });
        $('rbFontSize').addEventListener('input', e => { state.config.layout.font_size = parseFloat(e.target.value); $('rbFontSizeLabel').textContent = e.target.value; changed(); });
        $('rbDateFormat').addEventListener('change', e => { state.config.layout.date_format = e.target.value; changed(); });
        [['rbSubtitle', null, 'subtitle'], ['rbSigLeft', 'sig_left'], ['rbSigRight', 'sig_right'], ['rbNotes', 'notes'], ['rbFooterNote', 'footer_note']].forEach(([id, layoutKey, rootKey]) => {
            $(id).addEventListener('input', e => {
                if (rootKey) state.config[rootKey] = e.target.value;
                else state.config.layout[layoutKey] = e.target.value;
                changed();
            });
        });

        // ── Tabs ─────────────────────────────────────────────────────────
        document.querySelectorAll('.rb-tab').forEach(tab => tab.addEventListener('click', () => {
            document.querySelectorAll('.rb-tab').forEach(t => t.classList.toggle('active', t === tab));
            document.querySelectorAll('.rb-pane').forEach(p => p.classList.toggle('active', p.dataset.pane === tab.dataset.tab));
        }));

        // ── Modals ───────────────────────────────────────────────────────
        function openModal(id) {
            $(id).classList.add('show');
            setTimeout(() => $(id).querySelector('input:not([type=checkbox]), textarea, button.rb-preset')?.focus(), 30);
        }
        function closeModal(id) { $(id).classList.remove('show'); }
        document.querySelectorAll('.rb-modal').forEach(m => {
            m.addEventListener('mousedown', e => { if (e.target === m) closeModal(m.id); });
            m.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => closeModal(m.id)));
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.rb-modal.show').forEach(m => closeModal(m.id));
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(false); }
        });

        async function showOpen() {
            $('rbPresetList').innerHTML = state.meta.presets.map((p, i) => `
                <button type="button" class="rb-preset" data-preset="${i}">
                    <i class="fas ${state.meta.sources[p.source]?.icon || 'fa-file-alt'}"></i>
                    <b>${esc(p.name)}</b><span>${esc(state.meta.sources[p.source].label)}</span>
                </button>`).join('') + `
                <button type="button" class="rb-preset" data-blank="1"><i class="fas fa-plus-circle"></i><b>Blank report</b><span>Start from scratch</span></button>`;
            openModal('rbOpenModal');
            await refreshSaved();
        }

        let savedCache = [];
        async function refreshSaved() {
            try {
                savedCache = (await api('templates')).templates;
            } catch (e) {
                $('rbSavedList').innerHTML = `<div class="rb-empty">${esc(e.message)}</div>`;
                return;
            }
            renderSaved();
        }
        function renderSaved() {
            const q = $('rbSavedSearch').value.trim().toLowerCase();
            const list = savedCache.filter(t => !q || (t.Name + ' ' + (t.Description || '')).toLowerCase().includes(q));
            $('rbSavedList').innerHTML = list.length ? list.map(t => `
                <div class="rb-saved" data-id="${t.TemplateID}">
                    <i class="fas ${state.meta.sources[t.Source]?.icon || 'fa-file-alt'}" style="color:var(--rb-gold)"></i>
                    <div class="rb-grow" data-open="${t.TemplateID}"><b>${esc(t.Name)}</b><span>${esc(t.Description || state.meta.sources[t.Source]?.label || '')} · updated ${esc(new Date(t.UpdatedAt.replace(' ', 'T')).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }))}</span></div>
                    <button type="button" class="rb-icon pin${+t.IsPinned ? ' on' : ''}" data-pin="${t.TemplateID}" title="${+t.IsPinned ? 'Unpin' : 'Pin to top'}"><i class="fas fa-thumbtack"></i></button>
                    <a class="rb-icon" href="generate-custom-report.php?template_id=${t.TemplateID}&format=pdf" target="_blank" title="Generate PDF now" style="display:grid;place-items:center"><i class="fas fa-file-pdf"></i></a>
                    <button type="button" class="rb-icon danger" data-del="${t.TemplateID}" title="Delete"><i class="fas fa-trash"></i></button>
                </div>`).join('') : `<div class="rb-empty">${savedCache.length ? 'No saved reports match your search.' : 'No saved reports yet. Design a report and click Save.'}</div>`;
        }
        $('rbSavedSearch').addEventListener('input', renderSaved);

        function confirmDiscard() {
            return !state.dirty || confirm('You have unsaved changes. Discard them?');
        }

        $('rbPresetList').addEventListener('click', e => {
            const b = e.target.closest('.rb-preset');
            if (!b || !confirmDiscard()) return;
            if (b.dataset.blank) load(blankConfig('transactions'));
            else load(state.meta.presets[+b.dataset.preset].config);
            closeModal('rbOpenModal');
        });
        $('rbSavedList').addEventListener('click', async e => {
            const open = e.target.closest('[data-open]');
            const pin = e.target.closest('[data-pin]');
            const del = e.target.closest('[data-del]');
            try {
                if (open) {
                    if (!confirmDiscard()) return;
                    await openTemplate(+open.dataset.open);
                    closeModal('rbOpenModal');
                } else if (pin) {
                    await api('pin', { id: +pin.dataset.pin });
                    await refreshSaved();
                } else if (del) {
                    const t = savedCache.find(x => x.TemplateID == del.dataset.del);
                    if (!confirm('Delete the saved report "' + (t ? t.Name : '') + '"? This cannot be undone.')) return;
                    await api('delete', { id: +del.dataset.del });
                    if (state.templateId === +del.dataset.del) { state.templateId = 0; state.name = ''; setDirty(true); }
                    toast('Saved report deleted.', 'success');
                    await refreshSaved();
                }
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        async function openTemplate(id) {
            const t = (await api('template', undefined, '&id=' + id)).template;
            load(t.Config, { templateId: +t.TemplateID, name: t.Name, description: t.Description || '' });
            history.replaceState(null, '', 'report-builder.php?template=' + t.TemplateID);
            toast('Opened "' + t.Name + '".', 'success');
        }

        // ── Save ─────────────────────────────────────────────────────────
        let saveAsNew = false;
        function save(asNew) {
            saveAsNew = asNew;
            if (state.templateId && !asNew) { doSave(state.name, state.description); return; }
            $('rbSaveName').value = asNew && state.name ? state.name + ' (copy)' : (state.name || state.config.title);
            $('rbSaveDesc').value = state.description;
            openModal('rbSaveModal');
        }
        async function doSave(name, description) {
            if (!name.trim()) { toast('Give the report a name.', 'error'); return; }
            try {
                const res = await api('save', { id: saveAsNew ? 0 : state.templateId, name, description, config: fullConfig() });
                state.templateId = res.id;
                state.name = res.name;
                state.description = description;
                setDirty(false);
                closeModal('rbSaveModal');
                history.replaceState(null, '', 'report-builder.php?template=' + res.id);
                toast('Saved "' + res.name + '".', 'success');
            } catch (e) {
                toast(e.message, 'error');
            }
        }
        $('rbSaveBtn').addEventListener('click', () => save(false));
        $('rbSaveAsBtn').addEventListener('click', () => save(true));
        $('rbSaveConfirm').addEventListener('click', () => doSave($('rbSaveName').value, $('rbSaveDesc').value));
        $('rbSaveName').addEventListener('keydown', e => { if (e.key === 'Enter') doSave($('rbSaveName').value, $('rbSaveDesc').value); });
        $('rbOpenBtn').addEventListener('click', showOpen);

        // ── Export ───────────────────────────────────────────────────────
        document.querySelectorAll('[data-export]').forEach(b => b.addEventListener('click', () => {
            if (!state.config.columns.filter(c => !c.hidden).length) { toast('Add at least one visible column first.', 'error'); return; }
            const form = $('rbExportForm');
            form.config.value = JSON.stringify(fullConfig());
            form.format.value = b.dataset.export;
            form.submit();
            toast(b.dataset.export === 'pdf' ? 'Opening the PDF in a new tab…' : 'Your ' + (b.dataset.export === 'xlsx' ? 'Excel' : 'CSV') + ' file is downloading…', 'success');
        }));

        window.addEventListener('beforeunload', e => {
            if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
        });

        // ── Letterheads ──────────────────────────────────────────────────
        function renderLetterheadSelect() {
            const list = state.meta.letterheads || [];
            const current = letterheadFor(state.config.letterhead_id);
            $('rbLetterheadSelect').innerHTML = list.map(x => `<option value="${x.id}"${current && x.id === current.id ? ' selected' : ''}>${esc(x.name)}${x.is_default ? ' (default)' : ''}</option>`).join('');
        }
        $('rbLetterheadSelect').addEventListener('change', e => {
            const entry = (state.meta.letterheads || []).find(x => x.id === +e.target.value);
            state.config.letterhead_id = entry ? entry.id : 0;
            changed();
        });

        const SWATCHES = {
            primary: ['#312E81', '#1E3A8A', '#065F46', '#7F1D1D', '#5B21B6', '#0F766E', '#111827'],
            accent: ['#C49A2C', '#D97706', '#DC2626', '#2563EB', '#059669', '#DB2777', '#6B7280'],
        };
        let lhEdit = null;

        function lhLogoUrl(cfg) {
            if (cfg.logo === 'none') return '';
            if (cfg.logo === 'system') return state.meta.system_logo_url || '';
            return lhEdit && lhEdit.customUrl && lhEdit.customLogo === cfg.logo ? lhEdit.customUrl : '';
        }

        function loadLetterheadIntoEditor(entry) {
            lhEdit = {
                id: entry.id, name: entry.name, is_default: entry.is_default, config: clone(entry.config),
                customLogo: entry.config.logo.startsWith('custom:') ? entry.config.logo : '',
                customUrl: entry.config.logo.startsWith('custom:') ? entry.logo_url : '',
            };
            renderLetterheadEditor();
        }

        function renderLetterheadEditor() {
            const list = state.meta.letterheads || [];
            $('rbLhList').innerHTML = list.map(x => `
                <button type="button" class="rb-lh-item${lhEdit && x.id === lhEdit.id ? ' on' : ''}" data-lh-id="${x.id}">
                    ${esc(x.name)}<small>${x.is_default ? '★ Default' : 'Letterhead'}${x.id === 0 ? ' · not saved yet' : ''}</small>
                </button>`).join('') + (lhEdit && lhEdit.id === -1 ? `<button type="button" class="rb-lh-item on">${esc(lhEdit.name || 'New letterhead')}<small>New · not saved yet</small></button>` : '');
            const c = lhEdit.config;
            $('lhLabel').value = lhEdit.name;
            $('lhDefault').checked = !!lhEdit.is_default;
            document.querySelectorAll('#rbLhForm [data-lh]').forEach(el => {
                const key = el.dataset.lh;
                if (key === 'logo_choice') el.value = c.logo.startsWith('custom:') ? 'custom' : c.logo;
                else if (el.type === 'checkbox') el.checked = !!c[key];
                else el.value = c[key];
            });
            $('lh_logo_height_v').textContent = c.logo_height;
            $('lh_name_size_v').textContent = c.name_size;
            const logoUrl = lhLogoUrl(c);
            $('lh_logo_img').style.display = logoUrl ? '' : 'none';
            if (logoUrl) $('lh_logo_img').src = logoUrl;
            document.querySelectorAll('[data-swatch-for]').forEach(box => {
                const key = box.dataset.swatchFor;
                box.innerHTML = SWATCHES[key].map(col => `<button type="button" class="rb-swatch" data-swatch="${key}" data-color="${col}" style="background:${col}" title="${col}"></button>`).join('');
            });
            $('rbLhDelete').style.display = lhEdit.id > 0 ? '' : 'none';
            renderLetterheadPreview();
        }

        function renderLetterheadPreview() {
            const c = lhEdit.config;
            const box = $('rbLhPreview');
            box.style.cssText = letterheadVars(c);
            box.innerHTML = letterheadTopHtml(c, lhLogoUrl(c), 'Sample Report Title')
                + '<div class="doc-details"><b>Report Period:</b><span>01 Apr 2026  to  31 Mar 2027</span><b>Ref. No:</b><span class="r">SRC/RPT/…</span></div>'
                + (c.bands ? '<div class="lh-band" style="margin-top:8px"></div>' : '');
        }

        function openLetterheadEditor() {
            const current = letterheadFor(state.config.letterhead_id);
            loadLetterheadIntoEditor(current);
            openModal('rbLhModal');
        }
        $('rbEditLetterheads').addEventListener('click', openLetterheadEditor);

        $('rbLhList').addEventListener('click', e => {
            const b = e.target.closest('[data-lh-id]');
            if (!b) return;
            const entry = (state.meta.letterheads || []).find(x => x.id === +b.dataset.lhId);
            if (entry) loadLetterheadIntoEditor(entry);
        });
        $('rbLhNew').addEventListener('click', () => {
            const base = letterheadFor(0);
            lhEdit = { id: -1, name: 'New letterhead', is_default: false, config: clone(base.config), customLogo: '', customUrl: '' };
            if (lhEdit.config.logo.startsWith('custom:')) { lhEdit.customLogo = lhEdit.config.logo; lhEdit.customUrl = base.logo_url; }
            renderLetterheadEditor();
            $('lhLabel').focus();
            $('lhLabel').select();
        });

        function onLetterheadField(e) {
            const el = e.target;
            const key = el.dataset.lh;
            if (!key || !lhEdit) return;
            const c = lhEdit.config;
            if (key === 'logo_choice') {
                if (el.value === 'custom') {
                    if (lhEdit.customLogo) c.logo = lhEdit.customLogo;
                    else { $('lh_logo_file').click(); return; }
                } else {
                    c.logo = el.value;
                }
                renderLetterheadEditor();
                return;
            }
            if (el.type === 'checkbox') c[key] = el.checked;
            else if (el.type === 'range') c[key] = parseFloat(el.value);
            else if (el.type === 'color') c[key] = el.value.toUpperCase();
            else c[key] = el.value;
            if (key === 'logo_height') $('lh_logo_height_v').textContent = el.value;
            if (key === 'name_size') $('lh_name_size_v').textContent = el.value;
            renderLetterheadPreview();
        }
        $('rbLhForm').addEventListener('input', onLetterheadField);
        $('rbLhForm').addEventListener('change', onLetterheadField);
        $('rbLhForm').addEventListener('click', e => {
            const sw = e.target.closest('[data-swatch]');
            if (!sw) return;
            lhEdit.config[sw.dataset.swatch] = sw.dataset.color;
            $('lh_' + sw.dataset.swatch).value = sw.dataset.color.toLowerCase();
            renderLetterheadPreview();
        });
        $('lhLabel').addEventListener('input', e => { lhEdit.name = e.target.value; });
        $('lhDefault').addEventListener('change', e => { lhEdit.is_default = e.target.checked; });

        $('lh_logo_upload').addEventListener('click', () => $('lh_logo_file').click());
        $('lh_logo_file').addEventListener('change', async e => {
            const file = e.target.files[0];
            e.target.value = '';
            if (!file) { renderLetterheadEditor(); return; }
            const form = new FormData();
            form.append('logo', file);
            try {
                const res = await fetch('api/report-builder.php?action=letterhead_logo', { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-RB-Token': BOOT.token } });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Upload failed');
                lhEdit.customLogo = data.logo;
                lhEdit.customUrl = data.url;
                lhEdit.config.logo = data.logo;
                renderLetterheadEditor();
                toast('Logo uploaded. Save the letterhead to keep it.', 'success');
            } catch (err) {
                toast(err.message, 'error');
                renderLetterheadEditor();
            }
        });

        async function saveLetterhead(asNew) {
            const name = ($('lhLabel').value || '').trim();
            if (!name) { toast('Give the letterhead a name.', 'error'); $('lhLabel').focus(); return; }
            try {
                const res = await api('letterhead_save', { id: asNew || lhEdit.id <= 0 ? 0 : lhEdit.id, name: asNew && lhEdit.id > 0 && name === lhEdit.name ? name + ' (copy)' : name, config: lhEdit.config });
                let list = res.letterheads;
                if (lhEdit.is_default && !list.find(x => x.id === res.id)?.is_default) {
                    list = (await api('letterhead_default', { id: res.id })).letterheads;
                }
                state.meta.letterheads = list;
                const saved = list.find(x => x.id === res.id);
                loadLetterheadIntoEditor(saved);
                state.config.letterhead_id = res.id;
                renderLetterheadSelect();
                changed();
                toast('Letterhead "' + saved.name + '" saved' + (saved.is_default ? ' as the default' : '') + ' and used for this report.', 'success');
            } catch (err) {
                toast(err.message, 'error');
            }
        }
        $('rbLhSave').addEventListener('click', () => saveLetterhead(false));
        $('rbLhSaveAs').addEventListener('click', () => saveLetterhead(true));
        $('rbLhDelete').addEventListener('click', async () => {
            if (!lhEdit || lhEdit.id <= 0 || !confirm('Delete the letterhead "' + lhEdit.name + '"? Reports that use it will use the default letterhead.')) return;
            try {
                const res = await api('letterhead_delete', { id: lhEdit.id });
                if (state.config.letterhead_id === lhEdit.id) { state.config.letterhead_id = 0; changed(); }
                state.meta.letterheads = res.letterheads;
                loadLetterheadIntoEditor(letterheadFor(0));
                renderLetterheadSelect();
                schedulePreview(0);
                toast('Letterhead deleted.', 'success');
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        // ── Draft banner ─────────────────────────────────────────────────
        function offerDraft(draft) {
            $('rbDraftTime').textContent = new Date(draft.at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' });
            $('rbDraftBanner').classList.add('show');
            $('rbDraftRestore').onclick = () => {
                load(draft.config, { templateId: draft.templateId || 0, name: draft.name || '' });
                setDirty(true);
                store.set(DRAFT_KEY, draft);
                $('rbDraftBanner').classList.remove('show');
            };
            $('rbDraftDiscard').onclick = () => { store.del(DRAFT_KEY); $('rbDraftBanner').classList.remove('show'); };
        }

        // ── Boot ─────────────────────────────────────────────────────────
        (async function init() {
            try {
                state.meta = await api('meta');
            } catch (e) {
                $('rbStatus').textContent = 'Could not load the report builder';
                $('rbError').textContent = e.message;
                $('rbError').classList.add('show');
                return;
            }
            const draft = store.get(DRAFT_KEY);
            if (BOOT.templateId) {
                try { await openTemplate(BOOT.templateId); } catch (e) { toast(e.message, 'error'); load(state.meta.presets[0].config); }
            } else {
                const preset = state.meta.presets.find(p => p.key === BOOT.preset) || state.meta.presets[0];
                load(preset.config);
                if (!BOOT.preset) showOpen();
            }
            if (draft && draft.config && Date.now() - draft.at < 14 * 86400000) offerDraft(draft);
        })();
    })();
    </script>
</body>
</html>
