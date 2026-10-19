<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

// ---------- Student view ----------
if ($u['role'] === 'student') {
    $st = student_row((int)$u['id']);
    page_header('My Marks');
    if (!$st) { echo '<div class="card"><p>No student profile is linked to your account.</p></div>'; page_footer(); exit; }
    $res = student_results((int)$st['id']);
    $marks = q('SELECT subject_id, exam, obtained, max_marks FROM marks WHERE student_id = ?', [$st['id']])->fetchAll();
    $byS = [];
    foreach ($marks as $m) $byS[$m['subject_id']][] = $m;
    foreach ($res as $r) {
        $p = pct($r['obtained'], $r['max_marks']);
        echo '<div class="card"><div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">' . e($r['name']) . ' <small>' . e($r['code']) . '</small></h2>';
        if ($r['exams']) echo '<div><strong>' . e(fmt($r['obtained'])) . ' / ' . e(fmt($r['max_marks'])) . '</strong> · ' . e($p) . '% <span class="badge ' . ($p < cfg('pass_mark') ? 'bad' : 'ok') . '">' . e(grade($p)) . '</span></div>';
        echo '</div>';
        if (empty($byS[$r['id']])) { echo '<div class="empty">No marks recorded yet.</div></div>'; continue; }
        echo '<div class="table-wrap"><table><tr><th>Exam</th><th class="num">Obtained</th><th class="num">Out of</th><th class="num">%</th></tr>';
        foreach ($byS[$r['id']] as $m) {
            echo '<tr><td>' . e($m['exam']) . '</td><td class="num">' . e(fmt($m['obtained'])) . '</td><td class="num">' . e(fmt($m['max_marks']))
               . '</td><td class="num">' . e(pct($m['obtained'], $m['max_marks'])) . '%</td></tr>';
        }
        echo '</table></div></div>';
    }
    echo '<p><a class="btn" href="' . e(url('result.php')) . '">Open result card</a></p>';
    page_footer(); exit;
}

// ---------- Staff view ----------
$subjects = accessible_subjects($u);
$sid = (int)($_REQUEST['subject'] ?? ($subjects[0]['id'] ?? 0));
$subject = $sid ? subject_ok($u, $sid) : null;
$exam = (string)($_REQUEST['exam'] ?? 'Internal 1');
if (!in_array($exam, exams(), true)) $exam = exams()[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $subject) {
    $max = (float)($_POST['max'] ?? 0);
    $errors = []; $toSave = [];
    if ($max <= 0 || $max > 1000) $errors[] = 'Enter a maximum mark between 1 and 1000.';
    $ids = q('SELECT s.id, s.roll_no FROM students s WHERE s.course_id = ?', [$subject['course_id']])->fetchAll();
    foreach ($ids as $s) {
        $raw = trim((string)($_POST['obtained'][$s['id']] ?? ''));
        if ($raw === '') continue;
        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > $max) { $errors[] = "{$s['roll_no']}: marks must be between 0 and " . fmt($max) . '.'; continue; }
        $toSave[] = [$s['id'], (float)$raw];
    }
    if ($errors) {
        flash('Nothing was saved. ' . implode(' ', array_slice($errors, 0, 3)), 'error');
    } else {
        $stmt = db()->prepare('INSERT INTO marks (student_id,subject_id,exam,obtained,max_marks) VALUES (?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE obtained = VALUES(obtained), max_marks = VALUES(max_marks)');
        db()->beginTransaction();
        foreach ($toSave as [$stuId, $val]) $stmt->execute([$stuId, $sid, $exam, $val, $max]);
        db()->commit();
        flash('Saved marks for ' . count($toSave) . " students ($exam).");
    }
    redirect('marks.php?subject=' . $sid . '&exam=' . urlencode($exam));
}

page_header('Marks');
if (!$subjects) { echo '<div class="card"><div class="empty">No subjects are assigned to you yet.</div></div>'; page_footer(); exit; }
?>
<div class="card">
  <form method="get" class="toolbar">
    <label>Subject <select name="subject" onchange="this.form.submit()">
      <?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $s['id'] == $sid ? 'selected' : '' ?>><?= e($s['course_code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Exam <select name="exam" onchange="this.form.submit()">
      <?php foreach (exams() as $x): ?><option <?= $x === $exam ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?>
    </select></label>
    <noscript><button class="btn">Load</button></noscript>
  </form>
<?php if ($subject):
    $stu = q('SELECT s.id, s.roll_no, un.name, m.obtained, m.max_marks AS mmax
              FROM students s JOIN users un ON un.id = s.user_id
              LEFT JOIN marks m ON m.student_id = s.id AND m.subject_id = ? AND m.exam = ?
              WHERE s.course_id = ? ORDER BY s.roll_no', [$sid, $exam, $subject['course_id']])->fetchAll();
    $existingMax = null;
    foreach ($stu as $r) if ($r['mmax'] !== null) { $existingMax = $r['mmax']; break; }
    $defMax = $existingMax ?? ($exam === 'Internal 1' || $exam === 'Internal 2' ? 20 : ($exam === 'Midterm' ? 50 : $subject['max_marks']));
    if (!$stu): ?><div class="empty">No students enrolled in this course.</div><?php else: ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="subject" value="<?= $sid ?>"><input type="hidden" name="exam" value="<?= e($exam) ?>">
    <div class="toolbar"><label>Maximum marks for this exam <input type="number" name="max" value="<?= e(fmt($defMax)) ?>" min="1" max="1000" step="0.5" required></label>
      <small>Leave a box empty to skip that student. Percentages update automatically.</small></div>
    <div class="table-wrap"><table>
      <tr><th>Roll</th><th>Student</th><th>Marks obtained</th><th class="num">Current %</th></tr>
      <?php foreach ($stu as $r): ?>
      <tr><td><?= e($r['roll_no']) ?></td><td><?= e($r['name']) ?></td>
        <td><input type="number" name="obtained[<?= (int)$r['id'] ?>]" value="<?= $r['obtained'] !== null ? e(fmt($r['obtained'])) : '' ?>" min="0" step="0.5"></td>
        <td class="num"><?= $r['obtained'] !== null ? e(pct($r['obtained'], $r['mmax'])) . '% <span class="badge ' . (pct($r['obtained'], $r['mmax']) < cfg('pass_mark') ? 'bad' : 'ok') . '">' . e(grade(pct($r['obtained'], $r['mmax']))) . '</span>' : '–' ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <p><button class="btn">Save marks</button></p>
  </form>
  <?php endif; endif; ?>
</div>
<?php page_footer();
