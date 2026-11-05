<?php
/**
 * SR Colony Gemini AI — Superadmin API Endpoint
 * Lives inside /superadmin/ so it shares the exact same session as other superadmin pages.
 */

// Same session start as every other superadmin file
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// Auth — return JSON instead of redirect
if (!isset($_SESSION['superadmin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'answer' => 'Please log in to use the chatbot.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Rate limiting
$_SESSION['gchat_reqs'] = array_values(
    array_filter($_SESSION['gchat_reqs'] ?? [], fn($t) => $t > time() - 60)
);
if (count($_SESSION['gchat_reqs']) >= 30) {
    echo json_encode(['answer' => 'Too many requests. Please wait a moment.']);
    exit;
}
$_SESSION['gchat_reqs'][] = time();

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

// ── Stats endpoint (for welcome card) ────────────────────────────────────────
if (($input['action'] ?? '') === 'stats') {
    try {
        $stats = [];
        $r = $pdo->query("SELECT COUNT(*) as t, SUM(Status='Active') as a FROM members")->fetch(PDO::FETCH_ASSOC);
        $stats['members']        = (int)($r['t'] ?? 0);
        $stats['active_members'] = (int)($r['a'] ?? 0);

        $r = $pdo->query("SELECT COUNT(*) as n FROM transactions WHERE DATE(TransactionDate)=CURDATE()")->fetch(PDO::FETCH_ASSOC);
        $stats['today_count'] = (int)($r['n'] ?? 0);

        $r = $pdo->query("SELECT COALESCE(SUM(CollectionAmount),0) as c, COALESCE(SUM(DistributedAmount),0) as d, COALESCE(SUM(InterestAmount),0) as i FROM transactions")->fetch(PDO::FETCH_ASSOC);
        $net = round((float)$r['c'] - (float)$r['d'] + (float)$r['i'], 2);
        $stats['net_balance']     = '₹' . number_format(abs($net), 2);
        $stats['net_balance_raw'] = $net;

        echo json_encode(['stats' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['stats' => null, 'error' => $e->getMessage()]);
    }
    exit;
}

$query = trim($input['query'] ?? '');
if ($query === '') {
    echo json_encode(['answer' => 'Please type a question to get started.']);
    exit;
}
$query = mb_substr($query, 0, 500);

// ── Gemini API key ────────────────────────────────────────────────────────────
function getGeminiKey(PDO $pdo): string {
    try {
        $stmt = $pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey = 'gemini_api_key' LIMIT 1");
        $stmt->execute();
        return $stmt->fetchColumn() ?: '';
    } catch (Exception $e) { return ''; }
}

$geminiKey = getGeminiKey($pdo);
if ($geminiKey === '') {
    echo json_encode(['answer' => 'Google AI API key not configured. Go to Super Admin → Settings → Google Gemini AI Chatbot to add it.']);
    exit;
}

// ── Fetch live app data ───────────────────────────────────────────────────────
function getAppSnapshot(PDO $pdo): string {
    $lines = [];
    try {
        // Members
        $r = $pdo->query("SELECT COUNT(*) as t, SUM(Status='Active') as a, SUM(Status='Inactive') as i FROM members")->fetch();
        $lines[] = "MEMBERS: Total={$r['t']}, Active={$r['a']}, Inactive={$r['i']}";

        // Transaction totals
        $r = $pdo->query("SELECT COUNT(*) as n, COALESCE(SUM(CollectionAmount),0) as c, COALESCE(SUM(DistributedAmount),0) as d, COALESCE(SUM(InterestAmount),0) as i FROM transactions")->fetch();
        $lines[] = "TRANSACTIONS: Count={$r['n']}, Collection=₹{$r['c']}, Distribution=₹{$r['d']}, Interest=₹{$r['i']}";
        $netBal = round((float)$r['c'] - (float)$r['d'] + (float)$r['i'], 2);
        $lines[] = "NET BALANCE: ₹{$netBal}";

        // Today
        $r = $pdo->query("SELECT COUNT(*) as n, COALESCE(SUM(CollectionAmount),0) as c FROM transactions WHERE DATE(TransactionDate)=CURDATE()")->fetch();
        $lines[] = "TODAY: {$r['n']} transactions, ₹{$r['c']} collected";

        // This month
        $r = $pdo->query("SELECT COUNT(*) as n, COALESCE(SUM(CollectionAmount),0) as c FROM transactions WHERE MONTH(TransactionDate)=MONTH(CURDATE()) AND YEAR(TransactionDate)=YEAR(CURDATE())")->fetch();
        $lines[] = "THIS MONTH: {$r['n']} transactions, ₹{$r['c']} collected";

        // Academic years
        $rows = $pdo->query("SELECT ay.YearName, ay.InterestRate, ay.Status, COUNT(t.TransactionID) as n, COALESCE(SUM(t.CollectionAmount),0) as c, COALESCE(SUM(t.DistributedAmount),0) as d, COALESCE(SUM(t.InterestAmount),0) as i FROM academic_years ay LEFT JOIN transactions t ON ay.YearID=t.YearID GROUP BY ay.YearID ORDER BY ay.YearName DESC")->fetchAll();
        $lines[] = "ACADEMIC YEARS:";
        foreach ($rows as $y) {
            $lines[] = "  {$y['YearName']} [{$y['Status']}] Rate={$y['InterestRate']}%: ₹{$y['c']} collected, ₹{$y['d']} distributed, ₹{$y['i']} interest, {$y['n']} transactions";
        }

        // Recent transactions
        $rows = $pdo->query("SELECT t.TransactionDate, t.CollectionAmount, t.DistributedAmount, t.InterestAmount, t.PaymentMode, m.MemberName, ay.YearName FROM transactions t JOIN members m ON t.MemberID=m.MemberID LEFT JOIN academic_years ay ON t.YearID=ay.YearID ORDER BY t.TransactionDate DESC, t.TransactionID DESC LIMIT 10")->fetchAll();
        $lines[] = "RECENT 10 TRANSACTIONS:";
        foreach ($rows as $tx) {
            $lines[] = "  [{$tx['TransactionDate']}] {$tx['MemberName']} | {$tx['YearName']}: Collected=₹{$tx['CollectionAmount']}, Distributed=₹{$tx['DistributedAmount']}, Interest=₹{$tx['InterestAmount']}, Mode={$tx['PaymentMode']}";
        }

        // Top 5 members
        $rows = $pdo->query("SELECT m.MemberName, COALESCE(SUM(t.CollectionAmount),0) as c, COUNT(t.TransactionID) as n FROM members m LEFT JOIN transactions t ON m.MemberID=t.MemberID GROUP BY m.MemberID ORDER BY c DESC LIMIT 5")->fetchAll();
        $lines[] = "TOP 5 MEMBERS BY COLLECTION:";
        foreach ($rows as $i => $m) {
            $lines[] = "  " . ($i+1) . ". {$m['MemberName']}: ₹{$m['c']} ({$m['n']} txns)";
        }

        // Payment modes
        $rows = $pdo->query("SELECT COALESCE(PaymentMode,'Not Specified') as m, COUNT(*) as n, COALESCE(SUM(CollectionAmount),0) as c FROM transactions GROUP BY PaymentMode ORDER BY n DESC")->fetchAll();
        $modes = array_map(fn($r) => "{$r['m']}: {$r['n']} txns (₹{$r['c']})", $rows);
        $lines[] = "PAYMENT MODES: " . implode(', ', $modes);

        // Monthly trend (last 12 months)
        $rows = $pdo->query("SELECT DATE_FORMAT(TransactionDate,'%b %Y') as mo, COUNT(*) as n, COALESCE(SUM(CollectionAmount),0) as c FROM transactions WHERE TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(TransactionDate,'%Y-%m') ORDER BY DATE_FORMAT(TransactionDate,'%Y-%m')")->fetchAll();
        $lines[] = "MONTHLY TREND (last 12 months):";
        foreach ($rows as $r) { $lines[] = "  {$r['mo']}: {$r['n']} txns, ₹{$r['c']} collected"; }

    } catch (PDOException $e) {
        $lines[] = "DB_ERROR: " . $e->getMessage();
    }
    return implode("\n", $lines);
}

$snapshot  = getAppSnapshot($pdo);
$adminName = htmlspecialchars($_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin');
$today     = date('l, Y-m-d \a\t H:i');

// ── Build Gemini prompt ───────────────────────────────────────────────────────
$systemPrompt = <<<PROMPT
You are the SR Colony Chitti Management AI Assistant helping {$adminName}.
Today is {$today}.

RULES:
- Answer ONLY using the data snapshot below. Never make up numbers or names.
- If the data does not contain enough to answer, say "I don't have that data right now."
- Use ₹ for money. Keep answers clear, concise, and well formatted.
- You understand chitti/rotating savings: collection = money paid by members, distribution = money given to a member who wins the chitti, interest = monthly interest charged on distributed amount.

LIVE APPLICATION DATA:
---
{$snapshot}
---
PROMPT;

// ── Call Gemini via file_get_contents (no curl needed) ───────────────────────
function callGemini(string $key, string $system, string $query): array {
    $url  = 'https://api.groq.com/openai/v1/chat/completions';
    $body = json_encode([
        'model'       => 'llama-3.1-8b-instant',
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $query],
        ],
        'temperature' => 0.2,
        'max_tokens'  => 1024,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\n",
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $e = error_get_last();
        return ['error' => 'Cannot reach Groq API: ' . ($e['message'] ?? 'network error')];
    }

    $data     = json_decode($resp, true);
    $httpCode = 200;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) $httpCode = (int)$m[1];
    }

    if ($httpCode !== 200) {
        return ['error' => 'Groq error ' . $httpCode . ': ' . ($data['error']['message'] ?? 'unknown')];
    }

    $text = $data['choices'][0]['message']['content'] ?? null;
    return $text ? ['text' => trim($text)] : ['error' => 'Empty response from Groq'];
}

$result = callGemini($geminiKey, $systemPrompt, $query);

// Audit log
try {
    $pdo->prepare("INSERT INTO audit_logs (UserType,UserID,Action,Details,IPAddress,CreatedAt) VALUES ('SuperAdmin',?,?,?,?,NOW())")
        ->execute([$_SESSION['superadmin_id'], 'Gemini Chatbot', mb_substr($query, 0, 200), $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (Exception $e) { /* silent */ }

if (isset($result['error'])) {
    echo json_encode(['answer' => '⚠️ ' . $result['error']]);
} else {
    echo json_encode([
        'answer'      => $result['text'],
        'suggestions' => ['Show total collection', 'Which member paid the most?', 'Net balance?', 'Recent transactions', 'Monthly trend'],
    ]);
}


