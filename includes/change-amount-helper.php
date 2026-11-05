<?php
/**
 * Change Amount transactions.
 *
 * Each entry has a date, year, amount (+ add / - deduct), reason and an optional member.
 *   - Entries with a member change that member's net balance (Distributed + Interest - Collection ± change).
 *   - Entries without a member are colony-level money.
 * academic_years.ChangeAmount is kept equal to the total of ALL entries of the year, so every page that
 * already uses it (dashboards, accounting, expense PDFs, chatbot) shows the right figure.
 * The system net balance adds only the general (no member) part, because member parts are in member balances.
 *
 * Requires: $pdo (config.php)
 */

if (!function_exists('caEnsureTable')) {
    /** Create the table on first use; existing year ChangeAmount values become "Opening" entries */
    function caEnsureTable(PDO $pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'change_amount_transactions'")->fetchColumn();
        if ($exists) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS change_amount_transactions (
                ChangeID INT AUTO_INCREMENT PRIMARY KEY,
                YearID INT NOT NULL,
                MemberID INT DEFAULT NULL COMMENT 'Empty = general (colony) change amount',
                TransactionDate DATE NOT NULL,
                Amount DECIMAL(12,2) NOT NULL COMMENT 'Positive = add, negative = deduct',
                Description VARCHAR(255) NOT NULL DEFAULT '',
                PaymentMode VARCHAR(30) DEFAULT NULL,
                ReferenceNumber VARCHAR(60) DEFAULT NULL,
                Remarks TEXT DEFAULT NULL,
                CreatedBy INT DEFAULT NULL,
                UpdatedBy INT DEFAULT NULL,
                CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_ca_year (YearID),
                KEY idx_ca_member (MemberID),
                KEY idx_ca_date (TransactionDate)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Keep the amounts entered before: one opening entry per year
        $years = $pdo->query('SELECT YearID, StartDate, ChangeAmount FROM academic_years WHERE COALESCE(ChangeAmount, 0) <> 0')->fetchAll(PDO::FETCH_ASSOC);
        $ins = $pdo->prepare("INSERT INTO change_amount_transactions (YearID, MemberID, TransactionDate, Amount, Description, Remarks) VALUES (?, NULL, ?, ?, 'Opening change amount', 'Carried over from the Academic Year setting')");
        foreach ($years as $y) {
            $ins->execute([$y['YearID'], $y['StartDate'], $y['ChangeAmount']]);
        }
    }

    function caTableExists(PDO $pdo) {
        return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'change_amount_transactions'")->fetchColumn();
    }

    /** academic_years.ChangeAmount = total of the year's entries */
    function caSyncYear(PDO $pdo, $yearId) {
        $pdo->prepare('UPDATE academic_years SET ChangeAmount = (SELECT COALESCE(SUM(Amount), 0) FROM change_amount_transactions WHERE YearID = ?) WHERE YearID = ?')
            ->execute([(int)$yearId, (int)$yearId]);
    }

    /**
     * Change amount per member: [MemberID => total]. Optional year, member list and date limits
     * ($before = strictly before that date; $from/$to = inclusive range).
     */
    function caMemberTotals(PDO $pdo, $yearId = null, ?array $memberIds = null, $before = null, $from = null, $to = null) {
        try {
            caEnsureTable($pdo);
            $where = ['MemberID IS NOT NULL'];
            $args = [];
            if ($yearId) { $where[] = 'YearID = ?'; $args[] = (int)$yearId; }
            if ($memberIds !== null) {
                if (!$memberIds) return [];
                $where[] = 'MemberID IN (' . implode(',', array_fill(0, count($memberIds), '?')) . ')';
                $args = array_merge($args, array_map('intval', $memberIds));
            }
            if ($before) { $where[] = 'TransactionDate < ?'; $args[] = $before; }
            if ($from) { $where[] = 'TransactionDate >= ?'; $args[] = $from; }
            if ($to) { $where[] = 'TransactionDate <= ?'; $args[] = $to; }
            $s = $pdo->prepare('SELECT MemberID, SUM(Amount) FROM change_amount_transactions WHERE ' . implode(' AND ', $where) . ' GROUP BY MemberID');
            $s->execute($args);
            return array_map('floatval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Exception $e) {
            error_log('Change amount member totals: ' . $e->getMessage());
            return [];
        }
    }

    /** One member's change amount total (year optional) */
    function caMemberTotal(PDO $pdo, $memberId, $yearId = null) {
        $t = caMemberTotals($pdo, $yearId, [(int)$memberId]);
        return (float)($t[(int)$memberId] ?? 0);
    }

    /** Totals of a year (or all years): ['total', 'general', 'members', 'count'] */
    function caTotals(PDO $pdo, $yearId = null, $from = null, $to = null, $memberId = null) {
        try {
            caEnsureTable($pdo);
            $where = ['1=1'];
            $args = [];
            if ($yearId) { $where[] = 'YearID = ?'; $args[] = (int)$yearId; }
            if ($from) { $where[] = 'TransactionDate >= ?'; $args[] = $from; }
            if ($to) { $where[] = 'TransactionDate <= ?'; $args[] = $to; }
            if ($memberId) { $where[] = 'MemberID = ?'; $args[] = (int)$memberId; }
            $s = $pdo->prepare('SELECT COALESCE(SUM(Amount), 0), COALESCE(SUM(CASE WHEN MemberID IS NULL THEN Amount END), 0),
                    COALESCE(SUM(CASE WHEN MemberID IS NOT NULL THEN Amount END), 0), COUNT(*) FROM change_amount_transactions WHERE ' . implode(' AND ', $where));
            $s->execute($args);
            [$total, $general, $members, $count] = $s->fetch(PDO::FETCH_NUM);
            return ['total' => (float)$total, 'general' => (float)$general, 'members' => (float)$members, 'count' => (int)$count];
        } catch (Exception $e) {
            error_log('Change amount totals: ' . $e->getMessage());
            return ['total' => 0.0, 'general' => 0.0, 'members' => 0.0, 'count' => 0];
        }
    }

    /** Entries of members in a period, grouped by member (for bills and statements) */
    function caMemberEntries(PDO $pdo, $yearId, $from, $to, array $memberIds) {
        if (!$memberIds) return [];
        try {
            caEnsureTable($pdo);
            $in = implode(',', array_fill(0, count($memberIds), '?'));
            $s = $pdo->prepare("SELECT ChangeID, MemberID, TransactionDate, Amount, Description, PaymentMode, ReferenceNumber FROM change_amount_transactions
                WHERE YearID = ? AND TransactionDate BETWEEN ? AND ? AND MemberID IN ($in) ORDER BY TransactionDate, ChangeID");
            $s->execute(array_merge([(int)$yearId, $from, $to], array_map('intval', $memberIds)));
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['MemberID']][] = $r;
            return $out;
        } catch (Exception $e) {
            error_log('Change amount entries: ' . $e->getMessage());
            return [];
        }
    }
}
