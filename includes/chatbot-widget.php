<?php
/**
 * SR Colony AI Chatbot - Frontend Widget
 * Floating chat widget for the superadmin portal.
 * Include this file at the bottom of any page to add the chatbot.
 */

// Only render if superadmin is logged in
if (!isset($_SESSION['superadmin_id'])) return;

// Determine API URL based on current page depth
$chatbotApiUrl = '';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
if (strpos($scriptPath, '/superadmin/accounting/events/') !== false) {
    $chatbotApiUrl = '../../../includes/chatbot.php';
} elseif (strpos($scriptPath, '/superadmin/accounting/') !== false) {
    $chatbotApiUrl = '../../includes/chatbot.php';
} elseif (strpos($scriptPath, '/superadmin/') !== false) {
    $chatbotApiUrl = '../includes/chatbot.php';
} else {
    $chatbotApiUrl = 'includes/chatbot.php';
}

$chatbotAdminName = htmlspecialchars($_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin');
?>

<!-- SR Colony AI Chatbot Widget -->
<div id="chatbot-toggle" class="cb-toggle" onclick="SRChatbot.toggle()" title="AI Assistant">
    <i class="fas fa-robot"></i>
    <span class="cb-pulse"></span>
</div>

<div id="chatbot-window" class="cb-window" style="display:none;">

    <!-- Header -->
    <div class="cb-header">
        <div class="cb-header-left">
            <div class="cb-avatar"><i class="fas fa-robot"></i></div>
            <div>
                <div class="cb-title">SR Colony AI Assistant</div>
                <div class="cb-subtitle"><span class="cb-online-dot"></span> Online &nbsp;&middot;&nbsp; Groq LLaMA 3.1</div>
            </div>
        </div>
        <div class="cb-header-btns">
            <button onclick="SRChatbot.clearChat()" class="cb-hbtn" title="Clear Chat">
                <i class="fas fa-trash-alt"></i>
            </button>
            <button onclick="SRChatbot.toggle()" class="cb-hbtn" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- Quick Action Buttons -->
    <div class="cb-quick-bar">
        <button class="cb-qbtn" onclick="SRChatbot.quickSend('Show total collection')">Show total collection</button>
        <button class="cb-qbtn" onclick="SRChatbot.quickSend('Which member paid the most?')">Which member paid the most?</button>
        <button class="cb-qbtn" onclick="SRChatbot.quickSend('Net balance?')">Net balance?</button>
        <button class="cb-qbtn" onclick="SRChatbot.quickSend('Recent transactions')">Recent transactions</button>
    </div>

    <!-- Messages -->
    <div class="cb-messages" id="chatbot-messages"></div>

    <!-- Input Row -->
    <div class="cb-input-row">
        <input type="text" id="chatbot-input" class="cb-input"
               placeholder="Ask about collections, members, transactions..."
               autocomplete="off"
               onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();SRChatbot.send();}">
        <button class="cb-mic-btn" id="cb-mic" onclick="SRChatbot.voiceInput()" title="Voice input">
            <i class="fas fa-microphone" id="cb-mic-icon"></i>
        </button>
        <button class="cb-send-btn" id="chatbot-send" onclick="SRChatbot.send()" title="Send">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>

    <!-- Footer -->
    <div class="cb-footer-bar">Powered by Groq LLaMA 3.1 &nbsp;&middot;&nbsp; Data is live from your database</div>

</div>

<style>
/* ======================================================
   SR Colony AI Chatbot Widget — Dark Theme
   ====================================================== */

/* Toggle Button */
.cb-toggle {
    position: fixed;
    bottom: 28px;
    right: 28px;
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf 0%, #7c73e6 100%);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    z-index: 9998;
    box-shadow: 0 4px 20px rgba(124, 115, 230, 0.5);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.cb-toggle:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 28px rgba(124, 115, 230, 0.7);
}
.cb-pulse {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    animation: cbPulse 2s infinite;
    z-index: -1;
}
@keyframes cbPulse {
    0%   { transform: scale(1); opacity: 0.6; }
    100% { transform: scale(1.6); opacity: 0; }
}

/* Chat Window */
.cb-window {
    position: fixed;
    bottom: 96px;
    right: 28px;
    width: 430px;
    height: 620px;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
    z-index: 9997;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #16192a;
    border: 1px solid rgba(255, 255, 255, 0.06);
    animation: cbSlideUp 0.3s ease;
}
@keyframes cbSlideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* Header */
.cb-header {
    background: #1a1d30;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    flex-shrink: 0;
}
.cb-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}
.cb-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: #fff;
    flex-shrink: 0;
}
.cb-title {
    font-size: 14px;
    font-weight: 600;
    color: #e8e8f4;
    line-height: 1.2;
}
.cb-subtitle {
    font-size: 11px;
    color: #7b7d9d;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
}
.cb-online-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #2ecc71;
    display: inline-block;
    flex-shrink: 0;
}
.cb-header-btns {
    display: flex;
    gap: 4px;
}
.cb-hbtn {
    background: transparent;
    border: none;
    color: #7b7d9d;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    text-decoration: none;
    transition: background 0.2s, color 0.2s;
}
.cb-hbtn:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #e8e8f4;
}

/* Quick Action Buttons Bar */
.cb-quick-bar {
    display: flex;
    gap: 8px;
    padding: 10px 14px;
    background: #1a1d30;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    overflow-x: auto;
    flex-shrink: 0;
    scrollbar-width: none;
}
.cb-quick-bar::-webkit-scrollbar { display: none; }
.cb-qbtn {
    white-space: nowrap;
    padding: 6px 14px;
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    background: rgba(255, 255, 255, 0.04);
    color: #c4c4e0;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
}
.cb-qbtn:hover {
    background: rgba(124, 115, 230, 0.18);
    border-color: #7c73e6;
    color: #fff;
}

/* Messages Area */
.cb-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: #16192a;
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.07) transparent;
}
.cb-messages::-webkit-scrollbar { width: 4px; }
.cb-messages::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.08);
    border-radius: 10px;
}

/* Date Separator */
.cb-date-sep {
    text-align: center;
    font-size: 11px;
    color: #4a4c6c;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 2px 0 6px;
    flex-shrink: 0;
}
.cb-date-sep::before,
.cb-date-sep::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255, 255, 255, 0.06);
}

/* Message Rows */
.cb-msg {
    display: flex;
    flex-direction: column;
    max-width: 93%;
    animation: cbMsgIn 0.25s ease;
}
@keyframes cbMsgIn {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.cb-msg-bot { align-self: flex-start; }
.cb-msg-user { align-self: flex-end; }

/* Bubbles */
.cb-bubble {
    padding: 10px 14px;
    font-size: 13px;
    line-height: 1.6;
    word-wrap: break-word;
}
.cb-bubble-bot {
    background: #252843;
    color: #dcdcf0;
    border-radius: 4px 14px 14px 14px;
    border: 1px solid rgba(255, 255, 255, 0.06);
}
.cb-bubble-bot strong, .cb-bubble-bot b {
    color: #a89ff5;
}
.cb-bubble-user {
    background: linear-gradient(135deg, #5a4fcf 0%, #7c73e6 100%);
    color: #fff;
    border-radius: 14px 14px 4px 14px;
}
.cb-time {
    font-size: 10px;
    color: #4a4c6c;
    margin-top: 4px;
    padding: 0 4px;
}
.cb-msg-bot .cb-time  { text-align: left; }
.cb-msg-user .cb-time { text-align: right; }

/* Stats Cards (in welcome message) */
.cb-stats-row {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}
.cb-stat-card {
    flex: 1;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    padding: 10px 10px 8px;
    min-width: 0;
}
.cb-stat-lbl {
    font-size: 10px;
    color: #5a5c7a;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.cb-stat-val {
    font-size: 20px;
    font-weight: 700;
    color: #e0e0f0;
    line-height: 1;
    margin-bottom: 4px;
}
.cb-stat-hint {
    font-size: 10px;
    color: #7c73e6;
}
.cb-stat-loading {
    font-size: 10px;
    color: #f59e0b;
    display: flex;
    align-items: center;
    gap: 4px;
}
.cb-loading-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #f59e0b;
    animation: cbPulse 1.2s infinite;
}

/* Typing Indicator */
.cb-typing {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 10px 14px;
    align-self: flex-start;
    background: #252843;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 4px 14px 14px 14px;
}
.cb-tdot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #5a5c7a;
    animation: cbTyping 1.2s infinite;
}
.cb-tdot:nth-child(2) { animation-delay: 0.2s; }
.cb-tdot:nth-child(3) { animation-delay: 0.4s; }
@keyframes cbTyping {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
    30%           { transform: translateY(-5px); opacity: 1; }
}

/* Error message */
.cb-error-msg {
    color: #fca5a5;
    background: rgba(239, 68, 68, 0.12);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12px;
    margin: 6px 0;
}

/* Data Table */
.cb-table-wrap {
    overflow-x: auto;
    margin: 8px 0;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.cb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11.5px;
}
.cb-table th {
    background: rgba(255, 255, 255, 0.05);
    color: #b0b0d0;
    font-weight: 600;
    padding: 7px 8px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    white-space: nowrap;
    font-size: 11px;
}
.cb-table td {
    padding: 6px 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    color: #9090b0;
    white-space: nowrap;
}
.cb-table tr:last-child td { border-bottom: none; }
.cb-table tr:hover td { background: rgba(255, 255, 255, 0.03); }

/* Calculations */
.cb-calc {
    margin: 10px 0;
    padding: 12px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.07);
}
.cb-calc-title {
    font-size: 12px;
    font-weight: 700;
    color: #a89ff5;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px dashed rgba(255, 255, 255, 0.08);
}
.cb-calc-step {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
    font-size: 12px;
}
.cb-calc-label {
    color: #7b7d9d;
    display: flex;
    align-items: center;
    gap: 4px;
}
.cb-calc-formula {
    font-size: 10px;
    color: #5a5c7a;
    font-style: italic;
}
.cb-calc-value {
    font-weight: 600;
    color: #d0d0f0;
    font-family: 'Courier New', monospace;
}
.cb-calc-op {
    font-weight: 700;
    color: #5a5c7a;
    width: 14px;
    text-align: center;
    font-size: 14px;
}
.cb-calc-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.08);
    margin: 8px 0;
}
.cb-calc-result {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0 0;
    font-size: 13px;
    font-weight: 700;
}
.cb-calc-result .cb-calc-value.positive { color: #2ecc71; }
.cb-calc-result .cb-calc-value.negative { color: #e74c3c; }
.cb-calc-hl {
    background: rgba(124, 115, 230, 0.08);
    border-radius: 6px;
    padding: 3px 6px;
    margin: 2px -6px;
}

/* Chart */
.cb-chart-wrap {
    width: 100%;
    height: 220px;
    margin: 8px 0;
    padding: 8px;
    background: rgba(255, 255, 255, 0.02);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.07);
}

/* Proof Gallery */
.cb-proof {
    margin: 8px 0;
    padding: 10px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.07);
}
.cb-proof-title {
    font-size: 12px;
    font-weight: 600;
    color: #b0b0d0;
    margin-bottom: 8px;
}
.cb-proof-items { display: flex; flex-wrap: wrap; gap: 8px; }
.cb-proof-thumb {
    display: block;
    width: 68px;
    height: 68px;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.08);
    transition: border-color 0.2s, transform 0.2s;
}
.cb-proof-thumb:hover {
    border-color: #7c73e6;
    transform: scale(1.05);
}
.cb-proof-thumb img { width: 100%; height: 100%; object-fit: cover; }
.cb-proof-file {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #a89ff5;
    font-size: 12px;
    text-decoration: none;
    transition: background 0.2s;
}
.cb-proof-file:hover { background: rgba(255, 255, 255, 0.07); }

/* Input Area */
.cb-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: #1a1d30;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    flex-shrink: 0;
}
.cb-input {
    flex: 1;
    padding: 9px 14px;
    border-radius: 22px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.05);
    color: #e0e0f0;
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s, background 0.2s;
}
.cb-input:focus {
    border-color: rgba(124, 115, 230, 0.5);
    background: rgba(255, 255, 255, 0.07);
}
.cb-input::placeholder { color: #4a4c6a; }
.cb-mic-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #7b7d9d;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.2s;
    flex-shrink: 0;
}
.cb-mic-btn:hover, .cb-mic-btn.cb-mic-active {
    background: rgba(124, 115, 230, 0.2);
    border-color: #7c73e6;
    color: #a89ff5;
}
.cb-send-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: transform 0.2s, opacity 0.2s;
    flex-shrink: 0;
}
.cb-send-btn:hover { transform: scale(1.08); }
.cb-send-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }

/* Footer Bar */
.cb-footer-bar {
    text-align: center;
    font-size: 10px;
    color: #3a3c5a;
    padding: 5px 8px;
    background: #1a1d30;
    border-top: 1px solid rgba(255, 255, 255, 0.04);
    flex-shrink: 0;
    letter-spacing: 0.2px;
}

/* Responsive */
@media (max-width: 768px) {
    .cb-window {
        width: 100vw;
        height: 100vh;
        bottom: 0;
        right: 0;
        border-radius: 0;
        border: none;
    }
    .cb-toggle {
        bottom: 20px;
        right: 20px;
        width: 52px;
        height: 52px;
        font-size: 20px;
    }
}

@media print {
    .cb-toggle, .cb-window { display: none !important; }
}
</style>

<script>
// Load Chart.js if not already present
if (typeof Chart === 'undefined') {
    var _cbChartScript = document.createElement('script');
    _cbChartScript.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    document.head.appendChild(_cbChartScript);
}

var SRChatbot = (function() {
    'use strict';

    var API_URL    = '<?php echo $chatbotApiUrl; ?>';
    var ADMIN_NAME = '<?php echo $chatbotAdminName; ?>';
    var isOpen     = false;
    var isLoading  = false;
    var hasShownWelcome = false;
    var chartInstances  = [];
    var pendingCharts   = [];
    var recognition     = null;

    /* ── Toggle ─────────────────────────────────────────── */
    function toggle() {
        var win = document.getElementById('chatbot-window');
        var btn = document.getElementById('chatbot-toggle');
        if (!win) return;
        isOpen = !isOpen;
        if (isOpen) {
            win.style.display = 'flex';
            btn.innerHTML = '<i class="fas fa-times"></i>';
            if (!hasShownWelcome) { showWelcome(); hasShownWelcome = true; }
            document.getElementById('chatbot-input').focus();
        } else {
            win.style.display = 'none';
            btn.innerHTML = '<i class="fas fa-robot"></i><span class="cb-pulse"></span>';
        }
    }

    /* ── Welcome message ────────────────────────────────── */
    function showWelcome() {
        var container = document.getElementById('chatbot-messages');

        // Date separator
        var sep = document.createElement('div');
        sep.className = 'cb-date-sep';
        sep.textContent = 'Today';
        container.appendChild(sep);

        // Bot bubble
        var row = document.createElement('div');
        row.className = 'cb-msg cb-msg-bot';

        var bubble = document.createElement('div');
        bubble.className = 'cb-bubble cb-bubble-bot';

        bubble.innerHTML =
            '<div style="margin-bottom:8px;font-size:14px;">' +
                '<strong>👋 Hello, ' + escHtml(ADMIN_NAME) + '! I\'m your SR Colony Chitti AI Assistant.</strong>' +
            '</div>' +
            '<div style="font-size:12.5px;color:#9090b0;line-height:1.6;">' +
                'I have live access to your collection data, member records, and transaction history. ' +
                'Ask me anything about your chitti fund.' +
            '</div>' +
            '<div class="cb-stats-row">' +
                '<div class="cb-stat-card">' +
                    '<div class="cb-stat-lbl">Members</div>' +
                    '<div class="cb-stat-val">&mdash;</div>' +
                    '<div class="cb-stat-loading"><span class="cb-loading-dot"></span> Loading...</div>' +
                '</div>' +
                '<div class="cb-stat-card">' +
                    '<div class="cb-stat-lbl">Today</div>' +
                    '<div class="cb-stat-val">&mdash;</div>' +
                    '<div class="cb-stat-hint">transactions</div>' +
                '</div>' +
                '<div class="cb-stat-card">' +
                    '<div class="cb-stat-lbl">Net Balance</div>' +
                    '<div class="cb-stat-val">&mdash;</div>' +
                    '<div class="cb-stat-hint">total</div>' +
                '</div>' +
            '</div>';

        var time = document.createElement('div');
        time.className = 'cb-time';
        time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        row.appendChild(bubble);
        row.appendChild(time);
        container.appendChild(row);
        scrollBottom();
    }

    /* ── Quick send ─────────────────────────────────────── */
    function quickSend(text) {
        var input = document.getElementById('chatbot-input');
        if (!input || isLoading) return;
        input.value = text;
        send();
    }

    /* ── Voice input ────────────────────────────────────── */
    function voiceInput() {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            alert('Voice input is not supported in this browser. Please use Chrome or Edge.');
            return;
        }
        var micBtn = document.getElementById('cb-mic');
        if (recognition) {
            recognition.stop();
            recognition = null;
            micBtn.classList.remove('cb-mic-active');
            return;
        }
        recognition = new SpeechRecognition();
        recognition.lang = 'en-IN';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        micBtn.classList.add('cb-mic-active');
        recognition.start();
        recognition.onresult = function(e) {
            var transcript = e.results[0][0].transcript;
            document.getElementById('chatbot-input').value = transcript;
            micBtn.classList.remove('cb-mic-active');
            recognition = null;
            send();
        };
        recognition.onerror = recognition.onend = function() {
            micBtn.classList.remove('cb-mic-active');
            recognition = null;
        };
    }

    /* ── Send message ───────────────────────────────────── */
    function send() {
        var input = document.getElementById('chatbot-input');
        if (!input) return;
        var text = input.value.trim();
        if (!text || isLoading) return;

        input.value = '';
        renderUserMsg(text);
        showTyping();
        isLoading = true;
        document.getElementById('chatbot-send').disabled = true;

        fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ query: text })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            hideTyping();
            isLoading = false;
            document.getElementById('chatbot-send').disabled = false;
            renderBotMsg(data);
        })
        .catch(function(err) {
            hideTyping();
            isLoading = false;
            document.getElementById('chatbot-send').disabled = false;
            renderBotMsg({ answer: 'Sorry, I encountered an error. Please try again.', error: err.message });
        });
    }

    /* ── Render user bubble ─────────────────────────────── */
    function renderUserMsg(text) {
        var container = document.getElementById('chatbot-messages');
        var row = document.createElement('div');
        row.className = 'cb-msg cb-msg-user';

        var bubble = document.createElement('div');
        bubble.className = 'cb-bubble cb-bubble-user';
        bubble.textContent = text;

        var time = document.createElement('div');
        time.className = 'cb-time';
        time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        row.appendChild(bubble);
        row.appendChild(time);
        container.appendChild(row);
        scrollBottom();
    }

    /* ── Render bot bubble ──────────────────────────────── */
    function renderBotMsg(data) {
        var container = document.getElementById('chatbot-messages');
        var row = document.createElement('div');
        row.className = 'cb-msg cb-msg-bot';

        var bubble = document.createElement('div');
        bubble.className = 'cb-bubble cb-bubble-bot';

        if (data.answer) {
            var textDiv = document.createElement('div');
            textDiv.innerHTML = fmtText(data.answer);
            bubble.appendChild(textDiv);
        }
        if (data.error) {
            var errDiv = document.createElement('div');
            errDiv.className = 'cb-error-msg';
            errDiv.textContent = 'Error: ' + data.error;
            bubble.appendChild(errDiv);
        }
        if (data.calculations) bubble.appendChild(renderCalc(data.calculations));
        if (data.charts)       bubble.appendChild(renderChart(data.charts));
        if (data.data && data.data.table) bubble.appendChild(renderTable(data.data.table));
        if (data.proof && data.proof.length) bubble.appendChild(renderProof(data.proof));

        var time = document.createElement('div');
        time.className = 'cb-time';
        time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        row.appendChild(bubble);
        row.appendChild(time);
        container.appendChild(row);
        scrollBottom();
        initPendingCharts();
    }

    /* ── Text formatter ─────────────────────────────────── */
    function fmtText(text) {
        text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\n/g, '<br>');
        return text;
    }

    /* ── Calculations ───────────────────────────────────── */
    function renderCalc(calc) {
        var div = document.createElement('div');
        div.className = 'cb-calc';
        if (calc.title) {
            var t = document.createElement('div');
            t.className = 'cb-calc-title';
            t.innerHTML = '<i class="fas fa-calculator"></i> ' + escHtml(calc.title);
            div.appendChild(t);
        }
        if (calc.steps) {
            calc.steps.forEach(function(step) {
                var row = document.createElement('div');
                row.className = 'cb-calc-step' + (step.highlight ? ' cb-calc-hl' : '');
                var lw = document.createElement('div');
                lw.className = 'cb-calc-label';
                if (step.operator) {
                    var op = document.createElement('span');
                    op.className = 'cb-calc-op';
                    op.textContent = step.operator;
                    lw.appendChild(op);
                }
                var lbl = document.createElement('span');
                lbl.textContent = step.label;
                lw.appendChild(lbl);
                if (step.formula) {
                    var f = document.createElement('span');
                    f.className = 'cb-calc-formula';
                    f.textContent = ' (' + step.formula + ')';
                    lw.appendChild(f);
                }
                var val = document.createElement('span');
                val.className = 'cb-calc-value';
                val.textContent = step.formatted || step.value;
                row.appendChild(lw);
                row.appendChild(val);
                div.appendChild(row);
            });
        }
        if (calc.final_result) {
            var divider = document.createElement('div');
            divider.className = 'cb-calc-divider';
            div.appendChild(divider);
            var res = document.createElement('div');
            res.className = 'cb-calc-result';
            var rl = document.createElement('div');
            rl.className = 'cb-calc-label';
            var rlt = document.createElement('span');
            rlt.textContent = calc.final_result.label;
            rl.appendChild(rlt);
            if (calc.final_result.formula) {
                var rf = document.createElement('span');
                rf.className = 'cb-calc-formula';
                rf.textContent = ' (' + calc.final_result.formula + ')';
                rl.appendChild(rf);
            }
            var rv = document.createElement('span');
            rv.className = 'cb-calc-value ' + (calc.final_result.positive ? 'positive' : 'negative');
            rv.textContent = (calc.final_result.positive ? '' : '-') + (calc.final_result.formatted || calc.final_result.value);
            res.appendChild(rl);
            res.appendChild(rv);
            div.appendChild(res);
        }
        return div;
    }

    /* ── Chart ──────────────────────────────────────────── */
    function renderChart(cfg) {
        var wrap = document.createElement('div');
        wrap.className = 'cb-chart-wrap';
        var canvas = document.createElement('canvas');
        var id = 'cb-chart-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
        canvas.id = id;
        wrap.appendChild(canvas);
        pendingCharts.push({ id: id, cfg: cfg });
        return wrap;
    }

    function initPendingCharts() {
        if (!pendingCharts.length) return;
        function tryInit() {
            if (typeof Chart === 'undefined') { setTimeout(tryInit, 200); return; }
            pendingCharts.forEach(function(item) {
                var canvas = document.getElementById(item.id);
                if (!canvas) return;
                var c = item.cfg;
                var isCircular = (c.type === 'pie' || c.type === 'doughnut');
                var textColor = '#9090b0';
                var gridColor = 'rgba(255,255,255,0.06)';
                var opts = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: { display: !!c.title, text: c.title || '', color: textColor, font: { size: 12, weight: '600' } },
                        legend: { display: true, position: isCircular ? 'bottom' : 'top', labels: { color: textColor, font: { size: 10 }, boxWidth: 12, padding: 8 } }
                    }
                };
                if (!isCircular) {
                    opts.scales = {
                        x: { ticks: { color: textColor, font: { size: 10 } }, grid: { color: gridColor } },
                        y: { ticks: { color: textColor, font: { size: 10 } }, grid: { color: gridColor }, beginAtZero: true }
                    };
                }
                chartInstances.push(new Chart(canvas, { type: c.type, data: { labels: c.labels, datasets: c.datasets }, options: opts }));
            });
            pendingCharts = [];
        }
        setTimeout(tryInit, 100);
    }

    /* ── Table ──────────────────────────────────────────── */
    function renderTable(tableData) {
        var wrap = document.createElement('div');
        wrap.className = 'cb-table-wrap';
        var table = document.createElement('table');
        table.className = 'cb-table';
        if (tableData.headers) {
            var thead = document.createElement('thead');
            var tr = document.createElement('tr');
            tableData.headers.forEach(function(h) {
                var th = document.createElement('th');
                th.textContent = h;
                tr.appendChild(th);
            });
            thead.appendChild(tr);
            table.appendChild(thead);
        }
        if (tableData.rows) {
            var tbody = document.createElement('tbody');
            var maxRows = 15;
            var total = tableData.rows.length;
            tableData.rows.slice(0, maxRows).forEach(function(row) {
                var tr = document.createElement('tr');
                row.forEach(function(cell) {
                    var td = document.createElement('td');
                    td.textContent = cell;
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            if (total > maxRows) {
                var tfoot = document.createElement('tfoot');
                var trF = document.createElement('tr');
                var tdF = document.createElement('td');
                tdF.colSpan = tableData.headers ? tableData.headers.length : 1;
                tdF.style.cssText = 'text-align:center;font-size:11px;color:#5a5c7a;padding:8px;cursor:pointer;';
                tdF.textContent = '+ ' + (total - maxRows) + ' more rows (click to show)';
                tdF.onclick = function() {
                    tableData.rows.slice(maxRows).forEach(function(row) {
                        var tr = document.createElement('tr');
                        row.forEach(function(cell) {
                            var td = document.createElement('td');
                            td.textContent = cell;
                            tr.appendChild(td);
                        });
                        tbody.appendChild(tr);
                    });
                    trF.remove();
                };
                trF.appendChild(tdF);
                tfoot.appendChild(trF);
                table.appendChild(tfoot);
            }
        }
        wrap.appendChild(table);
        return wrap;
    }

    /* ── Proof gallery ──────────────────────────────────── */
    function renderProof(proofList) {
        var div = document.createElement('div');
        div.className = 'cb-proof';
        var title = document.createElement('div');
        title.className = 'cb-proof-title';
        title.innerHTML = '<i class="fas fa-paperclip"></i> Proof Attachments (' + proofList.length + ')';
        div.appendChild(title);
        var items = document.createElement('div');
        items.className = 'cb-proof-items';
        proofList.forEach(function(p) {
            var isImg = p.type && p.type.indexOf('image/') === 0;
            var a = document.createElement('a');
            a.href = p.url;
            a.target = '_blank';
            if (isImg) {
                a.className = 'cb-proof-thumb';
                a.title = p.name;
                var img = document.createElement('img');
                img.src = p.thumbnail || p.url;
                img.alt = p.name;
                img.onerror = function() { this.style.display = 'none'; };
                a.appendChild(img);
            } else {
                a.className = 'cb-proof-file';
                a.title = p.name;
                a.innerHTML = '<i class="fas ' + fileIcon(p.type) + '"></i> ' + escHtml(p.name);
            }
            items.appendChild(a);
        });
        div.appendChild(items);
        return div;
    }

    function fileIcon(mime) {
        if (!mime) return 'fa-file';
        if (mime.indexOf('pdf') !== -1) return 'fa-file-pdf';
        if (mime.indexOf('word') !== -1 || mime.indexOf('doc') !== -1) return 'fa-file-word';
        if (mime.indexOf('sheet') !== -1 || mime.indexOf('excel') !== -1 || mime.indexOf('csv') !== -1) return 'fa-file-excel';
        if (mime.indexOf('zip') !== -1 || mime.indexOf('rar') !== -1) return 'fa-file-archive';
        return 'fa-file';
    }

    /* ── Typing indicator ───────────────────────────────── */
    function showTyping() {
        var container = document.getElementById('chatbot-messages');
        var div = document.createElement('div');
        div.className = 'cb-typing';
        div.id = 'cb-typing';
        div.innerHTML = '<span class="cb-tdot"></span><span class="cb-tdot"></span><span class="cb-tdot"></span>';
        container.appendChild(div);
        scrollBottom();
    }
    function hideTyping() {
        var el = document.getElementById('cb-typing');
        if (el) el.remove();
    }

    /* ── Helpers ────────────────────────────────────────── */
    function scrollBottom() {
        var c = document.getElementById('chatbot-messages');
        if (c) setTimeout(function() { c.scrollTop = c.scrollHeight; }, 50);
    }
    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function clearChat() {
        var container = document.getElementById('chatbot-messages');
        if (container) container.innerHTML = '';
        chartInstances.forEach(function(c) { c.destroy(); });
        chartInstances = [];
        hasShownWelcome = false;
        showWelcome();
    }

    return { toggle: toggle, send: send, quickSend: quickSend, voiceInput: voiceInput, clearChat: clearChat };
})();
</script>
