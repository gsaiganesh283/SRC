<?php
/**
 * Formula language for the Custom Report Builder.
 *
 *   Values      12  3.5  "text"  {FieldName}  TRUE  FALSE
 *   Operators   + - * / % ^   & (join text)   = <> != < <= > >=
 *   Functions   see rbFormulaFunctions()
 *
 * Row formulas are evaluated once per row. Aggregate functions (SUM, AVG, COUNTIF, RUNNING...)
 * look across the report's rows. In "summary" mode (calculation lines, recalculated totals) a bare
 * {Field} means the total of that field over the rows in scope.
 *
 * Formulas are parsed into an AST and interpreted here - nothing is ever passed to eval() or SQL.
 */

/**
 * Function reference (also served to the UI). Each entry: [category, syntax, description]
 */
function rbFormulaFunctions() {
    return [
        // Maths
        'ROUND' => ['Maths', 'ROUND(x, digits)', 'Round to a number of decimals (default 0)'],
        'ROUNDUP' => ['Maths', 'ROUNDUP(x, digits)', 'Round away from zero'],
        'ROUNDDOWN' => ['Maths', 'ROUNDDOWN(x, digits)', 'Round towards zero'],
        'FLOOR' => ['Maths', 'FLOOR(x)', 'Largest whole number not above x'],
        'CEIL' => ['Maths', 'CEIL(x)', 'Smallest whole number not below x'],
        'INT' => ['Maths', 'INT(x)', 'Whole-number part of x'],
        'ABS' => ['Maths', 'ABS(x)', 'Value without its sign'],
        'SIGN' => ['Maths', 'SIGN(x)', '1, 0 or -1'],
        'SQRT' => ['Maths', 'SQRT(x)', 'Square root'],
        'POWER' => ['Maths', 'POWER(x, n)', 'x raised to n (same as x ^ n)'],
        'MOD' => ['Maths', 'MOD(x, n)', 'Remainder of x / n'],
        'PCT' => ['Maths', 'PCT(part, whole)', 'part as a percentage of whole'],
        'MIN' => ['Maths', 'MIN(a, b, ...)', 'Smallest value (one argument = smallest in the report)'],
        'MAX' => ['Maths', 'MAX(a, b, ...)', 'Largest value (one argument = largest in the report)'],
        // Finance
        'INTEREST' => ['Finance', 'INTEREST(principal, rate%, periods)', 'Simple interest: principal × rate% × periods'],
        'COMPOUND' => ['Finance', 'COMPOUND(principal, rate%, periods)', 'Compound interest earned over the periods'],
        'EMI' => ['Finance', 'EMI(principal, rate%, periods)', 'Equal instalment per period'],
        // Totals across the report
        'SUM' => ['Report totals', 'SUM(expr)', 'Total of expr over all rows (or SUM(a, b, ...) adds values)'],
        'AVG' => ['Report totals', 'AVG(expr)', 'Average of expr over all rows'],
        'COUNT' => ['Report totals', 'COUNT(expr)', 'Rows where expr is not blank; COUNT() = number of rows'],
        'SUMIF' => ['Report totals', 'SUMIF(expr, condition)', 'Total of expr over rows where the condition is true'],
        'AVGIF' => ['Report totals', 'AVGIF(expr, condition)', 'Average of expr over rows where the condition is true'],
        'COUNTIF' => ['Report totals', 'COUNTIF(condition)', 'Number of rows where the condition is true'],
        'MINIF' => ['Report totals', 'MINIF(expr, condition)', 'Smallest expr among rows where the condition is true'],
        'MAXIF' => ['Report totals', 'MAXIF(expr, condition)', 'Largest expr among rows where the condition is true'],
        'PCTTOTAL' => ['Report totals', 'PCTTOTAL(expr)', 'This row as a percentage of the report total'],
        'RANK' => ['Report totals', 'RANK(expr)', 'Position of this row from the highest (1 = highest)'],
        'GSUM' => ['Group totals', 'GSUM(expr)', 'Total of expr within this row\'s group'],
        'GAVG' => ['Group totals', 'GAVG(expr)', 'Average of expr within this row\'s group'],
        'GCOUNT' => ['Group totals', 'GCOUNT()', 'Number of rows in this row\'s group'],
        // Running
        'RUNNING' => ['Running', 'RUNNING(expr)', 'Running total down the report (e.g. a balance)'],
        'GRUNNING' => ['Running', 'GRUNNING(expr)', 'Running total that restarts in each group'],
        'PREV' => ['Running', 'PREV(expr)', 'Value of expr on the previous row'],
        'ROWNUM' => ['Running', 'ROWNUM()', 'Row number in the report'],
        // Logic
        'IF' => ['Logic', 'IF(condition, then, else)', 'Choose a value; e.g. IF({Amount} > 1000, "High", "Low")'],
        'AND' => ['Logic', 'AND(a, b, ...)', 'True when every condition is true'],
        'OR' => ['Logic', 'OR(a, b, ...)', 'True when any condition is true'],
        'NOT' => ['Logic', 'NOT(condition)', 'Opposite of the condition'],
        'IFBLANK' => ['Logic', 'IFBLANK(value, fallback)', 'Use the fallback when the value is blank'],
        'ISBLANK' => ['Logic', 'ISBLANK(value)', 'True when the value is empty'],
        'IN' => ['Logic', 'IN(value, a, b, ...)', 'True when the value equals any of the choices, e.g. IN({PaymentMode}, "UPI", "Cash")'],
        'BETWEEN' => ['Logic', 'BETWEEN(value, low, high)', 'True when low ≤ value ≤ high (numbers, dates "2026-04-01" or text)'],
        'STARTSWITH' => ['Text', 'STARTSWITH(text, start)', 'True when the text begins with start (any case)'],
        'ENDSWITH' => ['Text', 'ENDSWITH(text, end)', 'True when the text ends with end (any case)'],
        // Text
        'CONCAT' => ['Text', 'CONCAT(a, b, ...)', 'Join text (same as a & b)'],
        'UPPER' => ['Text', 'UPPER(text)', 'Capital letters'],
        'LOWER' => ['Text', 'LOWER(text)', 'Small letters'],
        'LEFT' => ['Text', 'LEFT(text, n)', 'First n characters'],
        'RIGHT' => ['Text', 'RIGHT(text, n)', 'Last n characters'],
        'LEN' => ['Text', 'LEN(text)', 'Number of characters'],
        'CONTAINS' => ['Text', 'CONTAINS(text, find)', 'True when the text contains find (any case)'],
        // Dates
        'TODAY' => ['Dates', 'TODAY()', 'Today\'s date'],
        'DATEFROM' => ['Dates', 'DATEFROM()', 'Start of the report\'s date range (step 1)'],
        'DATETO' => ['Dates', 'DATETO()', 'End of the report\'s date range (step 1)'],
        'DAYS' => ['Dates', 'DAYS(end, start)', 'Days from start to end'],
        'MONTHS' => ['Dates', 'MONTHS(end, start)', 'Whole months from start to end'],
        'YEAR' => ['Dates', 'YEAR(date)', 'Year number'],
        'MONTH' => ['Dates', 'MONTH(date)', 'Month number (1-12)'],
        'DAY' => ['Dates', 'DAY(date)', 'Day of the month'],
        'MONTHNAME' => ['Dates', 'MONTHNAME(date)', 'Month name, e.g. April'],
        'ADDDAYS' => ['Dates', 'ADDDAYS(date, n)', 'Date n days later'],
        'ADDMONTHS' => ['Dates', 'ADDMONTHS(date, n)', 'Date n months later'],
    ];
}

/** Functions whose single argument is evaluated across many rows */
const RB_AGG_FUNCS = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'SUMIF', 'AVGIF', 'MINIF', 'MAXIF', 'COUNTIF', 'PCTTOTAL', 'RANK', 'GSUM', 'GAVG', 'GCOUNT', 'RUNNING', 'GRUNNING', 'PREV'];

// ── Parsing ───────────────────────────────────────────────────────────────

/**
 * Parse a formula. Returns ['ast' => node|null, 'error' => string|null, 'refs' => [field keys]]
 */
function rbParseFormula(string $formula) {
    static $cache = [];
    if (isset($cache[$formula])) {
        return $cache[$formula];
    }
    $result = ['ast' => null, 'error' => null, 'refs' => []];
    try {
        $tokens = rbTokenize($formula);
        if (!$tokens) {
            throw new InvalidArgumentException('The formula is empty');
        }
        $p = new RbFormulaParser($tokens);
        $result['ast'] = $p->parse();
        $result['refs'] = array_values(array_unique($p->refs));
    } catch (InvalidArgumentException $e) {
        $result['error'] = $e->getMessage();
    }
    if (count($cache) > 500) $cache = [];
    return $cache[$formula] = $result;
}

function rbTokenize(string $s) {
    $tokens = [];
    $i = 0;
    $n = strlen($s);
    while ($i < $n) {
        $c = $s[$i];
        if (ctype_space($c)) { $i++; continue; }
        if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
            preg_match('/\d*\.?\d+(?:[eE][-+]?\d+)?/A', $s, $m, 0, $i);
            $tokens[] = ['num', (float)$m[0]];
            $i += strlen($m[0]);
            continue;
        }
        if ($c === '"' || $c === "'") {
            $end = strpos($s, $c, $i + 1);
            if ($end === false) throw new InvalidArgumentException('Text started with ' . $c . ' is not closed');
            $tokens[] = ['str', substr($s, $i + 1, $end - $i - 1)];
            $i = $end + 1;
            continue;
        }
        if ($c === '{') {
            $end = strpos($s, '}', $i);
            if ($end === false) throw new InvalidArgumentException('A field name started with { is not closed with }');
            $name = trim(substr($s, $i + 1, $end - $i - 1));
            if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $name)) throw new InvalidArgumentException('"{' . $name . '}" is not a valid field name (use {Field} or {module.Field})');
            $tokens[] = ['field', $name];
            $i = $end + 1;
            continue;
        }
        if (ctype_alpha($c) || $c === '_') {
            preg_match('/[A-Za-z_][A-Za-z0-9_]*/A', $s, $m, 0, $i);
            $tokens[] = ['id', strtoupper($m[0])];
            $i += strlen($m[0]);
            continue;
        }
        foreach (['<=', '>=', '<>', '!=', '=='] as $op2) {
            if (substr($s, $i, 2) === $op2) {
                $tokens[] = ['op', $op2 === '==' ? '=' : ($op2 === '!=' ? '<>' : $op2)];
                $i += 2;
                continue 2;
            }
        }
        if (strpos('+-*/%^&=<>(),', $c) !== false) {
            $tokens[] = ['op', $c];
            $i++;
            continue;
        }
        // Accept the × and ÷ symbols people type from the keyboard or copy from documents
        if (substr($s, $i, 2) === "\xC3\x97" || substr($s, $i, 2) === "\xC3\xB7") {
            $tokens[] = ['op', substr($s, $i, 2) === "\xC3\x97" ? '*' : '/'];
            $i += 2;
            continue;
        }
        throw new InvalidArgumentException('Unexpected character "' . mb_substr(substr($s, $i), 0, 1) . '"');
    }
    return $tokens;
}

class RbFormulaParser
{
    private $t;
    private $p = 0;
    private $id = 0;
    public $refs = [];

    public function __construct(array $tokens) { $this->t = $tokens; }

    public function parse() {
        $node = $this->comparison();
        if ($this->p < count($this->t)) {
            throw new InvalidArgumentException('Unexpected "' . $this->t[$this->p][1] . '" — check for a missing operator or bracket');
        }
        return $node;
    }

    private function peekOp($ops) {
        $tok = $this->t[$this->p] ?? null;
        return ($tok && $tok[0] === 'op' && in_array($tok[1], (array)$ops, true)) ? $tok[1] : null;
    }

    private function comparison() {
        $left = $this->concat();
        if ($op = $this->peekOp(['=', '<>', '<', '<=', '>', '>='])) {
            $this->p++;
            $left = ['bin', $op, $left, $this->concat()];
        }
        return $left;
    }

    private function concat() {
        $left = $this->additive();
        while ($this->peekOp('&')) {
            $this->p++;
            $left = ['bin', '&', $left, $this->additive()];
        }
        return $left;
    }

    private function additive() {
        $left = $this->term();
        while ($op = $this->peekOp(['+', '-'])) {
            $this->p++;
            $left = ['bin', $op, $left, $this->term()];
        }
        return $left;
    }

    private function term() {
        $left = $this->power();
        while ($op = $this->peekOp(['*', '/', '%'])) {
            $this->p++;
            $left = ['bin', $op, $left, $this->power()];
        }
        return $left;
    }

    private function power() {
        $base = $this->unary();
        if ($this->peekOp('^')) {
            $this->p++;
            return ['bin', '^', $base, $this->power()];
        }
        return $base;
    }

    private function unary() {
        if ($op = $this->peekOp(['-', '+'])) {
            $this->p++;
            $v = $this->unary();
            return $op === '-' ? ['neg', $v] : $v;
        }
        return $this->primary();
    }

    private function primary() {
        $tok = $this->t[$this->p++] ?? null;
        if ($tok === null) throw new InvalidArgumentException('The formula ends too early');
        switch ($tok[0]) {
            case 'num': return ['num', $tok[1]];
            case 'str': return ['str', $tok[1]];
            case 'field':
                $this->refs[] = $tok[1];
                return ['field', $tok[1]];
            case 'id':
                $name = $tok[1];
                if ($this->peekOp('(')) {
                    $this->p++;
                    $args = [];
                    if (!$this->peekOp(')')) {
                        do {
                            $args[] = $this->comparison();
                        } while ($this->peekOp(',') && ++$this->p);
                    }
                    if (!$this->peekOp(')')) throw new InvalidArgumentException('Missing ) after the arguments of ' . $name);
                    $this->p++;
                    if (!isset(rbFormulaFunctions()[$name])) throw new InvalidArgumentException('Unknown function ' . $name . '()');
                    return ['call', $name, $args, ++$this->id];
                }
                if ($name === 'TRUE') return ['num', 1.0];
                if ($name === 'FALSE') return ['num', 0.0];
                throw new InvalidArgumentException('Unknown word "' . $name . '" — wrap field names in { }, e.g. {Amount}');
            case 'op':
                if ($tok[1] === '(') {
                    $v = $this->comparison();
                    if (!$this->peekOp(')')) throw new InvalidArgumentException('A bracket ( is not closed');
                    $this->p++;
                    return $v;
                }
        }
        throw new InvalidArgumentException('Unexpected "' . $tok[1] . '"');
    }
}

// ── Evaluation ────────────────────────────────────────────────────────────

/**
 * Evaluation context.
 *   row     current row (assoc array)
 *   rows    all rows in display order
 *   index   position of row in rows (null in summary mode)
 *   group   rows of the current group (defaults to rows)
 *   gindex  position of row in group
 *   summary true for calculation lines / recalculated totals
 *   memo    shared cache (per pass)
 */
class RbFormulaContext
{
    public $row = [];
    public $rows = [];
    public $index = null;
    public $group = null;
    public $gindex = null;
    public $groupKey = '';
    public $summary = false;
    public $memo = [];

    /**
     * Shared by every formula of the report being built:
     *   foreign    rows of other modules, keyed by prefix, each row keyed "prefix.Field"
     *   date_from / date_to   the report's date range (for DATEFROM() / DATETO())
     */
    public static $env = ['foreign' => [], 'date_from' => '', 'date_to' => ''];
}

function rbNum($v) {
    if ($v === null || $v === '' || $v === false) return 0.0;
    if (is_int($v) || is_float($v)) return (float)$v;
    if (is_numeric($v)) return (float)$v;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($clean) ? (float)$clean : 0.0;
}

function rbIsBlank($v) {
    return $v === null || $v === '' || (is_string($v) && trim($v) === '');
}

/**
 * Compare two values: numbers numerically, dates by their date part (so "2026-03-31 18:00"
 * counts as 31 March), anything else as text ignoring case. Returns -1, 0 or 1.
 */
function rbCompare($x, $y) {
    $numLike = function ($v) { return $v === null || is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v))); };
    if ($numLike($x) && $numLike($y)) {
        return rbNum($x) <=> rbNum($y);
    }
    $a = trim((string)($x ?? ''));
    $b = trim((string)($y ?? ''));
    $isDate = '/^\d{4}-\d{2}-\d{2}/';
    if (preg_match($isDate, $a) && preg_match($isDate, $b)) {
        return strcmp(substr($a, 0, 10), substr($b, 0, 10)) <=> 0;
    }
    return strcasecmp($a, $b) <=> 0;
}

function rbTruthy($v) {
    if (is_string($v) && !is_numeric($v)) return trim($v) !== '';
    return rbNum($v) != 0;
}

function rbDateTs($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v) && $v > 20000 && $v < 80000) return (int)(($v - 25569) * 86400); // Excel-style serial
    $ts = strtotime(substr((string)$v, 0, 10) . ' 00:00:00 UTC');
    return $ts === false ? null : $ts;
}

/**
 * Evaluate a parsed formula. Returns float|string|null.
 */
function rbEvalNode($node, RbFormulaContext $ctx) {
    switch ($node[0]) {
        case 'num': return $node[1];
        case 'str': return $node[1];
        case 'neg': return -rbNum(rbEvalNode($node[1], $ctx));
        case 'field':
            // {module.Field} outside a row of that module: the field's total across the module
            if (strpos($node[1], '.') !== false && !array_key_exists($node[1], $ctx->row)) {
                return rbForeignTotal($node[1], $ctx);
            }
            if ($ctx->summary) {
                // In summary mode a bare field stands for its total over the rows in scope
                $set = $ctx->group ?? $ctx->rows;
                $sum = 0.0;
                $numeric = false;
                foreach ($set as $r) {
                    $v = $r[$node[1]] ?? null;
                    if (!rbIsBlank($v)) {
                        $numeric = $numeric || is_numeric($v);
                        $sum += rbNum($v);
                    }
                }
                if ($numeric) return $sum;
                return $set ? ($set[0][$node[1]] ?? null) : null;
            }
            return $ctx->row[$node[1]] ?? null;
        case 'bin':
            [, $op, $a, $b] = $node;
            $x = rbEvalNode($a, $ctx);
            $y = rbEvalNode($b, $ctx);
            switch ($op) {
                case '+': return rbNum($x) + rbNum($y);
                case '-': return rbNum($x) - rbNum($y);
                case '*': return rbNum($x) * rbNum($y);
                case '/': return rbNum($y) == 0 ? null : rbNum($x) / rbNum($y);
                case '%': return rbNum($y) == 0 ? null : fmod(rbNum($x), rbNum($y));
                case '^':
                    $r = pow(rbNum($x), rbNum($y));
                    return is_finite($r) ? $r : null;
                case '&': return (string)($x ?? '') . (string)($y ?? '');
                default:
                    $cmp = rbCompare($x, $y);
                    switch ($op) {
                        case '=': return (float)($cmp === 0);
                        case '<>': return (float)($cmp !== 0);
                        case '<': return (float)($cmp < 0);
                        case '<=': return (float)($cmp <= 0);
                        case '>': return (float)($cmp > 0);
                        case '>=': return (float)($cmp >= 0);
                    }
            }
            return null;
        case 'call':
            return rbCall($node, $ctx);
    }
    return null;
}

/** Total of {module.Field} over all rows of that module (or its first value for text) */
function rbForeignTotal($qualified, RbFormulaContext $ctx) {
    $key = 'T' . $qualified;
    if (!array_key_exists($key, $ctx->memo)) {
        $prefix = substr($qualified, 0, strpos($qualified, '.'));
        $rows = RbFormulaContext::$env['foreign'][$prefix] ?? [];
        $sum = 0.0;
        $numeric = false;
        foreach ($rows as $r) {
            $v = $r[$qualified] ?? null;
            if (!rbIsBlank($v)) {
                $numeric = $numeric || is_numeric($v);
                $sum += rbNum($v);
            }
        }
        $ctx->memo[$key] = $numeric ? $sum : ($rows ? ($rows[0][$qualified] ?? null) : null);
    }
    return $ctx->memo[$key];
}

/** Field references in a parsed expression: [qualified prefixes, has plain (this-report) fields] */
function rbNodeScope($node) {
    $prefixes = [];
    $plain = false;
    $walk = function ($n) use (&$walk, &$prefixes, &$plain) {
        if (!is_array($n)) return;
        if ($n[0] === 'field') {
            if (strpos($n[1], '.') !== false) $prefixes[substr($n[1], 0, strpos($n[1], '.'))] = true;
            else $plain = true;
            return;
        }
        foreach ($n as $child) {
            if (is_array($child)) {
                if (isset($child[0]) && is_string($child[0])) $walk($child);
                else foreach ($child as $c) $walk($c);
            }
        }
    };
    $walk($node);
    return [array_keys($prefixes), $plain];
}

/** Evaluate $expr for every row in $set (a fresh row context for each) */
function rbEvalOver($expr, array $set, RbFormulaContext $ctx) {
    $sub = new RbFormulaContext();
    $sub->rows = $ctx->rows;
    $sub->group = $ctx->group;
    $sub->groupKey = $ctx->groupKey;
    $sub->memo = &$ctx->memo;
    $out = [];
    foreach ($set as $i => $r) {
        $sub->row = $r;
        $sub->index = $i;
        $out[] = rbEvalNode($expr, $sub);
    }
    return $out;
}

function rbCall(array $node, RbFormulaContext $ctx) {
    [, $name, $args, $id] = $node;
    $argc = count($args);
    $arg = function ($i) use ($args, $ctx) { return isset($args[$i]) ? rbEvalNode($args[$i], $ctx) : null; };
    $num = function ($i, $default = 0.0) use ($args, $ctx) { return isset($args[$i]) ? rbNum(rbEvalNode($args[$i], $ctx)) : $default; };

    // Scalar forms of MIN / MAX / SUM / AVG when given two or more values
    if ($argc >= 2 && in_array($name, ['MIN', 'MAX', 'SUM', 'AVG'], true)) {
        $vals = [];
        for ($i = 0; $i < $argc; $i++) $vals[] = $num($i);
        if ($name === 'MIN') return min($vals);
        if ($name === 'MAX') return max($vals);
        if ($name === 'SUM') return array_sum($vals);
        return array_sum($vals) / count($vals);
    }

    if (in_array($name, RB_AGG_FUNCS, true)) {
        return rbAggregateCall($name, $args, $id, $ctx);
    }

    switch ($name) {
        case 'ROUND': return round($num(0), (int)$num(1));
        case 'ROUNDUP':
            $f = pow(10, (int)$num(1));
            $x = $num(0);
            return ($x < 0 ? -ceil(-$x * $f) : ceil($x * $f)) / $f;
        case 'ROUNDDOWN':
            $f = pow(10, (int)$num(1));
            $x = $num(0);
            return ($x < 0 ? -floor(-$x * $f) : floor($x * $f)) / $f;
        case 'FLOOR': return floor($num(0));
        case 'CEIL': return ceil($num(0));
        case 'INT': return (float)(int)$num(0);
        case 'ABS': return abs($num(0));
        case 'SIGN': return (float)($num(0) <=> 0);
        case 'SQRT': return $num(0) < 0 ? null : sqrt($num(0));
        case 'POWER':
            $r = pow($num(0), $num(1));
            return is_finite($r) ? $r : null;
        case 'MOD': return $num(1) == 0 ? null : fmod($num(0), $num(1));
        case 'PCT': return $num(1) == 0 ? null : $num(0) / $num(1) * 100;
        case 'INTEREST': return $num(0) * $num(1) / 100 * $num(2, 1);
        case 'COMPOUND': return $num(0) * pow(1 + $num(1) / 100, $num(2, 1)) - $num(0);
        case 'EMI':
            $p = $num(0);
            $r = $num(1) / 100;
            $n = max(1, $num(2, 1));
            return $r == 0 ? $p / $n : $p * $r * pow(1 + $r, $n) / (pow(1 + $r, $n) - 1);
        case 'IF':
            return rbTruthy($arg(0)) ? $arg(1) : $arg(2);
        case 'AND':
            foreach ($args as $a) if (!rbTruthy(rbEvalNode($a, $ctx))) return 0.0;
            return 1.0;
        case 'OR':
            foreach ($args as $a) if (rbTruthy(rbEvalNode($a, $ctx))) return 1.0;
            return 0.0;
        case 'NOT': return (float)!rbTruthy($arg(0));
        case 'IFBLANK':
            $v = $arg(0);
            return rbIsBlank($v) ? $arg(1) : $v;
        case 'ISBLANK':
            return (float)rbIsBlank($arg(0));
        case 'IN':
            $v = $arg(0);
            for ($i = 1; $i < $argc; $i++) {
                if (rbCompare($v, rbEvalNode($args[$i], $ctx)) === 0) return 1.0;
            }
            return 0.0;
        case 'BETWEEN':
            $v = $arg(0);
            if (rbIsBlank($v)) return 0.0;
            return (float)(rbCompare($v, $arg(1)) >= 0 && rbCompare($v, $arg(2)) <= 0);
        case 'STARTSWITH':
            $s = (string)$arg(1);
            return (float)($s === '' || mb_stripos((string)$arg(0), $s) === 0);
        case 'ENDSWITH':
            $text = (string)$arg(0);
            $s = (string)$arg(1);
            return (float)($s === '' || (mb_strlen($text) >= mb_strlen($s) && mb_strtolower(mb_substr($text, -mb_strlen($s))) === mb_strtolower($s)));
        case 'CONCAT':
            $s = '';
            foreach ($args as $a) $s .= (string)(rbEvalNode($a, $ctx) ?? '');
            return $s;
        case 'UPPER': return mb_strtoupper((string)$arg(0));
        case 'LOWER': return mb_strtolower((string)$arg(0));
        case 'LEFT': return mb_substr((string)$arg(0), 0, max(0, (int)$num(1, 1)));
        case 'RIGHT':
            $n = max(0, (int)$num(1, 1));
            return $n ? mb_substr((string)$arg(0), -$n) : '';
        case 'LEN': return (float)mb_strlen((string)$arg(0));
        case 'CONTAINS':
            $needle = (string)$arg(1);
            return (float)($needle !== '' && mb_stripos((string)$arg(0), $needle) !== false);
        case 'TODAY': return gmdate('Y-m-d', time() + 19800); // IST
        case 'DATEFROM': return RbFormulaContext::$env['date_from'] ?: '0001-01-01';
        case 'DATETO': return RbFormulaContext::$env['date_to'] ?: '9999-12-31';
        case 'DAYS':
            $a = rbDateTs($arg(0));
            $b = rbDateTs($arg(1));
            return ($a === null || $b === null) ? null : round(($a - $b) / 86400);
        case 'MONTHS':
            $a = rbDateTs($arg(0));
            $b = rbDateTs($arg(1));
            if ($a === null || $b === null) return null;
            $m = ((int)gmdate('Y', $a) - (int)gmdate('Y', $b)) * 12 + (int)gmdate('n', $a) - (int)gmdate('n', $b);
            if ($m > 0 && (int)gmdate('j', $a) < (int)gmdate('j', $b)) $m--;
            if ($m < 0 && (int)gmdate('j', $a) > (int)gmdate('j', $b)) $m++;
            return (float)$m;
        case 'YEAR':
        case 'MONTH':
        case 'DAY':
        case 'MONTHNAME':
            $ts = rbDateTs($arg(0));
            if ($ts === null) return null;
            return $name === 'MONTHNAME' ? gmdate('F', $ts) : (float)gmdate(['YEAR' => 'Y', 'MONTH' => 'n', 'DAY' => 'j'][$name], $ts);
        case 'ADDDAYS':
            $ts = rbDateTs($arg(0));
            return $ts === null ? null : gmdate('Y-m-d', $ts + (int)round($num(1)) * 86400);
        case 'ADDMONTHS':
            $ts = rbDateTs($arg(0));
            if ($ts === null) return null;
            $d = new DateTime(gmdate('Y-m-d', $ts), new DateTimeZone('UTC'));
            $day = (int)$d->format('j');
            $d->modify('first day of this month')->modify(((int)round($num(1)) >= 0 ? '+' : '') . (int)round($num(1)) . ' months');
            $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
            return $d->format('Y-m-d');
    }
    return null;
}

/**
 * Functions that look across rows. Results that don't depend on the current row are cached.
 */
function rbAggregateCall($name, array $args, $id, RbFormulaContext $ctx) {
    $expr = $args[0] ?? null;
    $isGroup = in_array($name, ['GSUM', 'GAVG', 'GCOUNT', 'GRUNNING'], true);
    $set = ($isGroup || $ctx->summary) ? ($ctx->group ?? $ctx->rows) : $ctx->rows;
    $scopeKey = $id . '|' . (($isGroup || $ctx->summary) && $ctx->group !== null ? 'g:' . $ctx->groupKey : 'all');

    // Arguments that use {module.Field}: run over that module's rows instead of the report's
    if (!array_key_exists('S' . $id, $ctx->memo)) {
        $ctx->memo['S' . $id] = rbNodeScope($args);
    }
    [$prefixes, $hasPlain] = $ctx->memo['S' . $id];
    if ($prefixes) {
        $foreign = RbFormulaContext::$env['foreign'][$prefixes[0]] ?? [];
        if ($hasPlain) {
            // e.g. SUMIF({transactions.Amount}, {transactions.MemberID} = {MemberID}): match against this row
            $outer = $ctx->summary ? [] : $ctx->row;
            $set = array_map(function ($r) use ($outer) { return $r + $outer; }, $foreign);
            $scopeKey = $id . '|F|' . ($ctx->summary ? 's' : $ctx->index) . '|' . $ctx->groupKey;
        } else {
            $set = $foreign;
            $scopeKey = $id . '|F';
        }
        if (in_array($name, ['RUNNING', 'GRUNNING', 'PREV', 'RANK', 'PCTTOTAL'], true)) {
            return null; // these follow the report's own rows
        }
    }

    // Per-row values of the first argument across the scope (cached)
    $values = function () use ($expr, $set, $ctx, $scopeKey) {
        if (!array_key_exists('v' . $scopeKey, $ctx->memo)) {
            $ctx->memo['v' . $scopeKey] = $expr ? rbEvalOver($expr, $set, $ctx) : array_fill(0, count($set), 1.0);
        }
        return $ctx->memo['v' . $scopeKey];
    };
    $conditionMask = function ($condNode) use ($set, $ctx, $scopeKey) {
        $key = 'c' . $scopeKey;
        if (!array_key_exists($key, $ctx->memo)) {
            $ctx->memo[$key] = array_map('rbTruthy', $condNode ? rbEvalOver($condNode, $set, $ctx) : []);
        }
        return $ctx->memo[$key];
    };
    $numbers = function (array $vals, ?array $mask = null) {
        $out = [];
        foreach ($vals as $i => $v) {
            if ($mask !== null && empty($mask[$i])) continue;
            if (!rbIsBlank($v)) $out[] = rbNum($v);
        }
        return $out;
    };

    switch ($name) {
        case 'SUM':
        case 'GSUM':
            return array_sum($numbers($values()));
        case 'AVG':
        case 'GAVG':
            $n = $numbers($values());
            return $n ? array_sum($n) / count($n) : null;
        case 'MIN':
            $n = $numbers($values());
            return $n ? min($n) : null;
        case 'MAX':
            $n = $numbers($values());
            return $n ? max($n) : null;
        case 'COUNT':
        case 'GCOUNT':
            if (!$expr) return (float)count($set);
            return (float)count(array_filter($values(), function ($v) { return !rbIsBlank($v); }));
        case 'SUMIF':
        case 'AVGIF':
        case 'MINIF':
        case 'MAXIF':
            $n = $numbers($values(), $conditionMask($args[1] ?? null));
            if ($name === 'SUMIF') return array_sum($n);
            if (!$n) return null;
            if ($name === 'MINIF') return min($n);
            if ($name === 'MAXIF') return max($n);
            return array_sum($n) / count($n);
        case 'COUNTIF':
            $key = 'c' . $scopeKey;
            if (!array_key_exists($key, $ctx->memo)) {
                $ctx->memo[$key] = array_map('rbTruthy', $expr ? rbEvalOver($expr, $set, $ctx) : []);
            }
            return (float)count(array_filter($ctx->memo[$key]));
        case 'PCTTOTAL':
            $total = array_sum($numbers($values()));
            if ($ctx->summary || $ctx->index === null) return $total == 0 ? null : 100.0;
            $own = rbNum(rbEvalNode($expr, $ctx));
            return $total == 0 ? null : $own / $total * 100;
        case 'RANK':
            if ($ctx->summary || $ctx->index === null) return null;
            $own = rbNum(rbEvalNode($expr, $ctx));
            $higher = 0;
            foreach ($numbers($values()) as $v) if ($v > $own) $higher++;
            return (float)($higher + 1);
        case 'RUNNING':
        case 'GRUNNING':
        case 'PREV':
            if ($ctx->summary || $ctx->index === null) {
                return $name === 'PREV' ? null : array_sum($numbers($values()));
            }
            $pos = $name === 'GRUNNING' ? $ctx->gindex : $ctx->index;
            $vals = $values();
            if ($name === 'PREV') return $pos > 0 ? ($vals[$pos - 1] ?? null) : null;
            $runKey = 'r' . $scopeKey;
            if (!isset($ctx->memo[$runKey])) {
                $acc = 0.0;
                $run = [];
                foreach ($vals as $v) { $acc += rbNum($v); $run[] = $acc; }
                $ctx->memo[$runKey] = $run;
            }
            return $ctx->memo[$runKey][$pos] ?? null;
    }
    return null;
}

/**
 * Evaluate a formula string. Returns the value, or null when the formula is invalid.
 */
function rbEvalFormula(string $formula, array $row, ?RbFormulaContext $ctx = null) {
    $parsed = rbParseFormula($formula);
    if ($parsed['error'] !== null) return null;
    if ($ctx === null) {
        $ctx = new RbFormulaContext();
        $ctx->rows = [$row];
        $ctx->index = 0;
        $ctx->gindex = 0;
    }
    $ctx->row = $row;
    try {
        return rbEvalNode($parsed['ast'], $ctx);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Field keys referenced by a formula
 */
function rbFormulaRefs(string $formula) {
    return rbParseFormula($formula)['refs'];
}
