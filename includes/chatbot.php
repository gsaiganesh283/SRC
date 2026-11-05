<?php
/**
 * SR Colony AI Chatbot - Backend API Handler
 * Provides intelligent query processing with intent detection,
 * database queries, calculations, chart configs, and proof display.
 *
 * Endpoint: POST /includes/chatbot.php
 * Request:  { "query": "user question" }
 * Response: { "answer": "...", "data": {...}, "calculations": {...}, "charts": {...}, "proof": [...], "suggestions": [...] }
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

// Require superadmin authentication
if (!isset($_SESSION['superadmin_id'])) {
    echo json_encode(['error' => 'Unauthorized', 'answer' => 'Please log in to use the chatbot.']);
    exit;
}

// Rate limiting: 30 requests per minute
if (!isset($_SESSION['chatbot_requests'])) {
    $_SESSION['chatbot_requests'] = [];
}
$_SESSION['chatbot_requests'] = array_values(array_filter($_SESSION['chatbot_requests'], function($t) {
    return $t > time() - 60;
}));
if (count($_SESSION['chatbot_requests']) >= 30) {
    echo json_encode(['error' => 'Rate limit exceeded', 'answer' => 'Too many requests. Please wait a moment and try again.']);
    exit;
}
$_SESSION['chatbot_requests'][] = time();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed', 'answer' => 'Invalid request method.']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$query = isset($input['query']) ? trim($input['query']) : '';

if (empty($query)) {
    echo json_encode(['error' => 'Empty query', 'answer' => 'Please type a question to get started.']);
    exit;
}

// Sanitize for logging (but keep original for intent parsing)
$sanitizedQuery = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');

// Log chatbot usage to audit
try {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (UserType, UserID, Action, Details, IPAddress, CreatedAt) VALUES ('SuperAdmin', ?, 'Chatbot Query', ?, ?, NOW())");
    $stmt->execute([$_SESSION['superadmin_id'], 'Query: ' . substr($sanitizedQuery, 0, 200), $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (Exception $e) {
    // Silent fail for audit logging
}

// ============================================================
// INTENT DETECTION
// ============================================================

function analyzeIntent($query) {
    $q = strtolower($query);

    // Intent keyword definitions with weights
    $intents = [
        'greeting' => [
            'keywords' => ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'howdy', 'greetings', 'namaste', 'hola', 'welcome', 'thank', 'thanks', 'thank you', 'bye', 'goodbye', 'good night', 'see you'],
            'weight' => 3
        ],
        'member_search' => [
            'keywords' => ['find member', 'search member', 'look up member', 'member named', 'member name', 'member id', 'who is member', 'details of member', 'info about member', 'particular member', 'specific member', 'which member', 'member details', 'member profile'],
            'weight' => 3
        ],
        'member_info' => [
            'keywords' => ['member', 'members', 'contact', 'phone', 'email', 'address', 'aadhar', 'profile', 'active member', 'inactive member', 'total member', 'new member', 'joined', 'registered', 'member list', 'all members'],
            'weight' => 1
        ],
        'transaction_summary' => [
            'keywords' => ['total collection', 'total distribution', 'transaction summary', 'sum of', 'overall collection', 'overall distribution', 'total amount', 'how much collected', 'how much distributed', 'financial summary', 'collection amount', 'distribution amount', 'revenue', 'income', 'money collected', 'money distributed'],
            'weight' => 3
        ],
        'transaction_details' => [
            'keywords' => ['transaction', 'transactions', 'payment', 'payments', 'payment history', 'transaction history', 'transaction record', 'show transaction', 'list transaction', 'recent transaction', 'transaction list', 'all transaction', 'transaction data', 'transaction table'],
            'weight' => 1
        ],
        'year_summary' => [
            'keywords' => ['year', 'years', 'annual', 'yearly', 'academic year', 'year wise', 'year-wise', 'yearwise', 'per year', 'each year', 'chitti year', 'compare year', 'year comparison', 'which year', 'best year', 'worst year'],
            'weight' => 2
        ],
        'statistics' => [
            'keywords' => ['statistic', 'statistics', 'stats', 'average', 'avg', 'maximum', 'minimum', 'highest', 'lowest', 'how many', 'count of', 'total count', 'overview', 'summary', 'dashboard', 'snapshot', 'at a glance', 'overall', 'general'],
            'weight' => 2
        ],
        'recent_activity' => [
            'keywords' => ['recent', 'latest', 'last', 'newest', 'today', 'recent activity', 'what happened', 'latest activity', 'today activity', 'new activity', 'current activity', 'what is new', 'what\'s new', 'updates', 'update'],
            'weight' => 2
        ],
        'verification' => [
            'keywords' => ['verify', 'verified', 'unverified', 'pending', 'verification', 'approval', 'pending verification', 'not verified', 'approved', 'unapproved', 'confirmation'],
            'weight' => 2
        ],
        'payment_modes' => [
            'keywords' => ['payment mode', 'cash', 'online', 'cheque', 'bank transfer', 'upi', 'neft', 'rtgs', 'mode of payment', 'how do people pay', 'payment method', 'payment type', 'gpay', 'phonepe', 'paytm', 'digital payment', 'cash payment'],
            'weight' => 2
        ],
        'monthly_analysis' => [
            'keywords' => ['monthly', 'month wise', 'month-wise', 'monthwise', 'per month', 'each month', 'monthly trend', 'monthly collection', 'monthly report',
                'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
                'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'],
            'weight' => 2
        ],
        'expense_info' => [
            'keywords' => ['expense', 'expenses', 'cost', 'spending', 'budget', 'vendor', 'expenditure', 'spent', 'expense report', 'how much spent', 'expense category', 'expense breakdown', 'expense summary', 'total expense', 'expense analysis', 'expense list', 'expense detail', 'maintenance cost', 'repair cost'],
            'weight' => 2
        ],
        'budget_info' => [
            'keywords' => ['budget', 'budget tracking', 'budget vs actual', 'budget comparison', 'over budget', 'under budget', 'budget allocation', 'budget utilization', 'budget report', 'budget status', 'allocated budget', 'remaining budget'],
            'weight' => 3
        ],
        'event_info' => [
            'keywords' => ['event', 'events', 'function', 'ceremony', 'celebration', 'festival', 'program', 'event list', 'event detail', 'event transaction', 'event expense', 'event report', 'event summary', 'upcoming event', 'past event', 'event budget', 'event cost'],
            'weight' => 3
        ],
        'admin_info' => [
            'keywords' => ['admin', 'admins', 'administrator', 'administrators', 'staff', 'manager', 'admin count', 'active admin', 'admin list', 'admin detail', 'who are admin', 'admin role'],
            'weight' => 2
        ],
        'audit_logs' => [
            'keywords' => ['audit', 'audit log', 'audit logs', 'activity log', 'who did', 'change history', 'log entry', 'system log', 'action log', 'track change', 'who changed', 'who modified', 'who deleted', 'who added', 'who updated'],
            'weight' => 2
        ],
        'net_balance' => [
            'keywords' => ['net balance', 'net amount', 'interest amount', 'fixed amount', 'profit', 'surplus', 'deficit', 'balance calculation', 'calculate balance', 'change amount', 'net profit', 'net income', 'net loss', 'financial position', 'bottom line'],
            'weight' => 3
        ],
        'notification_info' => [
            'keywords' => ['notification', 'notifications', 'alert', 'alerts', 'unread', 'message', 'messages', 'inbox', 'bell', 'notify', 'reminder', 'reminders', 'pending notification'],
            'weight' => 2
        ],
        'permission_info' => [
            'keywords' => ['permission', 'permissions', 'access', 'role', 'roles', 'privilege', 'can access', 'allowed', 'restricted', 'authorization', 'who can', 'access control'],
            'weight' => 2
        ],
        'backup_info' => [
            'keywords' => ['backup', 'backups', 'restore', 'database backup', 'data backup', 'backup history', 'last backup', 'backup status', 'export data', 'import data'],
            'weight' => 2
        ],
        'system_info' => [
            'keywords' => ['system', 'database', 'database size', 'setting', 'settings', 'health', 'system status', 'system info', 'db size', 'server', 'php version', 'version', 'uptime', 'performance'],
            'weight' => 2
        ],
        'comparison' => [
            'keywords' => ['compare', 'comparison', 'versus', 'vs', 'difference between', 'between', 'growth', 'trend', 'increase', 'decrease', 'change from', 'better', 'worse', 'more than', 'less than', 'improvement'],
            'weight' => 2
        ],
        'top_performers' => [
            'keywords' => ['top', 'best', 'highest', 'most', 'leading', 'top member', 'top collector', 'best performer', 'highest collection', 'most active', 'who collected most', 'who paid most', 'rank', 'ranking', 'leaderboard'],
            'weight' => 3
        ],
        'defaulters' => [
            'keywords' => ['defaulter', 'defaulters', 'due', 'overdue', 'not paid', 'pending payment', 'outstanding', 'unpaid', 'who has not paid', 'missing payment', 'missed payment', 'late payment', 'delinquent'],
            'weight' => 3
        ],
        'help' => [
            'keywords' => ['help', 'what can you do', 'commands', 'guide', 'how to use', 'capabilities', 'features', 'what do you know', 'assist', 'support', 'how does'],
            'weight' => 2
        ]
    ];

    // Score each intent
    $scores = [];
    foreach ($intents as $intentName => $config) {
        $score = 0;
        foreach ($config['keywords'] as $keyword) {
            if (strpos($q, $keyword) !== false) {
                $score += $config['weight'];
                // Bonus for longer/more specific keyword matches
                if (strlen($keyword) > 10) $score += 1;
            }
        }
        $scores[$intentName] = $score;
    }

    // Get best match
    arsort($scores);
    $bestIntent = key($scores);
    $bestScore = current($scores);

    // If no match, try smart fallback - attempt to answer using general stats
    if ($bestScore === 0) {
        $bestIntent = 'smart_fallback';
    }

    // Extract parameters
    $params = extractParams($q);

    return ['intent' => $bestIntent, 'params' => $params, 'score' => $bestScore];
}

function extractParams($q) {
    $params = [
        'member_id' => null,
        'member_name' => null,
        'year' => null,
        'month' => null,
        'date_range' => null,
        'limit' => null
    ];

    // Extract member ID
    if (preg_match('/member\s*(?:id|#|no\.?|number)?\s*(\d+)/i', $q, $m)) {
        $params['member_id'] = (int)$m[1];
    }

    // Extract member name (after "member named", "member name", "find member")
    if (preg_match('/(?:member\s+named?|find\s+member|search\s+member|details\s+(?:of|for)\s+member|info\s+(?:about|for)\s+member)\s+([a-zA-Z\s]+)/i', $q, $m)) {
        $name = trim($m[1]);
        // Remove trailing common words
        $name = preg_replace('/\s+(please|thanks|thank|info|details|data)$/i', '', $name);
        if (strlen($name) >= 2) {
            $params['member_name'] = $name;
        }
    }

    // Extract academic year (e.g., 2025-2026 or 2025/2026)
    if (preg_match('/(\d{4})\s*[-\/]\s*(\d{4})/', $q, $m)) {
        $params['year'] = $m[1] . '-' . $m[2];
    }

    // Extract month name
    $months = ['january','february','march','april','may','june','july','august','september','october','november','december'];
    $monthShort = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    foreach ($months as $i => $month) {
        if (strpos($q, $month) !== false || strpos($q, $monthShort[$i]) !== false) {
            $params['month'] = $i + 1;
            break;
        }
    }

    // Extract date range keywords
    if (strpos($q, 'this month') !== false) {
        $params['date_range'] = 'this_month';
    } elseif (strpos($q, 'this year') !== false) {
        $params['date_range'] = 'this_year';
    } elseif (strpos($q, 'today') !== false) {
        $params['date_range'] = 'today';
    } elseif (strpos($q, 'this week') !== false) {
        $params['date_range'] = 'this_week';
    } elseif (preg_match('/last\s+(\d+)\s+days?/i', $q, $m)) {
        $params['date_range'] = 'last_' . (int)$m[1] . '_days';
    }

    // Extract limit
    if (preg_match('/(?:top|last|recent|first|show)\s+(\d+)/i', $q, $m)) {
        $params['limit'] = min((int)$m[1], 50); // Cap at 50
    }

    return $params;
}

// ============================================================
// DATA FETCHING
// ============================================================

function fetchRelevantData($intent, $params, $pdo) {
    $data = [];

    try {
        // Build optional year filter
        $yearFilter = '';
        $yearParam = [];
        if (!empty($params['year'])) {
            $yearFilter = ' AND ay.YearName = ?';
            $yearParam = [$params['year']];
        }

        // Build optional date filter
        $dateFilter = '';
        $dateParam = [];
        if (!empty($params['date_range'])) {
            switch ($params['date_range']) {
                case 'today':
                    $dateFilter = ' AND DATE(t.TransactionDate) = CURDATE()';
                    break;
                case 'this_week':
                    $dateFilter = ' AND t.TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
                    break;
                case 'this_month':
                    $dateFilter = ' AND MONTH(t.TransactionDate) = MONTH(CURDATE()) AND YEAR(t.TransactionDate) = YEAR(CURDATE())';
                    break;
                case 'this_year':
                    $dateFilter = ' AND YEAR(t.TransactionDate) = YEAR(CURDATE())';
                    break;
                default:
                    if (preg_match('/last_(\d+)_days/', $params['date_range'], $dm)) {
                        $days = (int)$dm[1];
                        $dateFilter = ' AND t.TransactionDate >= DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)';
                    }
            }
        }

        switch ($intent) {
            case 'member_info':
                // Member counts
                $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN Status = 'Active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN Status = 'Inactive' THEN 1 ELSE 0 END) as inactive FROM members");
                $data['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

                // Recent members
                $stmt = $pdo->query("SELECT MemberID, MemberName, Phone, Email, Status, CreatedAt FROM members ORDER BY CreatedAt DESC LIMIT 10");
                $data['recent_members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Members with most transactions
                $stmt = $pdo->query("SELECT m.MemberID, m.MemberName, COUNT(t.TransactionID) as tx_count, COALESCE(SUM(t.CollectionAmount), 0) as total_collection FROM members m LEFT JOIN transactions t ON m.MemberID = t.MemberID GROUP BY m.MemberID ORDER BY tx_count DESC LIMIT 5");
                $data['top_members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'member_search':
                $searchResults = [];
                if (!empty($params['member_id'])) {
                    $stmt = $pdo->prepare("SELECT m.MemberID, m.MemberName, m.FatherName, m.Phone, m.Email, m.Address, m.AadharNumber, m.Status, m.CreatedAt, COUNT(t.TransactionID) as transaction_count, COALESCE(SUM(t.CollectionAmount), 0) as total_collection, COALESCE(SUM(t.DistributedAmount), 0) as total_distribution, COALESCE(SUM(t.InterestAmount), 0) as total_interest FROM members m LEFT JOIN transactions t ON m.MemberID = t.MemberID WHERE m.MemberID = ? GROUP BY m.MemberID");
                    $stmt->execute([$params['member_id']]);
                    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif (!empty($params['member_name'])) {
                    $stmt = $pdo->prepare("SELECT m.MemberID, m.MemberName, m.FatherName, m.Phone, m.Email, m.Address, m.AadharNumber, m.Status, m.CreatedAt, COUNT(t.TransactionID) as transaction_count, COALESCE(SUM(t.CollectionAmount), 0) as total_collection, COALESCE(SUM(t.DistributedAmount), 0) as total_distribution, COALESCE(SUM(t.InterestAmount), 0) as total_interest FROM members m LEFT JOIN transactions t ON m.MemberID = t.MemberID WHERE m.MemberName LIKE ? GROUP BY m.MemberID LIMIT 10");
                    $stmt->execute(['%' . $params['member_name'] . '%']);
                    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                $data['members'] = $searchResults;

                // Get recent transactions for found members
                if (!empty($searchResults)) {
                    $memberIds = array_column($searchResults, 'MemberID');
                    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
                    $stmt = $pdo->prepare("SELECT t.TransactionID, t.TransactionDate, t.CollectionAmount, t.DistributedAmount, t.InterestAmount, t.PaymentMode, m.MemberName, ay.YearName FROM transactions t JOIN members m ON t.MemberID = m.MemberID LEFT JOIN academic_years ay ON t.YearID = ay.YearID WHERE t.MemberID IN ($placeholders) ORDER BY t.TransactionDate DESC LIMIT 10");
                    $stmt->execute($memberIds);
                    $data['member_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Check for proof attachments
                    $data['proof'] = fetchProofAttachments($pdo, 'transaction', $data['member_transactions']);
                }
                break;

            case 'transaction_summary':
                $yearJoin = '';
                $yearWhere = '';
                $execParams = [];
                if (!empty($params['year'])) {
                    $yearJoin = ' LEFT JOIN academic_years ay ON t.YearID = ay.YearID';
                    $yearWhere = ' WHERE ay.YearName = ?';
                    $execParams[] = $params['year'];
                }

                $sql = "SELECT COUNT(*) as total_transactions, COALESCE(SUM(t.CollectionAmount), 0) as total_collection, COALESCE(SUM(t.DistributedAmount), 0) as total_distribution, COALESCE(SUM(t.InterestAmount), 0) as total_interest FROM transactions t" . $yearJoin . $yearWhere;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($execParams);
                $data['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

                // Today's stats
                $stmt = $pdo->query("SELECT COUNT(*) as today_count, COALESCE(SUM(CollectionAmount), 0) as today_collection FROM transactions WHERE DATE(TransactionDate) = CURDATE()");
                $data['today'] = $stmt->fetch(PDO::FETCH_ASSOC);

                // This month stats
                $stmt = $pdo->query("SELECT COUNT(*) as month_count, COALESCE(SUM(CollectionAmount), 0) as month_collection FROM transactions WHERE MONTH(TransactionDate) = MONTH(CURDATE()) AND YEAR(TransactionDate) = YEAR(CURDATE())");
                $data['this_month'] = $stmt->fetch(PDO::FETCH_ASSOC);
                break;

            case 'transaction_details':
                $limit = $params['limit'] ?? 20;
                $whereClauses = ['1=1'];
                $execParams = [];

                if (!empty($params['member_id'])) {
                    $whereClauses[] = 't.MemberID = ?';
                    $execParams[] = $params['member_id'];
                }

                $whereStr = implode(' AND ', $whereClauses);
                $stmt = $pdo->prepare("SELECT t.TransactionID, t.TransactionDate, t.CollectionAmount, t.DistributedAmount, t.InterestAmount, t.PaymentMode, t.Remarks, m.MemberName, m.MemberID, ay.YearName FROM transactions t JOIN members m ON t.MemberID = m.MemberID LEFT JOIN academic_years ay ON t.YearID = ay.YearID WHERE $whereStr $dateFilter ORDER BY t.TransactionDate DESC LIMIT " . (int)$limit);
                $stmt->execute($execParams);
                $data['transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get proof attachments for displayed transactions
                $data['proof'] = fetchProofAttachments($pdo, 'transaction', $data['transactions']);

                // Total count
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM transactions");
                $data['total_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                break;

            case 'year_summary':
                $stmt = $pdo->query("SELECT ay.YearID, ay.YearName, ay.StartDate, ay.EndDate, ay.InterestRate, ay.Status, COUNT(t.TransactionID) as transaction_count, COALESCE(SUM(t.CollectionAmount), 0) as total_collection, COALESCE(SUM(t.DistributedAmount), 0) as total_distribution, COALESCE(SUM(t.InterestAmount), 0) as total_interest, COUNT(DISTINCT t.MemberID) as member_count FROM academic_years ay LEFT JOIN transactions t ON ay.YearID = t.YearID GROUP BY ay.YearID ORDER BY ay.YearName DESC");
                $data['years'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'statistics':
                // Overall stats
                $stmt = $pdo->query("SELECT COUNT(*) as total_transactions, COALESCE(AVG(CollectionAmount), 0) as avg_collection, COALESCE(MAX(CollectionAmount), 0) as max_collection, COALESCE(MIN(CASE WHEN CollectionAmount > 0 THEN CollectionAmount END), 0) as min_collection, COALESCE(AVG(DistributedAmount), 0) as avg_distribution, COALESCE(MAX(DistributedAmount), 0) as max_distribution, COALESCE(AVG(InterestAmount), 0) as avg_interest, COALESCE(MAX(InterestAmount), 0) as max_interest FROM transactions");
                $data['stats'] = $stmt->fetch(PDO::FETCH_ASSOC);

                // Counts
                $stmt = $pdo->query("SELECT (SELECT COUNT(*) FROM members) as total_members, (SELECT COUNT(*) FROM members WHERE Status='Active') as active_members, (SELECT COUNT(*) FROM admins) as total_admins, (SELECT COUNT(*) FROM academic_years) as total_years, (SELECT COUNT(*) FROM transactions) as total_transactions");
                $data['counts'] = $stmt->fetch(PDO::FETCH_ASSOC);
                break;

            case 'recent_activity':
                // Recent transactions
                $stmt = $pdo->query("SELECT t.TransactionID, t.TransactionDate, t.CollectionAmount, t.DistributedAmount, t.PaymentMode, m.MemberName, ay.YearName FROM transactions t JOIN members m ON t.MemberID = m.MemberID LEFT JOIN academic_years ay ON t.YearID = ay.YearID ORDER BY t.TransactionDate DESC, t.TransactionID DESC LIMIT 10");
                $data['recent_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Recent audit logs
                $stmt = $pdo->query("SELECT LogID, UserType, Action, Details, IPAddress, CreatedAt FROM audit_logs ORDER BY CreatedAt DESC LIMIT 10");
                $data['recent_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'verification':
                $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN Verified = 1 OR Verified = 'Yes' THEN 1 ELSE 0 END) as verified, SUM(CASE WHEN Verified = 0 OR Verified IS NULL OR Verified = 'No' THEN 1 ELSE 0 END) as unverified FROM transactions");
                $data['verification'] = $stmt->fetch(PDO::FETCH_ASSOC);
                break;

            case 'payment_modes':
                $stmt = $pdo->query("SELECT COALESCE(PaymentMode, 'Not Specified') as mode, COUNT(*) as count, COALESCE(SUM(CollectionAmount), 0) as total_collection, COALESCE(SUM(DistributedAmount), 0) as total_distribution FROM transactions GROUP BY PaymentMode ORDER BY count DESC");
                $data['modes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'monthly_analysis':
                $monthFilter = '';
                $monthParams = [];
                if (!empty($params['month'])) {
                    $monthFilter = ' AND MONTH(TransactionDate) = ?';
                    $monthParams[] = $params['month'];
                }

                $stmt = $pdo->prepare("SELECT DATE_FORMAT(TransactionDate, '%b %Y') as month_label, DATE_FORMAT(TransactionDate, '%Y-%m') as month_sort, COUNT(*) as transaction_count, COALESCE(SUM(CollectionAmount), 0) as collection, COALESCE(SUM(DistributedAmount), 0) as distribution, COALESCE(SUM(InterestAmount), 0) as interest FROM transactions WHERE TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $monthFilter GROUP BY DATE_FORMAT(TransactionDate, '%Y-%m') ORDER BY month_sort ASC");
                $stmt->execute($monthParams);
                $data['monthly'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'expense_info':
                // Check if expenses table exists
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as total_expenses, COALESCE(SUM(Amount), 0) as total_amount FROM expenses");
                    $data['expense_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $pdo->query("SELECT COALESCE(Category, 'Uncategorized') as category, COUNT(*) as count, COALESCE(SUM(Amount), 0) as amount FROM expenses GROUP BY Category ORDER BY amount DESC");
                    $data['expense_by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Recent expenses
                    $stmt = $pdo->query("SELECT ExpenseID, Description, Amount, Category, ExpenseDate, Vendor FROM expenses ORDER BY ExpenseDate DESC LIMIT 10");
                    $data['recent_expenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Get proof for expenses
                    $data['proof'] = fetchProofAttachments($pdo, 'expense', $data['recent_expenses']);
                } catch (Exception $e) {
                    $data['expense_summary'] = ['total_expenses' => 0, 'total_amount' => 0];
                    $data['expense_by_category'] = [];
                    $data['recent_expenses'] = [];
                    $data['expense_error'] = 'Expense tracking module is not set up.';
                }
                break;

            case 'admin_info':
                $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN Status = 'Active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN Status = 'Inactive' THEN 1 ELSE 0 END) as inactive FROM admins");
                $data['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->query("SELECT AdminID, Username, FullName, Email, Phone, Role, Status, LastLogin FROM admins ORDER BY AdminID LIMIT 10");
                $data['admins'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'audit_logs':
                $limit = $params['limit'] ?? 15;
                $stmt = $pdo->prepare("SELECT LogID, UserType, Action, Details, TableName, IPAddress, CreatedAt FROM audit_logs ORDER BY CreatedAt DESC LIMIT " . (int)$limit);
                $stmt->execute();
                $data['logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Audit log stats
                $stmt = $pdo->query("SELECT COUNT(*) as total, COUNT(DISTINCT UserType) as user_types, COUNT(DISTINCT Action) as action_types FROM audit_logs");
                $data['stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
                break;

            case 'net_balance':
                // Step-by-step net balance calculation
                $yearWhere = '';
                $yearWhereExp = '';
                $execParams = [];
                $execParamsExp = [];

                if (!empty($params['year'])) {
                    $stmt = $pdo->prepare("SELECT YearID FROM academic_years WHERE YearName = ? LIMIT 1");
                    $stmt->execute([$params['year']]);
                    $yearRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($yearRow) {
                        $yearWhere = ' WHERE YearID = ?';
                        $yearWhereExp = ' WHERE YearID = ?';
                        $execParams = [$yearRow['YearID']];
                        $execParamsExp = [$yearRow['YearID']];
                    }
                }

                // Total Interest
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(InterestAmount), 0) as value FROM transactions" . $yearWhere);
                $stmt->execute($execParams);
                $data['interest'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['value'];

                // Total Collection
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(CollectionAmount), 0) as value FROM transactions" . $yearWhere);
                $stmt->execute($execParams);
                $data['collection'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['value'];

                // Total Distribution
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(DistributedAmount), 0) as value FROM transactions" . $yearWhere);
                $stmt->execute($execParams);
                $data['distribution'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['value'];

                // Total Fixed Amount (from academic_years)
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(FixedAmount), 0) as value FROM academic_years" . ($yearWhere ? ' WHERE YearName = ?' : ''));
                    $stmt->execute(!empty($params['year']) ? [$params['year']] : []);
                    $data['fixed_amount'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['value'];
                } catch (Exception $e) {
                    $data['fixed_amount'] = 0;
                }

                // Total Change Amount (from academic_years)
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ChangeAmount), 0) as value FROM academic_years" . ($yearWhere ? ' WHERE YearName = ?' : ''));
                    $stmt->execute(!empty($params['year']) ? [$params['year']] : []);
                    $data['change_amount'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['value'];
                } catch (Exception $e) {
                    $data['change_amount'] = 0;
                }

                // Total Expenses
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) as value FROM expenses" . $yearWhereExp);
                    $stmt->execute($execParamsExp);
                    $data['expenses'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['value'];
                } catch (Exception $e) {
                    $data['expenses'] = 0;
                }

                // Calculate net balance
                $data['net_balance'] = ($data['interest'] + $data['fixed_amount'] + $data['change_amount']) - $data['expenses'];
                break;

            case 'system_info':
                // Database size
                $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.TABLES WHERE table_schema = DATABASE()");
                $data['db_size'] = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'] ?? '0';

                // Table counts
                $stmt = $pdo->query("SELECT 'Members' as name, COUNT(*) as count FROM members UNION ALL SELECT 'Admins', COUNT(*) FROM admins UNION ALL SELECT 'Transactions', COUNT(*) FROM transactions UNION ALL SELECT 'Academic Years', COUNT(*) FROM academic_years UNION ALL SELECT 'Audit Logs', COUNT(*) FROM audit_logs UNION ALL SELECT 'Notifications', COUNT(*) FROM notifications");
                $data['table_counts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // System settings
                try {
                    $stmt = $pdo->query("SELECT SettingKey, SettingValue FROM system_settings WHERE SettingKey IN ('site_name', 'site_email', 'currency_symbol', 'date_format', 'timezone') LIMIT 10");
                    $data['settings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $data['settings'] = [];
                }

                $data['php_version'] = phpversion();
                $data['server_time'] = date('Y-m-d H:i:s');
                break;

            case 'greeting':
                $data['greeting'] = true;
                $data['admin_name'] = $_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin';
                // Quick stats for greeting context
                try {
                    $stmt = $pdo->query("SELECT (SELECT COUNT(*) FROM members) as members, (SELECT COUNT(*) FROM transactions) as transactions, (SELECT COUNT(*) FROM members WHERE Status='Active') as active_members");
                    $data['quick_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt = $pdo->query("SELECT COUNT(*) as today_tx FROM transactions WHERE DATE(TransactionDate) = CURDATE()");
                    $data['today'] = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) { /* silent */ }
                break;

            case 'budget_info':
                try {
                    // Budget vs actual expenses
                    $stmt = $pdo->query("SELECT COALESCE(SUM(Amount), 0) as total_spent FROM expenses");
                    $data['total_spent'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total_spent'];

                    $stmt = $pdo->query("SELECT COALESCE(Category, 'Uncategorized') as category, COUNT(*) as count, COALESCE(SUM(Amount), 0) as amount FROM expenses GROUP BY Category ORDER BY amount DESC");
                    $data['by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Monthly expense trend
                    $stmt = $pdo->query("SELECT DATE_FORMAT(ExpenseDate, '%b %Y') as month_label, DATE_FORMAT(ExpenseDate, '%Y-%m') as month_sort, COALESCE(SUM(Amount), 0) as amount, COUNT(*) as count FROM expenses WHERE ExpenseDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(ExpenseDate, '%Y-%m') ORDER BY month_sort ASC");
                    $data['monthly_expenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Total collection for ratio
                    $stmt = $pdo->query("SELECT COALESCE(SUM(CollectionAmount), 0) as total_collection FROM transactions");
                    $data['total_collection'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total_collection'];
                } catch (Exception $e) {
                    $data['error_msg'] = 'Budget tracking data is not available.';
                }
                break;

            case 'event_info':
                try {
                    // Events summary
                    $stmt = $pdo->query("SELECT COUNT(*) as total_events, SUM(CASE WHEN Status = 'Active' OR Status = 'Ongoing' THEN 1 ELSE 0 END) as active_events, SUM(CASE WHEN Status = 'Completed' THEN 1 ELSE 0 END) as completed_events FROM events");
                    $data['event_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Recent events
                    $stmt = $pdo->query("SELECT EventID, EventName, EventDate, Venue, Status, Budget, COALESCE(TotalExpense, 0) as TotalExpense FROM events ORDER BY EventDate DESC LIMIT 10");
                    $data['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Event transaction totals
                    $stmt = $pdo->query("SELECT e.EventName, COUNT(et.TransactionID) as tx_count, COALESCE(SUM(et.Amount), 0) as total_amount FROM events e LEFT JOIN event_transactions et ON e.EventID = et.EventID GROUP BY e.EventID ORDER BY total_amount DESC LIMIT 10");
                    $data['event_financials'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $data['event_summary'] = ['total_events' => 0, 'active_events' => 0, 'completed_events' => 0];
                    $data['events'] = [];
                    $data['event_error'] = 'Events module is not set up yet. Set it up from Accounting > Events.';
                }
                break;

            case 'notification_info':
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN IsRead = 0 THEN 1 ELSE 0 END) as unread, SUM(CASE WHEN IsRead = 1 THEN 1 ELSE 0 END) as read_count FROM notifications");
                    $data['notification_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $pdo->query("SELECT NotificationID, Title, Message, NotificationType, Priority, IsRead, CreatedAt FROM notifications ORDER BY CreatedAt DESC LIMIT 10");
                    $data['recent_notifications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $data['notification_summary'] = ['total' => 0, 'unread' => 0, 'read_count' => 0];
                    $data['recent_notifications'] = [];
                }
                break;

            case 'permission_info':
                try {
                    $stmt = $pdo->query("SELECT a.AdminID, a.Username, a.FullName, a.Role, a.Status, ap.can_view_members, ap.can_add_members, ap.can_edit_members, ap.can_delete_members, ap.can_view_transactions, ap.can_add_transactions, ap.can_edit_transactions, ap.can_delete_transactions, ap.can_view_reports, ap.can_backup_data FROM admins a LEFT JOIN admin_permissions ap ON a.AdminID = ap.AdminID ORDER BY a.AdminID");
                    $data['admin_permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $data['admin_permissions'] = [];
                    $data['perm_error'] = 'Permissions data is not available.';
                }
                break;

            case 'backup_info':
                try {
                    $stmt = $pdo->query("SELECT BackupID, FileName, FileSize, BackupType, CreatedAt, Status FROM database_backups ORDER BY CreatedAt DESC LIMIT 10");
                    $data['backups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM database_backups");
                    $data['total_backups'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

                    $stmt = $pdo->query("SELECT MAX(CreatedAt) as last_backup FROM database_backups WHERE Status = 'Completed' OR Status = 'Success'");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $data['last_backup'] = $row['last_backup'] ?? 'Never';
                } catch (Exception $e) {
                    $data['backups'] = [];
                    $data['total_backups'] = 0;
                    $data['last_backup'] = 'Unknown';
                }
                break;

            case 'comparison':
                // Compare years or months
                $stmt = $pdo->query("SELECT ay.YearName, COUNT(t.TransactionID) as tx_count, COALESCE(SUM(t.CollectionAmount), 0) as collection, COALESCE(SUM(t.DistributedAmount), 0) as distribution, COALESCE(SUM(t.InterestAmount), 0) as interest, COUNT(DISTINCT t.MemberID) as active_members FROM academic_years ay LEFT JOIN transactions t ON ay.YearID = t.YearID GROUP BY ay.YearID ORDER BY ay.YearName DESC");
                $data['year_comparison'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Monthly comparison (this year vs last year)
                $stmt = $pdo->query("SELECT DATE_FORMAT(TransactionDate, '%Y') as year, DATE_FORMAT(TransactionDate, '%m') as month_num, DATE_FORMAT(TransactionDate, '%b') as month_name, COALESCE(SUM(CollectionAmount), 0) as collection FROM transactions WHERE TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) GROUP BY DATE_FORMAT(TransactionDate, '%Y'), DATE_FORMAT(TransactionDate, '%m') ORDER BY year DESC, month_num ASC");
                $data['monthly_comparison'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'top_performers':
                $limit = $params['limit'] ?? 10;
                $stmt = $pdo->prepare("SELECT m.MemberID, m.MemberName, m.Status, COUNT(t.TransactionID) as tx_count, COALESCE(SUM(t.CollectionAmount), 0) as total_collection, COALESCE(SUM(t.DistributedAmount), 0) as total_distribution, COALESCE(SUM(t.InterestAmount), 0) as total_interest FROM members m JOIN transactions t ON m.MemberID = t.MemberID GROUP BY m.MemberID ORDER BY total_collection DESC LIMIT " . (int)$limit);
                $stmt->execute();
                $data['top_members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Overall totals for percentage calculation
                $stmt = $pdo->query("SELECT COALESCE(SUM(CollectionAmount), 0) as grand_total FROM transactions");
                $data['grand_total'] = (float)$stmt->fetch(PDO::FETCH_ASSOC)['grand_total'];
                break;

            case 'defaulters':
                try {
                    // Get the active year
                    $stmt = $pdo->query("SELECT YearID, YearName FROM academic_years WHERE Status = 'Active' ORDER BY YearID DESC LIMIT 1");
                    $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($activeYear) {
                        // Members in active year who have no recent transactions
                        $stmt = $pdo->prepare("SELECT m.MemberID, m.MemberName, m.Phone, m.Status, MAX(t.TransactionDate) as last_transaction, COUNT(t.TransactionID) as tx_count, COALESCE(SUM(t.CollectionAmount), 0) as total_paid FROM members m LEFT JOIN transactions t ON m.MemberID = t.MemberID AND t.YearID = ? WHERE m.Status = 'Active' GROUP BY m.MemberID HAVING tx_count = 0 OR last_transaction < DATE_SUB(CURDATE(), INTERVAL 60 DAY) ORDER BY last_transaction ASC LIMIT 20");
                        $stmt->execute([$activeYear['YearID']]);
                        $data['defaulters'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $data['active_year'] = $activeYear['YearName'];
                    } else {
                        $data['defaulters'] = [];
                        $data['active_year'] = 'None';
                    }
                } catch (Exception $e) {
                    $data['defaulters'] = [];
                    $data['active_year'] = 'Unknown';
                }
                break;

            case 'smart_fallback':
                // Try to provide a useful response by showing quick overview
                $stmt = $pdo->query("SELECT (SELECT COUNT(*) FROM members) as total_members, (SELECT COUNT(*) FROM members WHERE Status='Active') as active_members, (SELECT COUNT(*) FROM transactions) as total_transactions, (SELECT COALESCE(SUM(CollectionAmount), 0) FROM transactions) as total_collection, (SELECT COALESCE(SUM(DistributedAmount), 0) FROM transactions) as total_distribution, (SELECT COUNT(*) FROM admins) as total_admins, (SELECT COUNT(*) FROM academic_years) as total_years");
                $data['overview'] = $stmt->fetch(PDO::FETCH_ASSOC);
                $data['original_query'] = $query ?? '';
                break;

            case 'help':
                $data['capabilities'] = [
                    'Member Information' => 'Ask about member counts, details, search by name or ID',
                    'Transaction Summary' => 'Get total collection, distribution, interest amounts',
                    'Transaction Details' => 'View recent transactions with member names and amounts',
                    'Year-wise Summary' => 'Compare performance across academic years',
                    'Statistics' => 'Get averages, maximums, minimums, and counts',
                    'Recent Activity' => 'See latest transactions and system activity',
                    'Verification Status' => 'Check verified vs unverified transactions',
                    'Payment Modes' => 'Breakdown by cash, online, cheque, etc. with chart',
                    'Monthly Analysis' => 'Monthly collection trends with chart',
                    'Expense Tracking' => 'View expenses by category with totals',
                    'Budget Tracking' => 'Budget vs actual spending analysis',
                    'Event Management' => 'View events, event expenses, and transactions',
                    'Admin Info' => 'See admin accounts and their status',
                    'Audit Logs' => 'View system activity and change history',
                    'Net Balance' => 'Step-by-step net balance calculation',
                    'Notifications' => 'View notification status and recent alerts',
                    'Permissions' => 'View admin permissions and access control',
                    'Backup History' => 'Check database backup status',
                    'System Info' => 'Database size, PHP version, system settings',
                    'Top Performers' => 'View top collecting members and rankings',
                    'Defaulters' => 'Find members with pending or missed payments',
                    'Comparisons' => 'Compare years or months side by side',
                    'Proof & Attachments' => 'View proof documents attached to transactions'
                ];
                break;
        }
    } catch (PDOException $e) {
        $data['error'] = 'A database error occurred while fetching data.';
        error_log('Chatbot DB Error: ' . $e->getMessage());
    }

    return $data;
}

// ============================================================
// PROOF ATTACHMENT HELPER
// ============================================================

function fetchProofAttachments($pdo, $entityType, $records) {
    $proofs = [];
    if (empty($records)) return $proofs;

    try {
        // Determine ID column
        $idCol = ($entityType === 'transaction') ? 'TransactionID' : 'ExpenseID';
        $ids = array_column($records, $idCol);
        if (empty($ids)) return $proofs;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT AttachmentID, EntityType, EntityID, OriginalName, MimeType, LocalPath, GoogleDriveURL, GoogleDriveThumbnail, FileSize FROM proof_attachments WHERE EntityType = ? AND EntityID IN ($placeholders)");
        $params = array_merge([$entityType], $ids);
        $stmt->execute($params);
        $proofs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table may not exist - silent fail
    }

    return $proofs;
}

// ============================================================
// CALCULATIONS
// ============================================================

function performCalculations($intent, $data) {
    $calc = null;

    switch ($intent) {
        case 'transaction_summary':
            if (isset($data['summary'])) {
                $s = $data['summary'];
                $collection = (float)($s['total_collection'] ?? 0);
                $distribution = (float)($s['total_distribution'] ?? 0);
                $interest = (float)($s['total_interest'] ?? 0);
                $surplus = $collection - $distribution;

                $calc = [
                    'title' => 'Transaction Summary Calculation',
                    'steps' => [
                        ['label' => 'Total Collection', 'value' => $collection, 'formatted' => formatCurrency($collection), 'formula' => 'SUM(CollectionAmount)'],
                        ['label' => 'Total Distribution', 'value' => $distribution, 'formatted' => formatCurrency($distribution), 'formula' => 'SUM(DistributedAmount)', 'operator' => '-'],
                        ['label' => 'Total Interest Earned', 'value' => $interest, 'formatted' => formatCurrency($interest), 'formula' => 'SUM(InterestAmount)']
                    ],
                    'final_result' => [
                        'label' => $surplus >= 0 ? 'Net Surplus' : 'Net Deficit',
                        'value' => $surplus,
                        'formatted' => formatCurrency(abs($surplus)),
                        'formula' => 'Collection - Distribution',
                        'positive' => $surplus >= 0
                    ]
                ];
            }
            break;

        case 'net_balance':
            $interest = $data['interest'] ?? 0;
            $fixed = $data['fixed_amount'] ?? 0;
            $change = $data['change_amount'] ?? 0;
            $expenses = $data['expenses'] ?? 0;
            $netBalance = $data['net_balance'] ?? 0;
            $income = $interest + $fixed + $change;

            $calc = [
                'title' => 'Net Balance Calculation',
                'steps' => [
                    ['label' => 'Total Interest Amount', 'value' => $interest, 'formatted' => formatCurrency($interest), 'formula' => 'SUM(InterestAmount) from transactions'],
                    ['label' => 'Total Fixed Amount', 'value' => $fixed, 'formatted' => formatCurrency($fixed), 'formula' => 'SUM(FixedAmount) from academic years', 'operator' => '+'],
                    ['label' => 'Total Change Amount', 'value' => $change, 'formatted' => formatCurrency($change), 'formula' => 'SUM(ChangeAmount) from academic years', 'operator' => '+'],
                    ['label' => 'Total Income', 'value' => $income, 'formatted' => formatCurrency($income), 'formula' => 'Interest + Fixed + Change', 'highlight' => true],
                    ['label' => 'Total Expenses', 'value' => $expenses, 'formatted' => formatCurrency($expenses), 'formula' => 'SUM(Amount) from expenses', 'operator' => '-']
                ],
                'final_result' => [
                    'label' => $netBalance >= 0 ? 'Net Balance (Surplus)' : 'Net Balance (Deficit)',
                    'value' => $netBalance,
                    'formatted' => formatCurrency(abs($netBalance)),
                    'formula' => '(Interest + Fixed + Change) - Expenses',
                    'positive' => $netBalance >= 0
                ]
            ];
            break;

        case 'statistics':
            if (isset($data['stats'])) {
                $s = $data['stats'];
                $calc = [
                    'title' => 'Statistical Analysis',
                    'steps' => [
                        ['label' => 'Average Collection', 'value' => (float)$s['avg_collection'], 'formatted' => formatCurrency((float)$s['avg_collection']), 'formula' => 'AVG(CollectionAmount)'],
                        ['label' => 'Maximum Collection', 'value' => (float)$s['max_collection'], 'formatted' => formatCurrency((float)$s['max_collection']), 'formula' => 'MAX(CollectionAmount)'],
                        ['label' => 'Minimum Collection', 'value' => (float)$s['min_collection'], 'formatted' => formatCurrency((float)$s['min_collection']), 'formula' => 'MIN(CollectionAmount where > 0)'],
                        ['label' => 'Average Distribution', 'value' => (float)$s['avg_distribution'], 'formatted' => formatCurrency((float)$s['avg_distribution']), 'formula' => 'AVG(DistributedAmount)'],
                        ['label' => 'Maximum Distribution', 'value' => (float)$s['max_distribution'], 'formatted' => formatCurrency((float)$s['max_distribution']), 'formula' => 'MAX(DistributedAmount)']
                    ],
                    'final_result' => [
                        'label' => 'Total Transactions Analyzed',
                        'value' => (int)$s['total_transactions'],
                        'formatted' => number_format((int)$s['total_transactions']),
                        'formula' => 'COUNT(*)',
                        'positive' => true
                    ]
                ];
            }
            break;

        case 'verification':
            if (isset($data['verification'])) {
                $v = $data['verification'];
                $total = (int)$v['total'];
                $verified = (int)$v['verified'];
                $unverified = (int)$v['unverified'];
                $pct = $total > 0 ? round(($verified / $total) * 100, 1) : 0;

                $calc = [
                    'title' => 'Verification Analysis',
                    'steps' => [
                        ['label' => 'Total Transactions', 'value' => $total, 'formatted' => number_format($total), 'formula' => 'COUNT(*)'],
                        ['label' => 'Verified Transactions', 'value' => $verified, 'formatted' => number_format($verified), 'formula' => 'COUNT(WHERE Verified=1)'],
                        ['label' => 'Unverified Transactions', 'value' => $unverified, 'formatted' => number_format($unverified), 'formula' => 'COUNT(WHERE Verified=0 or NULL)']
                    ],
                    'final_result' => [
                        'label' => 'Verification Rate',
                        'value' => $pct,
                        'formatted' => $pct . '%',
                        'formula' => '(Verified / Total) x 100',
                        'positive' => $pct >= 80
                    ]
                ];
            }
            break;

        case 'member_search':
            if (!empty($data['members'])) {
                foreach ($data['members'] as &$m) {
                    $collection = (float)($m['total_collection'] ?? 0);
                    $distribution = (float)($m['total_distribution'] ?? 0);
                    $interest = (float)($m['total_interest'] ?? 0);
                    $m['net_amount'] = $collection - $distribution;
                }
                unset($m);

                $first = $data['members'][0];
                $calc = [
                    'title' => 'Member Financial Summary - ' . ($first['MemberName'] ?? 'Unknown'),
                    'steps' => [
                        ['label' => 'Total Collection', 'value' => (float)$first['total_collection'], 'formatted' => formatCurrency((float)$first['total_collection']), 'formula' => 'SUM(CollectionAmount)'],
                        ['label' => 'Total Distribution', 'value' => (float)$first['total_distribution'], 'formatted' => formatCurrency((float)$first['total_distribution']), 'formula' => 'SUM(DistributedAmount)', 'operator' => '-'],
                        ['label' => 'Total Interest', 'value' => (float)$first['total_interest'], 'formatted' => formatCurrency((float)$first['total_interest']), 'formula' => 'SUM(InterestAmount)']
                    ],
                    'final_result' => [
                        'label' => ($first['net_amount'] ?? 0) >= 0 ? 'Net Amount (Surplus)' : 'Net Amount (Deficit)',
                        'value' => $first['net_amount'] ?? 0,
                        'formatted' => formatCurrency(abs($first['net_amount'] ?? 0)),
                        'formula' => 'Collection - Distribution',
                        'positive' => ($first['net_amount'] ?? 0) >= 0
                    ]
                ];
            }
            break;

        case 'expense_info':
            if (isset($data['expense_summary'])) {
                $total = (float)($data['expense_summary']['total_amount'] ?? 0);
                $count = (int)($data['expense_summary']['total_expenses'] ?? 0);
                $avg = $count > 0 ? $total / $count : 0;

                $steps = [
                    ['label' => 'Total Expenses', 'value' => $count, 'formatted' => number_format($count), 'formula' => 'COUNT(*)'],
                    ['label' => 'Total Amount Spent', 'value' => $total, 'formatted' => formatCurrency($total), 'formula' => 'SUM(Amount)'],
                    ['label' => 'Average Expense', 'value' => $avg, 'formatted' => formatCurrency($avg), 'formula' => 'Total / Count']
                ];

                $calc = [
                    'title' => 'Expense Analysis',
                    'steps' => $steps,
                    'final_result' => [
                        'label' => 'Total Expenditure',
                        'value' => $total,
                        'formatted' => formatCurrency($total),
                        'formula' => 'SUM(all expenses)',
                        'positive' => false
                    ]
                ];
            }
            break;

        case 'budget_info':
            if (isset($data['total_spent'])) {
                $totalSpent = (float)$data['total_spent'];
                $totalColl = (float)($data['total_collection'] ?? 0);
                $ratio = $totalColl > 0 ? round(($totalSpent / $totalColl) * 100, 1) : 0;
                $remaining = $totalColl - $totalSpent;

                $steps = [
                    ['label' => 'Total Collection (Income)', 'value' => $totalColl, 'formatted' => formatCurrency($totalColl), 'formula' => 'SUM(CollectionAmount)'],
                    ['label' => 'Total Expenditure', 'value' => $totalSpent, 'formatted' => formatCurrency($totalSpent), 'formula' => 'SUM(expenses.Amount)', 'operator' => '-'],
                    ['label' => 'Expense-to-Income Ratio', 'value' => $ratio, 'formatted' => $ratio . '%', 'formula' => '(Expenses / Collection) x 100']
                ];

                $calc = [
                    'title' => 'Budget Analysis',
                    'steps' => $steps,
                    'final_result' => [
                        'label' => $remaining >= 0 ? 'Remaining Balance' : 'Over Budget By',
                        'value' => $remaining,
                        'formatted' => formatCurrency(abs($remaining)),
                        'formula' => 'Collection - Expenses',
                        'positive' => $remaining >= 0
                    ]
                ];
            }
            break;

        case 'top_performers':
            if (!empty($data['top_members'])) {
                $totalColl = 0;
                $totalDist = 0;
                foreach ($data['top_members'] as $m) {
                    $totalColl += (float)$m['total_collection'];
                    $totalDist += (float)$m['total_distribution'];
                }
                $grandTotal = $data['grand_total'] ?? 1;
                $topPct = $grandTotal > 0 ? round(($totalColl / $grandTotal) * 100, 1) : 0;
                $count = count($data['top_members']);

                $calc = [
                    'title' => "Top $count Members Analysis",
                    'steps' => [
                        ['label' => "Top $count Collection", 'value' => $totalColl, 'formatted' => formatCurrency($totalColl), 'formula' => "SUM of top $count members"],
                        ['label' => 'Grand Total Collection', 'value' => (float)$grandTotal, 'formatted' => formatCurrency((float)$grandTotal), 'formula' => 'SUM(all members)'],
                        ['label' => "Top $count Contribution", 'value' => $topPct, 'formatted' => $topPct . '%', 'formula' => "(Top $count / Grand Total) x 100"]
                    ],
                    'final_result' => [
                        'label' => "Top $count contribute",
                        'value' => $topPct,
                        'formatted' => $topPct . '% of total collection',
                        'formula' => 'Concentration ratio',
                        'positive' => true
                    ]
                ];
            }
            break;
    }

    return $calc;
}

// ============================================================
// CHART GENERATION
// ============================================================

function generateChartConfig($intent, $data) {
    $chart = null;

    $colors = ['#667eea', '#764ba2', '#2ecc71', '#e67e22', '#e74c3c', '#3498db', '#9b59b6', '#1abc9c', '#f39c12', '#d35400'];

    switch ($intent) {
        case 'payment_modes':
            if (!empty($data['modes'])) {
                $labels = array_column($data['modes'], 'mode');
                $values = array_map('floatval', array_column($data['modes'], 'total_collection'));
                $counts = array_map('intval', array_column($data['modes'], 'count'));

                $chart = [
                    'type' => 'doughnut',
                    'title' => 'Payment Mode Distribution',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Collection Amount',
                            'data' => $values,
                            'backgroundColor' => array_slice($colors, 0, count($labels))
                        ]
                    ],
                    'extra_info' => 'Transaction counts: ' . implode(', ', array_map(function($l, $c) { return "$l: $c"; }, $labels, $counts))
                ];
            }
            break;

        case 'monthly_analysis':
            if (!empty($data['monthly'])) {
                $labels = array_column($data['monthly'], 'month_label');
                $collections = array_map('floatval', array_column($data['monthly'], 'collection'));
                $distributions = array_map('floatval', array_column($data['monthly'], 'distribution'));

                $chart = [
                    'type' => 'bar',
                    'title' => 'Monthly Collection vs Distribution (Last 12 Months)',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Collection',
                            'data' => $collections,
                            'backgroundColor' => 'rgba(46, 204, 113, 0.7)',
                            'borderColor' => 'rgba(46, 204, 113, 1)',
                            'borderWidth' => 1
                        ],
                        [
                            'label' => 'Distribution',
                            'data' => $distributions,
                            'backgroundColor' => 'rgba(52, 152, 219, 0.7)',
                            'borderColor' => 'rgba(52, 152, 219, 1)',
                            'borderWidth' => 1
                        ]
                    ]
                ];
            }
            break;

        case 'year_summary':
            if (!empty($data['years'])) {
                $labels = array_column($data['years'], 'YearName');
                $collections = array_map('floatval', array_column($data['years'], 'total_collection'));
                $distributions = array_map('floatval', array_column($data['years'], 'total_distribution'));

                $chart = [
                    'type' => 'bar',
                    'title' => 'Year-wise Collection vs Distribution',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Collection',
                            'data' => $collections,
                            'backgroundColor' => 'rgba(102, 126, 234, 0.7)',
                            'borderColor' => 'rgba(102, 126, 234, 1)',
                            'borderWidth' => 1
                        ],
                        [
                            'label' => 'Distribution',
                            'data' => $distributions,
                            'backgroundColor' => 'rgba(118, 75, 162, 0.7)',
                            'borderColor' => 'rgba(118, 75, 162, 1)',
                            'borderWidth' => 1
                        ]
                    ]
                ];
            }
            break;

        case 'verification':
            if (isset($data['verification'])) {
                $v = $data['verification'];
                $chart = [
                    'type' => 'doughnut',
                    'title' => 'Transaction Verification Status',
                    'labels' => ['Verified', 'Unverified'],
                    'datasets' => [
                        [
                            'data' => [(int)$v['verified'], (int)$v['unverified']],
                            'backgroundColor' => ['#2ecc71', '#e74c3c']
                        ]
                    ]
                ];
            }
            break;

        case 'expense_info':
            if (!empty($data['expense_by_category'])) {
                $labels = array_column($data['expense_by_category'], 'category');
                $amounts = array_map('floatval', array_column($data['expense_by_category'], 'amount'));

                $chart = [
                    'type' => 'doughnut',
                    'title' => 'Expenses by Category',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'data' => $amounts,
                            'backgroundColor' => array_slice($colors, 0, count($labels))
                        ]
                    ]
                ];
            }
            break;

        case 'member_info':
            if (!empty($data['top_members'])) {
                $labels = array_column($data['top_members'], 'MemberName');
                $collections = array_map('floatval', array_column($data['top_members'], 'total_collection'));

                $chart = [
                    'type' => 'bar',
                    'title' => 'Top 5 Members by Collection',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Total Collection',
                            'data' => $collections,
                            'backgroundColor' => array_slice($colors, 0, count($labels))
                        ]
                    ]
                ];
            }
            break;

        case 'budget_info':
            if (!empty($data['monthly_expenses'])) {
                $labels = array_column($data['monthly_expenses'], 'month_label');
                $amounts = array_map('floatval', array_column($data['monthly_expenses'], 'amount'));

                $chart = [
                    'type' => 'line',
                    'title' => 'Monthly Expense Trend',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Expenses',
                            'data' => $amounts,
                            'borderColor' => '#e74c3c',
                            'backgroundColor' => 'rgba(231, 76, 60, 0.1)',
                            'fill' => true,
                            'tension' => 0.3
                        ]
                    ]
                ];
            } elseif (!empty($data['by_category'])) {
                $labels = array_column($data['by_category'], 'category');
                $amounts = array_map('floatval', array_column($data['by_category'], 'amount'));

                $chart = [
                    'type' => 'doughnut',
                    'title' => 'Expense by Category',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'data' => $amounts,
                            'backgroundColor' => array_slice($colors, 0, count($labels))
                        ]
                    ]
                ];
            }
            break;

        case 'event_info':
            if (!empty($data['event_financials'])) {
                $labels = array_column($data['event_financials'], 'EventName');
                $amounts = array_map('floatval', array_column($data['event_financials'], 'total_amount'));

                $chart = [
                    'type' => 'bar',
                    'title' => 'Event Financial Summary',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Total Amount',
                            'data' => $amounts,
                            'backgroundColor' => array_slice($colors, 0, count($labels))
                        ]
                    ]
                ];
            }
            break;

        case 'comparison':
            if (!empty($data['year_comparison'])) {
                $labels = array_column($data['year_comparison'], 'YearName');
                $collections = array_map('floatval', array_column($data['year_comparison'], 'collection'));
                $distributions = array_map('floatval', array_column($data['year_comparison'], 'distribution'));
                $interests = array_map('floatval', array_column($data['year_comparison'], 'interest'));

                $chart = [
                    'type' => 'bar',
                    'title' => 'Year-over-Year Comparison',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Collection',
                            'data' => $collections,
                            'backgroundColor' => 'rgba(46, 204, 113, 0.7)',
                            'borderColor' => 'rgba(46, 204, 113, 1)',
                            'borderWidth' => 1
                        ],
                        [
                            'label' => 'Distribution',
                            'data' => $distributions,
                            'backgroundColor' => 'rgba(52, 152, 219, 0.7)',
                            'borderColor' => 'rgba(52, 152, 219, 1)',
                            'borderWidth' => 1
                        ],
                        [
                            'label' => 'Interest',
                            'data' => $interests,
                            'backgroundColor' => 'rgba(155, 89, 182, 0.7)',
                            'borderColor' => 'rgba(155, 89, 182, 1)',
                            'borderWidth' => 1
                        ]
                    ]
                ];
            }
            break;

        case 'top_performers':
            if (!empty($data['top_members'])) {
                $labels = array_column($data['top_members'], 'MemberName');
                $collections = array_map('floatval', array_column($data['top_members'], 'total_collection'));

                $chart = [
                    'type' => 'bar',
                    'title' => 'Top Members by Collection',
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => 'Total Collection',
                            'data' => $collections,
                            'backgroundColor' => array_slice($colors, 0, count($labels))
                        ]
                    ]
                ];
            }
            break;
    }

    return $chart;
}

// ============================================================
// RESPONSE GENERATION
// ============================================================

function generateResponse($intent, $data, $calculations) {
    $answer = '';

    switch ($intent) {
        case 'member_info':
            if (isset($data['summary'])) {
                $s = $data['summary'];
                $answer = "Here's the member overview:\n\n";
                $answer .= "Total Members: " . number_format((int)$s['total']) . "\n";
                $answer .= "Active: " . number_format((int)$s['active']) . " | Inactive: " . number_format((int)$s['inactive']) . "\n\n";
                if (!empty($data['top_members'])) {
                    $answer .= "Top 5 members by collection are shown in the chart below.";
                }
            }
            break;

        case 'member_search':
            if (!empty($data['members'])) {
                $count = count($data['members']);
                $answer = "Found $count member(s) matching your search:\n\n";
                foreach ($data['members'] as $m) {
                    $answer .= "**" . htmlspecialchars($m['MemberName']) . "** (ID: " . $m['MemberID'] . ")\n";
                    $answer .= "Status: " . $m['Status'] . " | Phone: " . ($m['Phone'] ?? 'N/A') . "\n";
                    $answer .= "Transactions: " . number_format((int)$m['transaction_count']) . "\n\n";
                }
                $answer .= "See the financial breakdown below.";
            } else {
                $answer = "No members found matching your search. Try searching by name or ID (e.g., 'find member Ravi' or 'member id 5').";
            }
            break;

        case 'transaction_summary':
            if (isset($data['summary'])) {
                $s = $data['summary'];
                $answer = "Here's the transaction summary:\n\n";
                $answer .= "Total Transactions: " . number_format((int)$s['total_transactions']) . "\n";
                $answer .= "Total Collection: " . formatCurrency((float)$s['total_collection']) . "\n";
                $answer .= "Total Distribution: " . formatCurrency((float)$s['total_distribution']) . "\n";
                $answer .= "Total Interest: " . formatCurrency((float)$s['total_interest']) . "\n\n";

                if (isset($data['today'])) {
                    $answer .= "Today: " . $data['today']['today_count'] . " transactions, " . formatCurrency((float)$data['today']['today_collection']) . " collected\n";
                }
                if (isset($data['this_month'])) {
                    $answer .= "This Month: " . $data['this_month']['month_count'] . " transactions, " . formatCurrency((float)$data['this_month']['month_collection']) . " collected\n\n";
                }
                $answer .= "See the detailed calculation breakdown below.";
            }
            break;

        case 'transaction_details':
            if (!empty($data['transactions'])) {
                $shown = count($data['transactions']);
                $total = $data['total_count'] ?? $shown;
                $answer = "Showing $shown of " . number_format($total) . " total transactions (most recent first).\n\n";
                $answer .= "The transaction details are displayed in the table below.";
                if (!empty($data['proof'])) {
                    $answer .= "\n\nProof attachments found for some transactions - see below.";
                }
            } else {
                $answer = "No transactions found matching your criteria.";
            }
            break;

        case 'year_summary':
            if (!empty($data['years'])) {
                $answer = "Here's the year-wise summary for " . count($data['years']) . " academic year(s):\n\n";
                foreach ($data['years'] as $y) {
                    $answer .= "**" . htmlspecialchars($y['YearName']) . "** (" . $y['Status'] . ")\n";
                    $answer .= "Transactions: " . number_format((int)$y['transaction_count']) . " | Members: " . number_format((int)$y['member_count']) . "\n";
                    $answer .= "Collection: " . formatCurrency((float)$y['total_collection']) . " | Distribution: " . formatCurrency((float)$y['total_distribution']) . "\n\n";
                }
                $answer .= "See the comparison chart below.";
            } else {
                $answer = "No academic years found in the system.";
            }
            break;

        case 'statistics':
            if (isset($data['stats']) && isset($data['counts'])) {
                $c = $data['counts'];
                $answer = "Here are the system statistics:\n\n";
                $answer .= "Members: " . number_format((int)$c['total_members']) . " (Active: " . number_format((int)$c['active_members']) . ")\n";
                $answer .= "Admins: " . number_format((int)$c['total_admins']) . "\n";
                $answer .= "Academic Years: " . number_format((int)$c['total_years']) . "\n";
                $answer .= "Transactions: " . number_format((int)$c['total_transactions']) . "\n\n";
                $answer .= "See the detailed statistical analysis below.";
            }
            break;

        case 'recent_activity':
            $txCount = count($data['recent_transactions'] ?? []);
            $logCount = count($data['recent_logs'] ?? []);
            $answer = "Here's the recent activity:\n\n";
            $answer .= "Showing last $txCount transactions and $logCount audit log entries.\n";
            $answer .= "Details are displayed in the tables below.";
            break;

        case 'verification':
            if (isset($data['verification'])) {
                $v = $data['verification'];
                $total = (int)$v['total'];
                $verified = (int)$v['verified'];
                $pct = $total > 0 ? round(($verified / $total) * 100, 1) : 0;

                $answer = "Here's the transaction verification status:\n\n";
                $answer .= "Total: " . number_format($total) . " | Verified: " . number_format($verified) . " | Unverified: " . number_format((int)$v['unverified']) . "\n";
                $answer .= "Verification Rate: $pct%\n\n";
                $answer .= "See the breakdown chart and calculations below.";
            }
            break;

        case 'payment_modes':
            if (!empty($data['modes'])) {
                $answer = "Here's the payment mode breakdown:\n\n";
                foreach ($data['modes'] as $m) {
                    $answer .= "**" . htmlspecialchars($m['mode']) . "**: " . number_format((int)$m['count']) . " transactions, " . formatCurrency((float)$m['total_collection']) . " collected\n";
                }
                $answer .= "\nSee the chart below for visual distribution.";
            }
            break;

        case 'monthly_analysis':
            if (!empty($data['monthly'])) {
                $answer = "Here's the monthly analysis (last 12 months):\n\n";
                $totalColl = 0;
                $totalDist = 0;
                foreach ($data['monthly'] as $m) {
                    $totalColl += (float)$m['collection'];
                    $totalDist += (float)$m['distribution'];
                }
                $answer .= "Total Collection: " . formatCurrency($totalColl) . "\n";
                $answer .= "Total Distribution: " . formatCurrency($totalDist) . "\n";
                $answer .= "Months with data: " . count($data['monthly']) . "\n\n";
                $answer .= "See the monthly trend chart and table below.";
            } else {
                $answer = "No transaction data found for the last 12 months.";
            }
            break;

        case 'expense_info':
            if (isset($data['expense_error'])) {
                $answer = $data['expense_error'];
            } elseif (isset($data['expense_summary'])) {
                $s = $data['expense_summary'];
                $answer = "Here's the expense overview:\n\n";
                $answer .= "Total Expenses: " . number_format((int)$s['total_expenses']) . "\n";
                $answer .= "Total Amount: " . formatCurrency((float)$s['total_amount']) . "\n\n";
                if (!empty($data['expense_by_category'])) {
                    $answer .= "Breakdown by category is shown in the chart below.";
                }
                if (!empty($data['proof'])) {
                    $answer .= "\nProof attachments found - see below.";
                }
            }
            break;

        case 'admin_info':
            if (isset($data['summary'])) {
                $s = $data['summary'];
                $answer = "Here's the admin overview:\n\n";
                $answer .= "Total Admins: " . number_format((int)$s['total']) . "\n";
                $answer .= "Active: " . number_format((int)$s['active']) . " | Inactive: " . number_format((int)$s['inactive']) . "\n\n";
                $answer .= "Admin details are shown in the table below.";
            }
            break;

        case 'audit_logs':
            if (!empty($data['logs'])) {
                $answer = "Here are the recent audit log entries (" . count($data['logs']) . " entries):\n\n";
                if (isset($data['stats'])) {
                    $answer .= "Total log entries: " . number_format((int)$data['stats']['total']) . "\n\n";
                }
                $answer .= "Details are shown in the table below.";
            } else {
                $answer = "No audit log entries found.";
            }
            break;

        case 'net_balance':
            $answer = "Here's the net balance calculation:\n\n";
            $answer .= "Interest: " . formatCurrency($data['interest'] ?? 0) . "\n";
            $answer .= "Fixed Amount: " . formatCurrency($data['fixed_amount'] ?? 0) . "\n";
            $answer .= "Change Amount: " . formatCurrency($data['change_amount'] ?? 0) . "\n";
            $answer .= "Expenses: " . formatCurrency($data['expenses'] ?? 0) . "\n\n";
            $nb = $data['net_balance'] ?? 0;
            $answer .= "**Net Balance: " . formatCurrency(abs($nb)) . " (" . ($nb >= 0 ? 'Surplus' : 'Deficit') . ")**\n\n";
            $answer .= "See the step-by-step calculation below.";
            break;

        case 'system_info':
            $answer = "Here's the system information:\n\n";
            $answer .= "Database Size: " . ($data['db_size'] ?? '0') . " MB\n";
            $answer .= "PHP Version: " . ($data['php_version'] ?? 'N/A') . "\n";
            $answer .= "Server Time: " . ($data['server_time'] ?? 'N/A') . "\n\n";
            if (!empty($data['table_counts'])) {
                $answer .= "Record Counts:\n";
                foreach ($data['table_counts'] as $tc) {
                    $answer .= "  " . $tc['name'] . ": " . number_format((int)$tc['count']) . "\n";
                }
            }
            break;

        case 'help':
            $answer = "I'm the SR Colony AI Assistant! Here's what I can help you with:\n\n";
            if (isset($data['capabilities'])) {
                foreach ($data['capabilities'] as $name => $desc) {
                    $answer .= "**$name**: $desc\n";
                }
            }
            $answer .= "\nTry asking questions like:\n";
            $answer .= "- \"How many active members?\"\n";
            $answer .= "- \"Show transaction summary\"\n";
            $answer .= "- \"Payment mode breakdown\"\n";
            $answer .= "- \"Net balance calculation\"\n";
            $answer .= "- \"Find member Ravi\"\n";
            $answer .= "- \"Monthly collection trends\"\n";
            $answer .= "- \"Show recent activity\"\n";
            $answer .= "- \"Year-wise comparison\"\n";
            $answer .= "- \"Show expenses\"\n";
            $answer .= "- \"Budget tracking\"\n";
            $answer .= "- \"Event details\"\n";
            $answer .= "- \"Top performers\"\n";
            $answer .= "- \"Show defaulters\"\n";
            $answer .= "- \"Notification status\"\n";
            $answer .= "- \"Backup history\"";
            break;

        case 'greeting':
            $name = $data['admin_name'] ?? 'Super Admin';
            $hour = (int)date('H');
            $timeGreeting = ($hour < 12) ? 'Good morning' : (($hour < 17) ? 'Good afternoon' : 'Good evening');
            $answer = "$timeGreeting, **$name**! Welcome to SR Colony AI Assistant.\n\n";
            if (isset($data['quick_stats'])) {
                $qs = $data['quick_stats'];
                $answer .= "Here's a quick snapshot:\n";
                $answer .= "Active Members: " . number_format((int)($qs['active_members'] ?? 0)) . " / " . number_format((int)($qs['members'] ?? 0)) . " total\n";
                $answer .= "Total Transactions: " . number_format((int)($qs['transactions'] ?? 0)) . "\n";
                if (isset($data['today'])) {
                    $answer .= "Today's Transactions: " . number_format((int)($data['today']['today_tx'] ?? 0)) . "\n";
                }
            }
            $answer .= "\nHow can I help you today? Try a suggestion below or ask me anything!";
            break;

        case 'budget_info':
            if (isset($data['error_msg'])) {
                $answer = $data['error_msg'];
            } else {
                $totalSpent = $data['total_spent'] ?? 0;
                $totalCollection = $data['total_collection'] ?? 0;
                $expenseRatio = $totalCollection > 0 ? round(($totalSpent / $totalCollection) * 100, 1) : 0;

                $answer = "Here's the budget and spending analysis:\n\n";
                $answer .= "Total Expenditure: " . formatCurrency($totalSpent) . "\n";
                $answer .= "Total Collection: " . formatCurrency($totalCollection) . "\n";
                $answer .= "Expense-to-Collection Ratio: $expenseRatio%\n\n";
                if (!empty($data['by_category'])) {
                    $answer .= "Top spending categories:\n";
                    foreach (array_slice($data['by_category'], 0, 5) as $c) {
                        $answer .= "  " . htmlspecialchars($c['category']) . ": " . formatCurrency((float)$c['amount']) . " (" . $c['count'] . " entries)\n";
                    }
                }
                $answer .= "\nSee the charts and detailed breakdown below.";
            }
            break;

        case 'event_info':
            if (isset($data['event_error'])) {
                $answer = $data['event_error'];
            } else {
                $es = $data['event_summary'] ?? [];
                $answer = "Here's the events overview:\n\n";
                $answer .= "Total Events: " . number_format((int)($es['total_events'] ?? 0)) . "\n";
                $answer .= "Active/Ongoing: " . number_format((int)($es['active_events'] ?? 0)) . "\n";
                $answer .= "Completed: " . number_format((int)($es['completed_events'] ?? 0)) . "\n\n";
                if (!empty($data['events'])) {
                    $answer .= "Recent events are shown in the table below.";
                    if (!empty($data['event_financials'])) {
                        $answer .= "\nEvent financial summaries are also included.";
                    }
                }
            }
            break;

        case 'notification_info':
            $ns = $data['notification_summary'] ?? [];
            $answer = "Here's the notification status:\n\n";
            $answer .= "Total Notifications: " . number_format((int)($ns['total'] ?? 0)) . "\n";
            $answer .= "Unread: " . number_format((int)($ns['unread'] ?? 0)) . "\n";
            $answer .= "Read: " . number_format((int)($ns['read_count'] ?? 0)) . "\n\n";
            if (!empty($data['recent_notifications'])) {
                $answer .= "Recent notifications are shown in the table below.";
            }
            break;

        case 'permission_info':
            if (isset($data['perm_error'])) {
                $answer = $data['perm_error'];
            } else {
                $count = count($data['admin_permissions'] ?? []);
                $answer = "Here's the admin permissions overview ($count admins):\n\n";
                $answer .= "The permissions matrix is displayed in the table below showing what each admin can access.";
            }
            break;

        case 'backup_info':
            $answer = "Here's the database backup information:\n\n";
            $answer .= "Total Backups: " . number_format($data['total_backups'] ?? 0) . "\n";
            $answer .= "Last Backup: " . ($data['last_backup'] !== 'Never' && $data['last_backup'] !== 'Unknown' ? date('d-m-Y H:i', strtotime($data['last_backup'])) : $data['last_backup']) . "\n\n";
            if (!empty($data['backups'])) {
                $answer .= "Recent backup history is shown in the table below.";
            }
            break;

        case 'comparison':
            if (!empty($data['year_comparison'])) {
                $answer = "Here's the year-over-year comparison:\n\n";
                foreach ($data['year_comparison'] as $y) {
                    $surplus = (float)$y['collection'] - (float)$y['distribution'];
                    $answer .= "**" . htmlspecialchars($y['YearName']) . "**: ";
                    $answer .= "Collection " . formatCurrency((float)$y['collection']);
                    $answer .= " | Distribution " . formatCurrency((float)$y['distribution']);
                    $answer .= " | " . ($surplus >= 0 ? "Surplus " : "Deficit ") . formatCurrency(abs($surplus)) . "\n";
                }
                $answer .= "\nSee the comparison chart and table below.";
            }
            break;

        case 'top_performers':
            if (!empty($data['top_members'])) {
                $count = count($data['top_members']);
                $answer = "Here are the top $count members by collection:\n\n";
                $grandTotal = $data['grand_total'] ?? 0;
                foreach ($data['top_members'] as $i => $m) {
                    $pct = $grandTotal > 0 ? round(((float)$m['total_collection'] / $grandTotal) * 100, 1) : 0;
                    $answer .= ($i + 1) . ". **" . htmlspecialchars($m['MemberName']) . "** - " . formatCurrency((float)$m['total_collection']) . " ($pct% of total, " . $m['tx_count'] . " transactions)\n";
                }
                $answer .= "\nSee the chart and detailed breakdown below.";
            } else {
                $answer = "No transaction data available to determine top performers.";
            }
            break;

        case 'defaulters':
            $yearName = $data['active_year'] ?? 'N/A';
            if (!empty($data['defaulters'])) {
                $count = count($data['defaulters']);
                $answer = "Found $count member(s) with pending/missed payments for year **$yearName**:\n\n";
                $answer .= "These are active members with no transactions or last payment over 60 days ago.\n";
                $answer .= "See the details in the table below.";
            } else {
                $answer = "No defaulters found for the active year ($yearName). All active members are up to date!";
            }
            break;

        case 'smart_fallback':
            $origQuery = htmlspecialchars($data['original_query'] ?? '');
            $answer = "I didn't find an exact match for \"$origQuery\", but here's a quick system overview to help:\n\n";
            if (isset($data['overview'])) {
                $o = $data['overview'];
                $answer .= "Members: " . number_format((int)$o['total_members']) . " (Active: " . number_format((int)$o['active_members']) . ")\n";
                $answer .= "Admins: " . number_format((int)$o['total_admins']) . "\n";
                $answer .= "Academic Years: " . number_format((int)$o['total_years']) . "\n";
                $answer .= "Total Transactions: " . number_format((int)$o['total_transactions']) . "\n";
                $answer .= "Total Collection: " . formatCurrency((float)$o['total_collection']) . "\n";
                $answer .= "Total Distribution: " . formatCurrency((float)$o['total_distribution']) . "\n\n";
            }
            $answer .= "Try being more specific, or ask about:\n";
            $answer .= "members, transactions, collections, expenses, events, payments, yearly/monthly analysis, net balance, statistics, audits, system info, or type 'help' for full list.";
            break;

        default:
            $answer = "I'm not sure how to help with that. Try asking about members, transactions, collections, payments, or type 'help' to see what I can do.";
    }

    return $answer;
}

// ============================================================
// DATA TABLE GENERATION
// ============================================================

function generateDataTable($intent, $data) {
    $table = null;

    switch ($intent) {
        case 'transaction_details':
            if (!empty($data['transactions'])) {
                $table = [
                    'headers' => ['#', 'Date', 'Member', 'Collection', 'Distribution', 'Interest', 'Mode', 'Year'],
                    'rows' => []
                ];
                foreach ($data['transactions'] as $i => $t) {
                    $table['rows'][] = [
                        $i + 1,
                        date('d-m-Y', strtotime($t['TransactionDate'])),
                        htmlspecialchars($t['MemberName']),
                        formatCurrency((float)$t['CollectionAmount']),
                        formatCurrency((float)$t['DistributedAmount']),
                        formatCurrency((float)$t['InterestAmount']),
                        htmlspecialchars($t['PaymentMode'] ?? 'N/A'),
                        htmlspecialchars($t['YearName'] ?? 'N/A')
                    ];
                }
            }
            break;

        case 'recent_activity':
            if (!empty($data['recent_transactions'])) {
                $table = [
                    'headers' => ['Date', 'Member', 'Collection', 'Distribution', 'Mode', 'Year'],
                    'rows' => []
                ];
                foreach ($data['recent_transactions'] as $t) {
                    $table['rows'][] = [
                        date('d-m-Y', strtotime($t['TransactionDate'])),
                        htmlspecialchars($t['MemberName']),
                        formatCurrency((float)$t['CollectionAmount']),
                        formatCurrency((float)$t['DistributedAmount']),
                        htmlspecialchars($t['PaymentMode'] ?? 'N/A'),
                        htmlspecialchars($t['YearName'] ?? 'N/A')
                    ];
                }
            }
            break;

        case 'admin_info':
            if (!empty($data['admins'])) {
                $table = [
                    'headers' => ['ID', 'Username', 'Name', 'Email', 'Role', 'Status', 'Last Login'],
                    'rows' => []
                ];
                foreach ($data['admins'] as $a) {
                    $table['rows'][] = [
                        $a['AdminID'],
                        htmlspecialchars($a['Username']),
                        htmlspecialchars($a['FullName'] ?? 'N/A'),
                        htmlspecialchars($a['Email'] ?? 'N/A'),
                        htmlspecialchars($a['Role'] ?? 'Admin'),
                        $a['Status'],
                        $a['LastLogin'] ? date('d-m-Y H:i', strtotime($a['LastLogin'])) : 'Never'
                    ];
                }
            }
            break;

        case 'audit_logs':
            if (!empty($data['logs'])) {
                $table = [
                    'headers' => ['ID', 'User Type', 'Action', 'Details', 'IP', 'Date'],
                    'rows' => []
                ];
                foreach ($data['logs'] as $l) {
                    $table['rows'][] = [
                        $l['LogID'],
                        htmlspecialchars($l['UserType'] ?? 'N/A'),
                        htmlspecialchars($l['Action'] ?? 'N/A'),
                        htmlspecialchars(substr($l['Details'] ?? '', 0, 50)) . (strlen($l['Details'] ?? '') > 50 ? '...' : ''),
                        htmlspecialchars($l['IPAddress'] ?? 'N/A'),
                        $l['CreatedAt'] ? date('d-m-Y H:i', strtotime($l['CreatedAt'])) : 'N/A'
                    ];
                }
            }
            break;

        case 'monthly_analysis':
            if (!empty($data['monthly'])) {
                $table = [
                    'headers' => ['Month', 'Transactions', 'Collection', 'Distribution', 'Interest'],
                    'rows' => []
                ];
                foreach ($data['monthly'] as $m) {
                    $table['rows'][] = [
                        $m['month_label'],
                        number_format((int)$m['transaction_count']),
                        formatCurrency((float)$m['collection']),
                        formatCurrency((float)$m['distribution']),
                        formatCurrency((float)$m['interest'])
                    ];
                }
            }
            break;

        case 'year_summary':
            if (!empty($data['years'])) {
                $table = [
                    'headers' => ['Year', 'Status', 'Members', 'Transactions', 'Collection', 'Distribution', 'Interest'],
                    'rows' => []
                ];
                foreach ($data['years'] as $y) {
                    $table['rows'][] = [
                        htmlspecialchars($y['YearName']),
                        $y['Status'],
                        number_format((int)$y['member_count']),
                        number_format((int)$y['transaction_count']),
                        formatCurrency((float)$y['total_collection']),
                        formatCurrency((float)$y['total_distribution']),
                        formatCurrency((float)$y['total_interest'])
                    ];
                }
            }
            break;

        case 'member_search':
            if (!empty($data['member_transactions'])) {
                $table = [
                    'headers' => ['Date', 'Collection', 'Distribution', 'Interest', 'Mode', 'Year'],
                    'rows' => []
                ];
                foreach ($data['member_transactions'] as $t) {
                    $table['rows'][] = [
                        date('d-m-Y', strtotime($t['TransactionDate'])),
                        formatCurrency((float)$t['CollectionAmount']),
                        formatCurrency((float)$t['DistributedAmount']),
                        formatCurrency((float)$t['InterestAmount']),
                        htmlspecialchars($t['PaymentMode'] ?? 'N/A'),
                        htmlspecialchars($t['YearName'] ?? 'N/A')
                    ];
                }
            }
            break;

        case 'expense_info':
            if (!empty($data['recent_expenses'])) {
                $table = [
                    'headers' => ['Date', 'Description', 'Category', 'Amount', 'Vendor'],
                    'rows' => []
                ];
                foreach ($data['recent_expenses'] as $e) {
                    $table['rows'][] = [
                        isset($e['ExpenseDate']) ? date('d-m-Y', strtotime($e['ExpenseDate'])) : 'N/A',
                        htmlspecialchars(substr($e['Description'] ?? '', 0, 40)),
                        htmlspecialchars($e['Category'] ?? 'N/A'),
                        formatCurrency((float)($e['Amount'] ?? 0)),
                        htmlspecialchars($e['Vendor'] ?? 'N/A')
                    ];
                }
            }
            break;

        case 'member_info':
            if (!empty($data['recent_members'])) {
                $table = [
                    'headers' => ['ID', 'Name', 'Phone', 'Email', 'Status', 'Joined'],
                    'rows' => []
                ];
                foreach ($data['recent_members'] as $m) {
                    $table['rows'][] = [
                        $m['MemberID'],
                        htmlspecialchars($m['MemberName']),
                        htmlspecialchars($m['Phone'] ?? 'N/A'),
                        htmlspecialchars($m['Email'] ?? 'N/A'),
                        $m['Status'],
                        $m['CreatedAt'] ? date('d-m-Y', strtotime($m['CreatedAt'])) : 'N/A'
                    ];
                }
            }
            break;

        case 'system_info':
            if (!empty($data['table_counts'])) {
                $table = [
                    'headers' => ['Table', 'Records'],
                    'rows' => []
                ];
                foreach ($data['table_counts'] as $tc) {
                    $table['rows'][] = [$tc['name'], number_format((int)$tc['count'])];
                }
            }
            break;

        case 'event_info':
            if (!empty($data['events'])) {
                $table = [
                    'headers' => ['Event', 'Date', 'Venue', 'Status', 'Budget', 'Spent'],
                    'rows' => []
                ];
                foreach ($data['events'] as $e) {
                    $table['rows'][] = [
                        htmlspecialchars($e['EventName'] ?? 'N/A'),
                        isset($e['EventDate']) ? date('d-m-Y', strtotime($e['EventDate'])) : 'N/A',
                        htmlspecialchars($e['Venue'] ?? 'N/A'),
                        $e['Status'] ?? 'N/A',
                        isset($e['Budget']) ? formatCurrency((float)$e['Budget']) : 'N/A',
                        formatCurrency((float)($e['TotalExpense'] ?? 0))
                    ];
                }
            }
            break;

        case 'budget_info':
            if (!empty($data['by_category'])) {
                $table = [
                    'headers' => ['Category', 'Expenses', 'Amount'],
                    'rows' => []
                ];
                foreach ($data['by_category'] as $c) {
                    $table['rows'][] = [
                        htmlspecialchars($c['category']),
                        number_format((int)$c['count']),
                        formatCurrency((float)$c['amount'])
                    ];
                }
            }
            break;

        case 'notification_info':
            if (!empty($data['recent_notifications'])) {
                $table = [
                    'headers' => ['Title', 'Type', 'Priority', 'Status', 'Date'],
                    'rows' => []
                ];
                foreach ($data['recent_notifications'] as $n) {
                    $table['rows'][] = [
                        htmlspecialchars(substr($n['Title'] ?? '', 0, 40)),
                        htmlspecialchars($n['NotificationType'] ?? 'N/A'),
                        htmlspecialchars($n['Priority'] ?? 'Normal'),
                        ((int)($n['IsRead'] ?? 0)) ? 'Read' : 'Unread',
                        $n['CreatedAt'] ? date('d-m-Y H:i', strtotime($n['CreatedAt'])) : 'N/A'
                    ];
                }
            }
            break;

        case 'permission_info':
            if (!empty($data['admin_permissions'])) {
                $table = [
                    'headers' => ['Admin', 'Role', 'Status', 'View Members', 'Add Members', 'View TX', 'Add TX', 'Reports', 'Backup'],
                    'rows' => []
                ];
                foreach ($data['admin_permissions'] as $a) {
                    $table['rows'][] = [
                        htmlspecialchars($a['FullName'] ?? $a['Username']),
                        htmlspecialchars($a['Role'] ?? 'Admin'),
                        $a['Status'],
                        ((int)($a['can_view_members'] ?? 0)) ? 'Yes' : 'No',
                        ((int)($a['can_add_members'] ?? 0)) ? 'Yes' : 'No',
                        ((int)($a['can_view_transactions'] ?? 0)) ? 'Yes' : 'No',
                        ((int)($a['can_add_transactions'] ?? 0)) ? 'Yes' : 'No',
                        ((int)($a['can_view_reports'] ?? 0)) ? 'Yes' : 'No',
                        ((int)($a['can_backup_data'] ?? 0)) ? 'Yes' : 'No'
                    ];
                }
            }
            break;

        case 'backup_info':
            if (!empty($data['backups'])) {
                $table = [
                    'headers' => ['ID', 'File Name', 'Size', 'Type', 'Status', 'Date'],
                    'rows' => []
                ];
                foreach ($data['backups'] as $b) {
                    $size = isset($b['FileSize']) ? round((float)$b['FileSize'] / 1024, 1) . ' KB' : 'N/A';
                    $table['rows'][] = [
                        $b['BackupID'] ?? '',
                        htmlspecialchars(substr($b['FileName'] ?? '', 0, 35)),
                        $size,
                        htmlspecialchars($b['BackupType'] ?? 'Full'),
                        htmlspecialchars($b['Status'] ?? 'N/A'),
                        $b['CreatedAt'] ? date('d-m-Y H:i', strtotime($b['CreatedAt'])) : 'N/A'
                    ];
                }
            }
            break;

        case 'comparison':
            if (!empty($data['year_comparison'])) {
                $table = [
                    'headers' => ['Year', 'Members', 'Transactions', 'Collection', 'Distribution', 'Interest', 'Surplus/Deficit'],
                    'rows' => []
                ];
                foreach ($data['year_comparison'] as $y) {
                    $surplus = (float)$y['collection'] - (float)$y['distribution'];
                    $table['rows'][] = [
                        htmlspecialchars($y['YearName']),
                        number_format((int)$y['active_members']),
                        number_format((int)$y['tx_count']),
                        formatCurrency((float)$y['collection']),
                        formatCurrency((float)$y['distribution']),
                        formatCurrency((float)$y['interest']),
                        ($surplus >= 0 ? '+' : '') . formatCurrency($surplus)
                    ];
                }
            }
            break;

        case 'top_performers':
            if (!empty($data['top_members'])) {
                $table = [
                    'headers' => ['Rank', 'Member', 'Status', 'Transactions', 'Collection', 'Distribution', 'Interest', '% of Total'],
                    'rows' => []
                ];
                $grandTotal = $data['grand_total'] ?? 1;
                foreach ($data['top_members'] as $i => $m) {
                    $pct = $grandTotal > 0 ? round(((float)$m['total_collection'] / $grandTotal) * 100, 1) : 0;
                    $table['rows'][] = [
                        $i + 1,
                        htmlspecialchars($m['MemberName']),
                        $m['Status'],
                        number_format((int)$m['tx_count']),
                        formatCurrency((float)$m['total_collection']),
                        formatCurrency((float)$m['total_distribution']),
                        formatCurrency((float)$m['total_interest']),
                        $pct . '%'
                    ];
                }
            }
            break;

        case 'defaulters':
            if (!empty($data['defaulters'])) {
                $table = [
                    'headers' => ['Member', 'Phone', 'Status', 'Last Payment', 'Total Paid', 'Transactions'],
                    'rows' => []
                ];
                foreach ($data['defaulters'] as $d) {
                    $table['rows'][] = [
                        htmlspecialchars($d['MemberName']),
                        htmlspecialchars($d['Phone'] ?? 'N/A'),
                        $d['Status'],
                        $d['last_transaction'] ? date('d-m-Y', strtotime($d['last_transaction'])) : 'Never',
                        formatCurrency((float)($d['total_paid'] ?? 0)),
                        number_format((int)($d['tx_count'] ?? 0))
                    ];
                }
            }
            break;

        case 'smart_fallback':
            if (isset($data['overview'])) {
                $o = $data['overview'];
                $table = [
                    'headers' => ['Metric', 'Value'],
                    'rows' => [
                        ['Total Members', number_format((int)$o['total_members'])],
                        ['Active Members', number_format((int)$o['active_members'])],
                        ['Total Admins', number_format((int)$o['total_admins'])],
                        ['Academic Years', number_format((int)$o['total_years'])],
                        ['Total Transactions', number_format((int)$o['total_transactions'])],
                        ['Total Collection', formatCurrency((float)$o['total_collection'])],
                        ['Total Distribution', formatCurrency((float)$o['total_distribution'])]
                    ]
                ];
            }
            break;
    }

    return $table;
}

// ============================================================
// SUGGESTIONS
// ============================================================

function generateSuggestions($intent) {
    $suggestions = [
        'member_info'          => ['Show transaction summary', 'Find a specific member', 'Top performers', 'Monthly analysis'],
        'member_search'        => ['Show transaction summary', 'How many active members?', 'Show net balance', 'Year-wise summary'],
        'transaction_summary'  => ['Show monthly breakdown', 'Payment mode stats', 'Show net balance', 'Recent transactions'],
        'transaction_details'  => ['Show transaction summary', 'Payment mode breakdown', 'Year-wise summary', 'Show statistics'],
        'year_summary'         => ['Compare years', 'Monthly collection trends', 'Show net balance', 'Top performers'],
        'statistics'           => ['Show transaction summary', 'Monthly analysis', 'Payment mode breakdown', 'Top performers'],
        'recent_activity'      => ['Show transaction summary', 'How many members?', 'Audit logs', 'Show defaulters'],
        'verification'         => ['Show transaction details', 'Recent transactions', 'Transaction summary', 'Statistics'],
        'payment_modes'        => ['Monthly trends', 'Transaction details', 'Show statistics', 'Year-wise summary'],
        'monthly_analysis'     => ['Year-wise summary', 'Payment modes', 'Transaction summary', 'Compare years'],
        'expense_info'         => ['Show net balance', 'Budget tracking', 'Event details', 'Monthly trends'],
        'budget_info'          => ['Show expenses', 'Net balance', 'Event details', 'Transaction summary'],
        'event_info'           => ['Show expenses', 'Budget tracking', 'Transaction summary', 'Monthly analysis'],
        'admin_info'           => ['Permissions overview', 'Audit logs', 'System info', 'Member information'],
        'audit_logs'           => ['Recent activity', 'System info', 'Admin info', 'Who are the defaulters?'],
        'net_balance'          => ['Expense breakdown', 'Budget tracking', 'Year-wise summary', 'Monthly analysis'],
        'notification_info'    => ['Recent activity', 'Audit logs', 'System info', 'Admin info'],
        'permission_info'      => ['Admin info', 'Audit logs', 'System settings', 'Member permissions'],
        'backup_info'          => ['System info', 'Database size', 'Audit logs', 'Settings'],
        'system_info'          => ['Backup history', 'Admin info', 'Audit logs', 'Transaction summary'],
        'comparison'           => ['Year-wise summary', 'Monthly analysis', 'Top performers', 'Net balance'],
        'top_performers'       => ['Show defaulters', 'Member info', 'Transaction summary', 'Year comparison'],
        'defaulters'           => ['Top performers', 'Member info', 'Transaction summary', 'Recent activity'],
        'greeting'             => ['Show transaction summary', 'How many members?', 'Net balance', 'Recent activity', 'Show expenses', 'System status'],
        'smart_fallback'       => ['Show help', 'Transaction summary', 'Member info', 'Show expenses', 'Event details', 'Recent activity'],
        'help'                 => ['Show transaction summary', 'How many members?', 'Net balance calculation', 'Show expenses', 'Event details', 'Top performers']
    ];

    return $suggestions[$intent] ?? ['Show help', 'Transaction summary', 'Member info', 'Recent activity'];
}

// ============================================================
// MAIN EXECUTION
// ============================================================

$intentResult = analyzeIntent($query);
$intent = $intentResult['intent'];
$params = $intentResult['params'];

$data = fetchRelevantData($intent, $params, $pdo);
$calculations = performCalculations($intent, $data);
$chart = generateChartConfig($intent, $data);
$table = generateDataTable($intent, $data);
$answer = generateResponse($intent, $data, $calculations);
$suggestions = generateSuggestions($intent);

// Build proof array with URLs
$proofOutput = [];
if (!empty($data['proof'])) {
    foreach ($data['proof'] as $p) {
        $url = '';
        $thumbnail = '';
        if (!empty($p['GoogleDriveURL'])) {
            $url = $p['GoogleDriveURL'];
            $thumbnail = $p['GoogleDriveThumbnail'] ?? $url;
        } elseif (!empty($p['LocalPath'])) {
            $url = BASE_URL . '/' . ltrim($p['LocalPath'], '/');
            $thumbnail = $url;
        }
        if ($url) {
            $proofOutput[] = [
                'id' => $p['AttachmentID'],
                'name' => $p['OriginalName'] ?? 'Attachment',
                'url' => $url,
                'thumbnail' => $thumbnail,
                'type' => $p['MimeType'] ?? 'application/octet-stream',
                'size' => $p['FileSize'] ?? 0,
                'entity_type' => $p['EntityType'] ?? '',
                'entity_id' => $p['EntityID'] ?? ''
            ];
        }
    }
}

// Final response
$response = [
    'answer' => $answer,
    'data' => [
        'table' => $table
    ],
    'calculations' => $calculations,
    'charts' => $chart,
    'proof' => $proofOutput,
    'suggestions' => $suggestions,
    'intent' => $intent,
    'error' => null
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
