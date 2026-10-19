<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login(['admin', 'teacher']);

$subjectsAll = accessible_subjects($u);
$courseMap = [];
foreach ($subjectsAll as $s) $courseMap[$s['course_id']] = $s['course_code'];
$type = in_array($_GET['type'] ?? '', ['attendance', 'marks', 'defaulters'], true) ? $_GET['type'] : 'attendance';
$courseId = (int)($_GET['course'] ?? array_key_first($courseMap) ?? 0);
if (!isset($courseMap[$courseId])) $courseId = (int)(array_key_first($courseMap) ?? 0);
$exam = (string)($_GET['exam'] ?? 'all');
if ($exam !== 'all' && !in_array($exam, exams(), true)) $exam = 'all';
$limit = max(1, min(100, (int)($_GET['limit'] ?? cfg('min_att'))));

$subs = array_values(array_filter($subjectsAll, fn($s) => (int)$s['course_id'] === $courseId));
$students = $courseId ? q('SELECT s.id, s.roll_no, un.name FROM students s JOIN users un ON un.id = s.user_id WHERE s.course_id = ? ORDER BY s.roll_no', [$courseId])->fetchAll() : [];
$title = ''; $head = []; $rows = [];

if ($subs && $students) {
    $subIds = array_column($subs, 'id');
    $in = implode(',', array_fill(0, count($subIds), '?'));

    if ($type === 'attendance' || $type === 'defaulters') {
        $map = [];
        foreach (q("SELECT student_id, subject_id, COUNT(*) t, SUM(status IN ('present','late')) a FROM attendance
                    WHERE subject_id IN ($in) GROUP BY student_id, subject_id", $subIds)->fetchAll() as $r) $map[$r['student_id']][$r['subject_id']] = $r;
        if ($type === 'attendance') {
            $title = 'Attendance report · ' . $courseMap[$courseId];
            $head = array_merge(['Roll', 'Student'], array_map(fn($s) => $s['code'] . ' %', $subs), ['Overall %']);
            foreach ($students as $st) {
                $line = [$st['roll_no'], $st['name']]; $T = 0; $A = 0;
                foreach ($subs as $s) {
                    $c = $map[$st['id']][$s['id']] ?? null;
                    $line[] = $c ? pct($c['a'], $c['t']) : '–';
                    if ($c) { $T += $c['t']; $A += $c['a']; }
                }
                $line[] = $T ? pct($A, $T) : '–';
                $rows[] = $line;
            }
        } else {
            $title = "Attendance defaulters (below {$limit}%) · " . $courseMap[$courseId];
            $head = ['Roll', 'Student', 'Attended', 'Total classes', 'Attendance %'];
            foreach ($students as $st) {
                $T = 0; $A = 0;
                foreach ($subs as $s) if ($c = $map[$st['id']][$s['id']] ?? null) { $T += $c['t']; $A += $c['a']; }
                if ($T > 0 && pct($A, $T) < $limit) $rows[] = [$st['roll_no'], $st['name'], (int)$A, $T, pct($A, $T)];
            }
            usort($rows, fn($a, $b) => $a[4] <=> $b[4]);
        }
    } else {
        $sql = "SELECT student_id, subject_id, SUM(obtained) o, SUM(max_marks) m FROM marks WHERE subject_id IN ($in)";
        $p = $subIds;
        if ($exam !== 'all') { $sql .= ' AND exam = ?'; $p[] = $exam; }
        $map = [];
        foreach (q($sql . ' GROUP BY student_id, subject_id', $p)->fetchAll() as $r) $map[$r['student_id']][$r['subject_id']] = $r;
        $title = 'Marks sheet · ' . $courseMap[$courseId] . ' · ' . ($exam === 'all' ? 'all exams combined' : $exam);
        $head = array_merge(['Roll', 'Student'], array_map(fn($s) => $s['code'] . ' %', $subs), ['Overall %', 'Grade']);
        foreach ($students as $st) {
            $line = [$st['roll_no'], $st['name']]; $O = 0; $M = 0;
            foreach ($subs as $s) {
                $c = $map[$st['id']][$s['id']] ?? null;
                $line[] = $c ? pct($c['o'], $c['m']) : '–';
                if ($c) { $O += $c['o']; $M += $c['m']; }
            }
            $line[] = $M ? pct($O, $M) : '–';
            $line[] = $M ? grade(pct($O, $M)) : '–';
            $rows[] = $line;
        }
    }
}

// CSV export
if (isset($_GET['csv']) && $head) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $title) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $head);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));
    exit;
}

page_header('Reports');
if (!$courseMap) { echo '<div class="card"><div class="empty">No subjects available yet.</div></div>'; page_footer(); exit; }
?>
<div class="card no-print">
  <form method="get" class="toolbar">
    <label>Report <select name="type" onchange="this.form.submit()">
      <option value="attendance" <?= $type === 'attendance' ? 'selected' : '' ?>>Attendance summary</option>
      <option value="marks" <?= $type === 'marks' ? 'selected' : '' ?>>Marks sheet</option>
      <option value="defaulters" <?= $type === 'defaulters' ? 'selected' : '' ?>>Attendance defaulters</option>
    </select></label>
    <label>Course <select name="course" onchange="this.form.submit()">
      <?php foreach ($courseMap as $id => $code): ?><option value="<?= (int)$id ?>" <?= $id == $courseId ? 'selected' : '' ?>><?= e($code) ?></option><?php endforeach; ?>
    </select></label>
    <?php if ($type === 'marks'): ?>
    <label>Exam <select name="exam" onchange="this.form.submit()"><option value="all">All exams combined</option>
      <?php foreach (exams() as $x): ?><option <?= $x === $exam ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <?php elseif ($type === 'defaulters'): ?>
    <label>Below % <input type="number" name="limit" value="<?= (int)$limit ?>" min="1" max="100"></label>
    <button class="btn">Apply</button>
    <?php endif; ?>
    <?php if ($head): ?>
    <button class="btn ghost" style="color:var(--brand)" data-print>Print</button>
    <a class="btn ghost" style="color:var(--brand)" href="?<?= e(http_build_query(array_merge($_GET, ['type' => $type, 'course' => $courseId, 'csv' => 1]))) ?>">Download CSV</a>
    <?php endif; ?>
  </form>
</div>
<div class="card">
  <h2><?= e($title ?: 'Report') ?></h2>
  <p class="muted print-only"><?= e(cfg('college')) ?> · generated <?= e(date('j M Y, H:i')) ?></p>
  <?php if (!$head): ?><div class="empty">No data for this course yet.</div>
  <?php elseif (!$rows): ?><div class="empty">Nobody matches this report. 🎉</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><?php foreach ($head as $i => $h): ?><th class="<?= $i >= 2 ? 'num' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr>
    <?php foreach ($rows as $r): ?>
    <tr><?php foreach ($r as $i => $v):
        $isPct = $i >= 2 && is_float($v) || ($i >= 2 && is_numeric($v) && str_contains((string)$head[$i], '%'));
        $bad = $isPct && is_numeric($v) && $v < ($type === 'marks' ? cfg('pass_mark') : ($type === 'defaulters' ? 101 : cfg('min_att')));
    ?><td class="<?= $i >= 2 ? 'num' : '' ?>" <?= $bad ? 'style="color:var(--bad);font-weight:650"' : '' ?>><?= e($v) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted"><?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?>. Values in red are below the threshold (attendance <?= (int)cfg('min_att') ?>%, marks <?= (int)cfg('pass_mark') ?>%).</p>
  <?php endif; ?>
</div>
<?php page_footer();
