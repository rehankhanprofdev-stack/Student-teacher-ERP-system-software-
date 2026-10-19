<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}

$GLOBALS['cfg'] = require __DIR__ . '/config.php';
function cfg(string $k) { return $GLOBALS['cfg'][$k]; }

// ---- URL base (works in a subfolder or at the web root) ----
$__docroot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$__root    = str_replace('\\', '/', dirname(__DIR__));
define('BASE', ($__docroot !== '' && str_starts_with($__root, $__docroot)) ? substr($__root, strlen($__docroot)) : '');
function url(string $p = ''): string { return BASE . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }

// ---- Database ----
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', cfg('db_host'), cfg('db_port'), cfg('db_name'));
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ---- Output, flash, CSRF ----
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'][] = [$type, $msg]; }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

// ---- Auth ----
function user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (isset($_SESSION['uid'])) {
            $u = q('SELECT id, name, email, role FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
        }
    }
    return $u;
}
function require_login(array $roles = []): array {
    $u = user();
    if (!$u) redirect('index.php');
    if ($roles && !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        page_header('Access denied');
        echo '<div class="card"><h2>Access denied</h2><p>Your role cannot open this page.</p></div>';
        page_footer();
        exit;
    }
    csrf_check();
    return $u;
}

// ---- Domain helpers ----
function exams(): array { return ['Internal 1', 'Internal 2', 'Midterm', 'Practical', 'Final']; }

function pct(float|int|string $obtained, float|int|string $max): float {
    return (float)$max > 0 ? round((float)$obtained / (float)$max * 100, 1) : 0.0;
}
function grade(float $p): string {
    return match (true) {
        $p >= 90 => 'A+', $p >= 80 => 'A', $p >= 70 => 'B', $p >= 60 => 'C',
        $p >= 50 => 'D', $p >= cfg('pass_mark') => 'E', default => 'F',
    };
}
function fmt($n): string { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }

function valid_date(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function student_row(int $userId): ?array {
    return q('SELECT s.*, c.name AS course_name, c.code AS course_code, u.name, u.email
              FROM students s JOIN courses c ON c.id = s.course_id JOIN users u ON u.id = s.user_id
              WHERE s.user_id = ?', [$userId])->fetch() ?: null;
}
function student_by_id(int $id): ?array {
    return q('SELECT s.*, c.name AS course_name, c.code AS course_code, u.name, u.email
              FROM students s JOIN courses c ON c.id = s.course_id JOIN users u ON u.id = s.user_id
              WHERE s.id = ?', [$id])->fetch() ?: null;
}

/** Subjects a staff member may manage: everything for admin, own subjects for teachers. */
function accessible_subjects(array $u): array {
    $sql = 'SELECT s.*, c.name AS course_name, c.code AS course_code
            FROM subjects s JOIN courses c ON c.id = s.course_id';
    $p = [];
    if ($u['role'] === 'teacher') { $sql .= ' WHERE s.teacher_id = ?'; $p[] = $u['id']; }
    return q($sql . ' ORDER BY c.name, s.name', $p)->fetchAll();
}
function subject_ok(array $u, int $sid): ?array {
    foreach (accessible_subjects($u) as $s) if ((int)$s['id'] === $sid) return $s;
    return null;
}
/** Students a staff member may look at (admin: all, teacher: students in their courses). */
function staff_students(array $u): array {
    $sql = 'SELECT s.id, s.roll_no, s.course_id, un.name, c.code AS course_code
            FROM students s JOIN users un ON un.id = s.user_id JOIN courses c ON c.id = s.course_id';
    $p = [];
    if ($u['role'] === 'teacher') {
        $sql .= ' WHERE s.course_id IN (SELECT course_id FROM subjects WHERE teacher_id = ?)';
        $p[] = $u['id'];
    }
    return q($sql . ' ORDER BY c.code, s.roll_no', $p)->fetchAll();
}

/** Per-subject attendance for one student. */
function student_attendance(int $sid): array {
    return q("SELECT sb.id, sb.code, sb.name, COUNT(a.id) AS total,
                     COALESCE(SUM(a.status IN ('present','late')), 0) AS attended
              FROM subjects sb
              LEFT JOIN attendance a ON a.subject_id = sb.id AND a.student_id = ?
              WHERE sb.course_id = (SELECT course_id FROM students WHERE id = ?)
              GROUP BY sb.id, sb.code, sb.name ORDER BY sb.name", [$sid, $sid])->fetchAll();
}
/** Per-subject marks totals (all exams combined) for one student. */
function student_results(int $sid): array {
    return q("SELECT sb.id, sb.code, sb.name, COALESCE(SUM(m.obtained), 0) AS obtained,
                     COALESCE(SUM(m.max_marks), 0) AS max_marks, COUNT(m.id) AS exams
              FROM subjects sb
              LEFT JOIN marks m ON m.subject_id = sb.id AND m.student_id = ?
              WHERE sb.course_id = (SELECT course_id FROM students WHERE id = ?)
              GROUP BY sb.id, sb.code, sb.name ORDER BY sb.name", [$sid, $sid])->fetchAll();
}
function attendance_totals(array $rows): array {
    $t = 0; $a = 0;
    foreach ($rows as $r) { $t += (int)$r['total']; $a += (int)$r['attended']; }
    return ['total' => $t, 'attended' => $a, 'pct' => pct($a, $t)];
}

function visible_notices(array $u, int $limit = 0): array {
    $sql = 'SELECT n.*, un.name AS author, c.code AS course_code
            FROM notices n LEFT JOIN users un ON un.id = n.created_by LEFT JOIN courses c ON c.id = n.course_id
            WHERE 1 = 1';
    $p = [];
    if ($u['role'] === 'student') {
        $st = student_row((int)$u['id']);
        $sql .= " AND n.audience IN ('all','students') AND (n.course_id IS NULL OR n.course_id = ?)";
        $p[] = $st['course_id'] ?? 0;
    } elseif ($u['role'] === 'teacher') {
        $sql .= " AND n.audience IN ('all','teachers')";
    }
    $sql .= ' ORDER BY n.created_at DESC, n.id DESC';
    if ($limit > 0) $sql .= ' LIMIT ' . $limit;
    return q($sql, $p)->fetchAll();
}

/** Progress bar; turns red below $warn percent. */
function bar(float $p, ?float $warn = null): string {
    $warn ??= (float)cfg('min_att');
    $cls = $p < $warn ? 'bad' : 'ok';
    return '<div class="bar ' . $cls . '"><span style="width:' . min(100, max(0, $p)) . '%"></span></div>';
}

require_once __DIR__ . '/layout.php';
