<?php
/**
 * SR Colony AI Chat Widget
 * Drop this include at the bottom of any portal page to add the AI chatbot.
 * Works for: superadmin, admin, and member portals.
 */

$_gc_allowed = isset($_SESSION['superadmin_id']) || isset($_SESSION['admin_id']) || isset($_SESSION['member_id']);
if (!$_gc_allowed) return;

// API URL
$_gc_script = $_SERVER['SCRIPT_NAME'] ?? '';
if (preg_match('#^(.*?/superadmin)/#', $_gc_script, $_gc_m)) {
    $_gc_api = 'gemini-api.php';
} elseif (preg_match('#^(.*?)/(admin|member)/#', $_gc_script, $_gc_m)) {
    $_gc_api = '../superadmin/gemini-api.php';
} else {
    $_gc_api = 'superadmin/gemini-api.php';
}

$_gc_username = htmlspecialchars(
    $_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ??
    $_SESSION['admin_name']      ?? $_SESSION['admin_username']      ??
    $_SESSION['member_name']     ?? 'User',
    ENT_QUOTES, 'UTF-8'
);

// Unique key per user to isolate localStorage history
$_gc_uid = $_SESSION['superadmin_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['member_id'] ?? 'u';

// Chatbot settings with defaults
$_gc_hist_enabled = true;
$_gc_hist_days    = 7;
$_gc_auto_open    = false;
$_gc_quick_btns   = ['Show total collection', 'Which member paid the most?', 'Net balance?', 'Recent transactions'];

try {
    if (isset($pdo)) {
        $s = $pdo->prepare("SELECT SettingKey, SettingValue FROM system_settings WHERE SettingKey LIKE 'chatbot_%'");
        $s->execute();
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            switch ($r['SettingKey']) {
                case 'chatbot_history_enabled': $_gc_hist_enabled = $r['SettingValue'] === '1'; break;
                case 'chatbot_history_days':    $_gc_hist_days    = max(1, min(30, intval($r['SettingValue']))); break;
                case 'chatbot_auto_open':       $_gc_auto_open    = $r['SettingValue'] === '1'; break;
                case 'chatbot_quick_btn_1':     $_gc_quick_btns[0] = $r['SettingValue']; break;
                case 'chatbot_quick_btn_2':     $_gc_quick_btns[1] = $r['SettingValue']; break;
                case 'chatbot_quick_btn_3':     $_gc_quick_btns[2] = $r['SettingValue']; break;
                case 'chatbot_quick_btn_4':     $_gc_quick_btns[3] = $r['SettingValue']; break;
            }
        }
    }
} catch (Exception $e) { /* use defaults */ }

// Filter out blank buttons
$_gc_quick_btns = array_values(array_filter($_gc_quick_btns, fn($b) => trim($b) !== ''));
?>

<!-- SR Colony AI Chat Widget -->
<div id="gc-toggle" onclick="GChatbot.toggle()" title="AI Assistant" role="button" aria-label="Open AI Assistant">
    <i class="fas fa-robot"></i>
    <span id="gc-pulse"></span>
</div>

<div id="gc-window" role="dialog" aria-label="SR Colony AI Assistant" aria-hidden="true">

    <!-- Header -->
    <div class="gc-header">
        <div class="gc-header-left">
            <div class="gc-avatar"><i class="fas fa-robot"></i></div>
            <div>
                <div class="gc-title">SR Colony AI Assistant</div>
                <div class="gc-subtitle"><span class="gc-online-dot"></span> Online &nbsp;&middot;&nbsp; Groq LLaMA 3.1</div>
            </div>
        </div>
        <div class="gc-header-btns">
            <button onclick="GChatbot.clearChat()" title="Clear chat" class="gc-hbtn"><i class="fas fa-trash-alt"></i></button>
            <button onclick="GChatbot.toggle()" title="Close" class="gc-hbtn"><i class="fas fa-times"></i></button>
        </div>
    </div>

    <!-- Quick Action Buttons with Scroll Arrows -->
    <div class="gc-quick-wrap">
        <button class="gc-qa-arrow gc-qa-left" id="gc-qa-left" onclick="GChatbot.scrollQuick(-160)" aria-label="Scroll left">
            <i class="fas fa-chevron-left"></i>
        </button>
        <div class="gc-quick-bar" id="gc-quick-bar">
            <?php foreach ($_gc_quick_btns as $btn): ?>
            <button class="gc-qbtn" onclick="GChatbot.quickSend(<?php echo json_encode($btn); ?>)">
                <?php echo htmlspecialchars($btn, ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <button class="gc-qa-arrow gc-qa-right" id="gc-qa-right" onclick="GChatbot.scrollQuick(160)" aria-label="Scroll right">
            <i class="fas fa-chevron-right"></i>
        </button>
    </div>

    <!-- Messages -->
    <div id="gc-messages" class="gc-messages" role="log" aria-live="polite"></div>

    <!-- Input row -->
    <div class="gc-input-row">
        <input type="text" id="gc-input" class="gc-input"
            placeholder="Ask about collections, members, transactions..."
            aria-label="Chat message input"
            onkeydown="GChatbot.handleKey(event)">
        <button class="gc-mic-btn" id="gc-mic" onclick="GChatbot.voiceInput()" title="Voice input">
            <i class="fas fa-microphone" id="gc-mic-icon"></i>
        </button>
        <button id="gc-send-btn" onclick="GChatbot.send()" class="gc-send-btn" title="Send">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>

    <!-- Footer -->
    <div class="gc-footer-bar">Powered by Groq LLaMA 3.1 &nbsp;&middot;&nbsp; Data is live from your database</div>

</div>

<style>
/* ═══════════════════════════════════════════
   SR Colony AI Chat Widget — Dark Theme
   ═══════════════════════════════════════════ */

#gc-toggle {
    position: fixed;
    bottom: 28px;
    right: 28px;
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(124,115,230,.5);
    z-index: 9998;
    transition: transform .2s, box-shadow .2s;
    user-select: none;
    font-size: 22px;
    border: none;
}
#gc-toggle:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(124,115,230,.7); }

#gc-pulse {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    animation: gcPulse 2s infinite;
    z-index: -1;
    pointer-events: none;
}
@keyframes gcPulse {
    0%   { transform: scale(1); opacity: .6; }
    100% { transform: scale(1.6); opacity: 0; }
}

#gc-window {
    position: fixed;
    bottom: 96px;
    right: 28px;
    width: 430px;
    max-width: calc(100vw - 32px);
    height: 620px;
    max-height: calc(100vh - 110px);
    background: #16192a;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
    display: none;
    flex-direction: column;
    z-index: 9999;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.06);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
    animation: gcSlideIn .25s ease;
}
@keyframes gcSlideIn {
    from { opacity: 0; transform: translateY(16px) scale(.97); }
    to   { opacity: 1; transform: translateY(0)  scale(1); }
}

/* Header */
.gc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: #1a1d30;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
}
.gc-header-left { display: flex; align-items: center; gap: 10px; }
.gc-avatar {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: #fff; flex-shrink: 0;
}
.gc-title { font-size: 14px; font-weight: 600; color: #e8e8f4; line-height: 1.2; }
.gc-subtitle {
    font-size: 11px; color: #7b7d9d;
    display: flex; align-items: center; gap: 5px; margin-top: 2px;
}
.gc-online-dot {
    width: 6px; height: 6px;
    border-radius: 50%; background: #2ecc71;
    display: inline-block; flex-shrink: 0;
}
.gc-header-btns { display: flex; gap: 4px; }
.gc-hbtn {
    background: transparent; border: none;
    color: #7b7d9d; width: 32px; height: 32px;
    border-radius: 8px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; transition: background .2s, color .2s;
}
.gc-hbtn:hover { background: rgba(255,255,255,.08); color: #e8e8f4; }

/* Quick Actions Wrapper */
.gc-quick-wrap {
    display: flex;
    align-items: stretch;
    background: #1a1d30;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
    position: relative;
}
.gc-qa-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    min-width: 26px;
    background: #1a1d30;
    border: none;
    color: #5a5c7a;
    cursor: pointer;
    font-size: 11px;
    transition: color .2s, background .2s;
    flex-shrink: 0;
    padding: 0;
    z-index: 1;
}
.gc-qa-arrow:hover { color: #e8e8f4; background: rgba(255,255,255,.05); }
.gc-qa-left  { box-shadow:  4px 0 8px rgba(26,29,48,.8); }
.gc-qa-right { box-shadow: -4px 0 8px rgba(26,29,48,.8); }

.gc-quick-bar {
    display: flex;
    gap: 8px;
    padding: 10px 4px;
    overflow-x: auto;
    flex: 1;
    scrollbar-width: none;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}
.gc-quick-bar::-webkit-scrollbar { display: none; }

.gc-qbtn {
    white-space: nowrap;
    padding: 6px 14px;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,.14);
    background: rgba(255,255,255,.04);
    color: #c4c4e0;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all .2s;
    flex-shrink: 0;
    font-family: inherit;
}
.gc-qbtn:hover {
    background: rgba(124,115,230,.2);
    border-color: #7c73e6;
    color: #fff;
}

/* Messages */
.gc-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: #16192a;
    scroll-behavior: smooth;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.07) transparent;
}
.gc-messages::-webkit-scrollbar { width: 4px; }
.gc-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 10px; }

/* Date separator */
.gc-date-sep {
    text-align: center;
    font-size: 11px;
    color: #4a4c6c;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    margin-bottom: 4px;
}
.gc-date-sep::before,
.gc-date-sep::after { content:''; flex:1; height:1px; background:rgba(255,255,255,.06); }

/* Message rows */
.gc-msg {
    display: flex;
    flex-direction: column;
    max-width: 92%;
    animation: gcMsgIn .2s ease;
}
@keyframes gcMsgIn {
    from { opacity:0; transform:translateY(7px); }
    to   { opacity:1; transform:translateY(0); }
}
.gc-msg.user { align-self: flex-end; align-items: flex-end; }
.gc-msg.bot  { align-self: flex-start; align-items: flex-start; }

.gc-bubble {
    padding: 10px 14px;
    border-radius: 14px;
    line-height: 1.6;
    word-break: break-word;
    font-size: 13px;
}
.gc-msg.user .gc-bubble {
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    color: #fff;
    border-radius: 14px 14px 4px 14px;
}
.gc-msg.bot .gc-bubble {
    background: #252843;
    color: #dcdcf0;
    border-radius: 4px 14px 14px 14px;
    border: 1px solid rgba(255,255,255,.06);
}
.gc-msg.bot .gc-bubble strong, .gc-msg.bot .gc-bubble b { color: #a89ff5; font-weight: 600; }
.gc-bubble p { margin: 0 0 6px; }
.gc-bubble p:last-child { margin: 0; }
.gc-bubble ul, .gc-bubble ol { padding-left: 18px; margin: 4px 0; }
.gc-bubble li { margin-bottom: 3px; }
.gc-bubble code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 4px; font-family: monospace; font-size: 12px; }

.gc-meta { font-size: 10px; color: #4a4c6c; margin-top: 4px; padding: 0 4px; }

/* Restored-history tag */
.gc-restored-tag {
    font-size: 11px;
    color: #5a5c7a;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 12px;
    padding: 3px 10px;
    align-self: center;
    margin: 4px 0;
}

/* Stats Cards */
.gc-stats-row { display: flex; gap: 8px; margin-top: 12px; }
.gc-stat-card {
    flex: 1;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 10px;
    padding: 10px 10px 8px;
    min-width: 0;
}
.gc-stat-lbl { font-size: 10px; color: #5a5c7a; font-weight: 500; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.gc-stat-val { font-size: 20px; font-weight: 700; color: #e0e0f0; line-height: 1; margin-bottom: 4px; }
.gc-stat-hint { font-size: 10px; color: #7c73e6; }
.gc-stat-loading { font-size: 10px; color: #f59e0b; display: flex; align-items: center; gap: 4px; }
.gc-loading-dot { width: 5px; height: 5px; border-radius: 50%; background: #f59e0b; animation: gcPulse 1.2s infinite; }

/* Typing */
.gc-typing .gc-bubble { display: flex; align-items: center; gap: 5px; padding: 12px 16px; }
.gc-typing-dot { width: 6px; height: 6px; border-radius: 50%; background: #5a5c7a; animation: gcBlink 1.2s infinite; }
.gc-typing-dot:nth-child(2) { animation-delay: .2s; }
.gc-typing-dot:nth-child(3) { animation-delay: .4s; }
@keyframes gcBlink {
    0%,80%,100% { opacity:.3; transform:scale(.85) translateY(0); }
    40%          { opacity:1;  transform:scale(1) translateY(-4px); }
}

/* Input Row */
.gc-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: #1a1d30;
    border-top: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
}
.gc-input {
    flex: 1;
    padding: 9px 14px;
    border-radius: 22px;
    border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.05);
    color: #e0e0f0;
    font-size: 13px;
    font-family: inherit;
    outline: none;
    transition: border-color .2s, background .2s;
}
.gc-input:focus { border-color: rgba(124,115,230,.5); background: rgba(255,255,255,.07); }
.gc-input::placeholder { color: #4a4c6a; }
.gc-input:disabled { opacity: .5; }

.gc-mic-btn {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1);
    color: #7b7d9d; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; transition: all .2s; flex-shrink: 0;
}
.gc-mic-btn:hover, .gc-mic-btn.gc-mic-active {
    background: rgba(124,115,230,.2);
    border-color: #7c73e6;
    color: #a89ff5;
}
.gc-send-btn {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    color: #fff; border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 14px; flex-shrink: 0;
    transition: opacity .15s, transform .1s;
}
.gc-send-btn:hover:not(:disabled) { transform: scale(1.08); }
.gc-send-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }

/* Footer */
.gc-footer-bar {
    text-align: center;
    font-size: 10px;
    color: #3a3c5a;
    padding: 5px 8px;
    background: #1a1d30;
    border-top: 1px solid rgba(255,255,255,.04);
    flex-shrink: 0;
    letter-spacing: .2px;
}

/* Mobile */
@media (max-width: 480px) {
    #gc-window { right:0; bottom:0; width:100vw; height:100vh; max-height:100vh; border-radius:0; border:none; }
    #gc-toggle { bottom:20px; right:16px; }
}
@media print { #gc-toggle, #gc-window { display:none !important; } }
</style>

<script>
(function () {
    'use strict';

    const API_URL      = <?= json_encode($_gc_api) ?>;
    const ADMIN_NAME   = <?= json_encode($_gc_username) ?>;
    const HIST_ENABLED = <?= $_gc_hist_enabled ? 'true' : 'false' ?>;
    const HIST_DAYS    = <?= intval($_gc_hist_days) ?>;
    const AUTO_OPEN    = <?= $_gc_auto_open ? 'true' : 'false' ?>;
    const HIST_KEY     = 'gc_hist_v2_<?= intval($_gc_uid) ?>';  // v2 = auto-clears old history
    const HIST_TTL     = HIST_DAYS * 86400 * 1000;

    var _gcRecognition = null;

    const GChatbot = window.GChatbot = {
        open: false,
        loading: false,

        el(id) { return document.getElementById(id); },
        msgs()  { return this.el('gc-messages'); },

        /* ── Toggle ─────────────────────────────────────── */
        toggle() {
            this.open = !this.open;
            const win = this.el('gc-window');
            win.style.display = this.open ? 'flex' : 'none';
            win.setAttribute('aria-hidden', String(!this.open));
            if (this.open) {
                if (!this.msgs().hasChildNodes()) {
                    if (!this._loadHistory()) this._showWelcome();
                }
                // Always refresh stats whenever the widget opens
                this._loadStats();
                setTimeout(() => this.el('gc-input').focus(), 80);
                this._updateArrows();
            }
        },

        /* ── Welcome ────────────────────────────────────── */
        _showWelcome() {
            const sep = document.createElement('div');
            sep.className = 'gc-date-sep';
            sep.textContent = 'Today';
            this.msgs().appendChild(sep);

            const row = document.createElement('div');
            row.className = 'gc-msg bot';
            const bubble = document.createElement('div');
            bubble.className = 'gc-bubble';
            bubble.innerHTML =
                '<div style="margin-bottom:8px;font-size:14px;">' +
                    '<strong>👋 Hello, ' + this._esc(ADMIN_NAME) + '! I\'m your SR Colony Chitti AI Assistant.</strong>' +
                '</div>' +
                '<div style="font-size:12.5px;color:#9090b0;line-height:1.6;">' +
                    'I have live access to your collection data, member records, and transaction history. ' +
                    'Ask me anything about your chitti fund.' +
                '</div>' +
                '<div class="gc-stats-row">' +
                    '<div class="gc-stat-card">' +
                        '<div class="gc-stat-lbl">Members</div>' +
                        '<div class="gc-stat-val" id="gc-sv-members">&mdash;</div>' +
                        '<div id="gc-sh-members" class="gc-stat-loading"><span class="gc-loading-dot"></span> Loading...</div>' +
                    '</div>' +
                    '<div class="gc-stat-card">' +
                        '<div class="gc-stat-lbl">Today</div>' +
                        '<div class="gc-stat-val" id="gc-sv-today">&mdash;</div>' +
                        '<div id="gc-sh-today" class="gc-stat-loading"><span class="gc-loading-dot"></span> Loading...</div>' +
                    '</div>' +
                    '<div class="gc-stat-card">' +
                        '<div class="gc-stat-lbl">Net Balance</div>' +
                        '<div class="gc-stat-val" id="gc-sv-balance" style="font-size:13px;">&mdash;</div>' +
                        '<div id="gc-sh-balance" class="gc-stat-loading"><span class="gc-loading-dot"></span> Loading...</div>' +
                    '</div>' +
                '</div>';

            const meta = document.createElement('div');
            meta.className = 'gc-meta';
            meta.textContent = this._now();
            row.appendChild(bubble);
            row.appendChild(meta);
            this.msgs().appendChild(row);
            this._scrollBottom();
            this._saveHistory();
        },

        /* ── Load live stats into welcome cards ─────────── */
        _loadStats() {
            fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'stats' })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.stats) return;
                GChatbot._applyStats(data.stats);
            })
            .catch(function() {
                ['gc-sh-members','gc-sh-today','gc-sh-balance'].forEach(function(id) {
                    const el = document.getElementById(id);
                    if (el) { el.className = 'gc-stat-hint'; el.textContent = 'unavailable'; }
                });
            });
        },

        /* ── Apply stats to DOM (with retry if elements not ready yet) ── */
        _applyStats(s, attempt) {
            attempt = attempt || 0;
            var membersEl = document.getElementById('gc-sv-members');
            // If elements not in DOM yet (history restore timing), retry up to 5×
            if (!membersEl && attempt < 5) {
                var self = this;
                setTimeout(function() { self._applyStats(s, attempt + 1); }, 150);
                return;
            }
            function setEl(id, val) {
                var el = document.getElementById(id);
                if (el) el.textContent = val;
            }
            function hintEl(id, val, cls) {
                var el = document.getElementById(id);
                if (!el) return;
                el.className = cls || 'gc-stat-hint';
                el.textContent = val;
            }
            if (s.members !== undefined) {
                setEl('gc-sv-members', s.members);
                hintEl('gc-sh-members', s.active_members + ' active');
            }
            if (s.today_count !== undefined) {
                setEl('gc-sv-today', s.today_count);
                hintEl('gc-sh-today', 'transactions today');
            }
            if (s.net_balance !== undefined) {
                setEl('gc-sv-balance', s.net_balance);
                var isPos = (s.net_balance_raw >= 0);
                hintEl('gc-sh-balance', isPos ? 'surplus' : 'deficit',
                       isPos ? 'gc-stat-hint' : 'gc-stat-loading');
            }
        },

        /* ── Quick scroll ───────────────────────────────── */
        scrollQuick(amount) {
            const bar = this.el('gc-quick-bar');
            if (bar) bar.scrollBy({ left: amount, behavior: 'smooth' });
        },

        _updateArrows() {
            const bar = this.el('gc-quick-bar');
            const left = this.el('gc-qa-left');
            const right = this.el('gc-qa-right');
            if (!bar || !left || !right) return;
            const atStart = bar.scrollLeft <= 2;
            const atEnd = bar.scrollLeft + bar.clientWidth >= bar.scrollWidth - 2;
            left.style.opacity  = atStart ? '0.3' : '1';
            right.style.opacity = atEnd   ? '0.3' : '1';
        },

        /* ── Quick send ─────────────────────────────────── */
        quickSend(text) {
            if (this.loading) return;
            this.el('gc-input').value = text;
            this.send();
        },

        /* ── Voice input ────────────────────────────────── */
        voiceInput() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) { alert('Voice input is not supported in this browser. Please use Chrome or Edge.'); return; }
            const micBtn = this.el('gc-mic');
            if (_gcRecognition) {
                _gcRecognition.stop();
                _gcRecognition = null;
                micBtn.classList.remove('gc-mic-active');
                return;
            }
            _gcRecognition = new SR();
            _gcRecognition.lang = 'en-IN';
            _gcRecognition.interimResults = false;
            micBtn.classList.add('gc-mic-active');
            _gcRecognition.start();
            _gcRecognition.onresult = (e) => {
                this.el('gc-input').value = e.results[0][0].transcript;
                micBtn.classList.remove('gc-mic-active');
                _gcRecognition = null;
                this.send();
            };
            _gcRecognition.onerror = _gcRecognition.onend = () => {
                micBtn.classList.remove('gc-mic-active');
                _gcRecognition = null;
            };
        },

        /* ── Key handler ────────────────────────────────── */
        handleKey(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); }
        },

        /* ── Send ───────────────────────────────────────── */
        async send() {
            if (this.loading) return;
            const input = this.el('gc-input');
            const query = input.value.trim();
            if (!query) return;
            input.value = '';
            this._setDisabled(true);
            this._appendMsg('user', query);
            const typingEl = this._showTyping();
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ query })
                });
                if (!res.ok) throw new Error('Server error ' + res.status);
                const data = await res.json();
                typingEl.remove();
                this._appendMsg('bot', data.error ? '⚠️ ' + data.error : (data.answer || 'No response received.'));
            } catch (err) {
                typingEl.remove();
                this._appendMsg('bot', '⚠️ Connection error. Please try again.');
            }
            this._setDisabled(false);
            this.el('gc-input').focus();
        },

        /* ── Clear chat ─────────────────────────────────── */
        clearChat() {
            this.msgs().innerHTML = '';
            // Remove current and legacy history keys
            try {
                localStorage.removeItem(HIST_KEY);
                localStorage.removeItem('gc_hist_<?= intval($_gc_uid) ?>'); // old v1 key
            } catch(e) {}
            this._showWelcome();
            this._loadStats();
        },

        /* ── Append message ─────────────────────────────── */
        _appendMsg(role, text) {
            const row = document.createElement('div');
            row.className = 'gc-msg ' + role;
            const bubble = document.createElement('div');
            bubble.className = 'gc-bubble';
            bubble.innerHTML = this._fmt(text);
            const meta = document.createElement('div');
            meta.className = 'gc-meta';
            meta.textContent = this._now();
            row.appendChild(bubble);
            row.appendChild(meta);
            this.msgs().appendChild(row);
            this._scrollBottom();
            this._saveHistory();
        },

        /* ── Typing indicator ───────────────────────────── */
        _showTyping() {
            const wrap = document.createElement('div');
            wrap.className = 'gc-msg bot gc-typing';
            wrap.innerHTML = '<div class="gc-bubble"><div class="gc-typing-dot"></div><div class="gc-typing-dot"></div><div class="gc-typing-dot"></div></div>';
            this.msgs().appendChild(wrap);
            this._scrollBottom();
            return wrap;
        },

        /* ── LocalStorage history ───────────────────────── */
        _saveHistory() {
            if (!HIST_ENABLED) return;
            try {
                const msgs = [];
                this.msgs().querySelectorAll('.gc-msg').forEach(row => {
                    const bubble = row.querySelector('.gc-bubble');
                    const meta   = row.querySelector('.gc-meta');
                    if (!bubble || !meta) return;
                    msgs.push({
                        r: row.classList.contains('user') ? 'user' : 'bot',
                        h: bubble.innerHTML,
                        t: meta.textContent,
                        ts: Date.now()
                    });
                });
                localStorage.setItem(HIST_KEY, JSON.stringify({ v: 1, saved: Date.now(), ttl: HIST_TTL, msgs }));
            } catch(e) {}
        },

        _loadHistory() {
            if (!HIST_ENABLED) return false;
            try {
                const raw = localStorage.getItem(HIST_KEY);
                if (!raw) return false;
                const data = JSON.parse(raw);
                if (!data || !data.msgs || !data.msgs.length) return false;
                if (Date.now() - data.saved > data.ttl) {
                    localStorage.removeItem(HIST_KEY);
                    return false;
                }
                const cutoff = Date.now() - HIST_TTL;
                let count = 0;
                data.msgs.forEach(m => {
                    if (m.ts < cutoff) return;
                    const row = document.createElement('div');
                    row.className = 'gc-msg ' + m.r;
                    const bubble = document.createElement('div');
                    bubble.className = 'gc-bubble';
                    bubble.innerHTML = m.h;
                    const meta = document.createElement('div');
                    meta.className = 'gc-meta';
                    meta.textContent = m.t;
                    row.appendChild(bubble);
                    row.appendChild(meta);
                    this.msgs().appendChild(row);
                    count++;
                });
                if (count === 0) return false;
                // "Restored" tag
                const tag = document.createElement('div');
                tag.className = 'gc-restored-tag';
                tag.innerHTML = '<i class="fas fa-history" style="font-size:9px;"></i> History restored &middot; ' + HIST_DAYS + '-day retention';
                this.msgs().appendChild(tag);
                // "Now" separator
                const sep = document.createElement('div');
                sep.className = 'gc-date-sep';
                sep.textContent = 'Now';
                this.msgs().appendChild(sep);
                this._scrollBottom();
                return true;
            } catch(e) { return false; }
        },

        /* ── Text formatter ─────────────────────────────── */
        _fmt(text) {
            let t = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
            t = t.replace(/^[•\-\*] (.+)/gm, '<li>$1</li>');
            t = t.replace(/^\d+\. (.+)/gm, '<li>$1</li>');
            t = t.replace(/(<li>[\s\S]*?<\/li>\n?)+/g, m => '<ul>' + m + '</ul>');
            t = t.split(/\n\n+/).map(p => {
                p = p.trim();
                if (!p) return '';
                if (p.startsWith('<ul>') || p.startsWith('<ol>')) return p;
                return '<p>' + p.replace(/\n/g,'<br>') + '</p>';
            }).join('');
            return t || text;
        },

        /* ── Helpers ────────────────────────────────────── */
        _esc(str) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(str));
            return d.innerHTML;
        },
        _now() { return new Date().toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }); },
        _setDisabled(v) {
            this.loading = v;
            this.el('gc-send-btn').disabled = v;
            this.el('gc-input').disabled = v;
        },
        _scrollBottom() {
            const el = this.msgs();
            requestAnimationFrame(() => { el.scrollTop = el.scrollHeight; });
        }
    };

    /* Mouse-wheel horizontal scroll on quick bar */
    document.addEventListener('DOMContentLoaded', function() {
        const bar = document.getElementById('gc-quick-bar');
        if (bar) {
            bar.addEventListener('wheel', function(e) {
                if (e.deltaY !== 0) {
                    e.preventDefault();
                    bar.scrollLeft += e.deltaY * 1.5;
                    GChatbot._updateArrows();
                }
            }, { passive: false });
            bar.addEventListener('scroll', function() { GChatbot._updateArrows(); });
        }
        if (AUTO_OPEN) GChatbot.toggle();
    });

})();
</script>
