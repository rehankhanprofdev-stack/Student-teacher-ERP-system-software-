<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

if ($u['role'] === 'student') {
    $st = student_row((int)$u['id']);
    $picker = [];
} else {
    $picker = staff_students($u);
    $want = (int)($_GET['student'] ?? 0);
    $st = null;
    foreach ($picker as $p) if ((int)$p['id'] === $want) { $st = student_by_id($want); break; }
}

page_header('Result Card');

if ($u['role'] !== 'student'): ?>
<div class="card no-print">
  <form method="get" class="toolbar">
    <label>Student <select name="student" onchange="this.form.submit()">
      <option value="">Select a student…</option>
      <?php foreach ($picker as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $st && $st['id'] == $p['id'] ? 'selected' : '' ?>><?= e($p['course_code'] . ' · ' . $p['roll_no'] . ' · ' . $p['name']) ?></option><?php endforeach; ?>
    </select></label>
    <noscript><button class="btn">Show</button></noscript>
  </form>
</div>
<?php endif;

if (!$st) {
    if ($u['role'] === 'student') echo '<div class="card"><p>No student profile is linked to your account.</p></div>';
    page_footer(); exit;
}

$res = student_results((int)$st['id']);
$att = attendance_totals(student_attendance((int)$st['id']));
$to = 0; $tm = 0; $failed = []; $graded = 0;
foreach ($res as $r) {
    if (!$r['exams']) continue;
    $graded++; $to += $r['obtained']; $tm += $r['max_marks'];
    if (pct($r['obtained'], $r['max_marks']) < cfg('pass_mark')) $failed[] = $r['name'];
}
$overall = pct($to, $tm);
$pending = count($res) - $graded;
$passed = $graded > 0 && !$failed;
?>
<div class="no-print" style="margin-bottom:12px"><button class="btn" data-print>Print result card</button></div>
<div class="card result-card">
  <div class="result-head">
    <h2><?= e(cfg('college')) ?></h2>
    <div class="muted">Statement of Marks · <?= e($st['course_name']) ?></div>
  </div>
  <div class="result-meta">
    <div><span class="muted">Name:</span> <strong><?= e($st['name']) ?></strong></div>
    <div><span class="muted">Roll no.:</span> <strong><?= e($st['roll_no']) ?></strong></div>
    <div><span class="muted">Course:</span> <?= e($st['course_code']) ?></div>
    <div><span class="muted">Issued:</span> <?= e(date('j F Y')) ?></div>
  </div>
  <div class="table-wrap"><table>
    <tr><th>Code</th><th>Subject</th><th class="num">Obtained</th><th class="num">Out of</th><th class="num">%</th><th>Grade</th><th>Result</th></tr>
    <?php foreach ($res as $r):
        if (!$r['exams']) { echo '<tr><td>' . e($r['code']) . '</td><td>' . e($r['name']) . '</td><td colspan="5"><small>Marks not yet recorded</small></td></tr>'; continue; }
        $p = pct($r['obtained'], $r['max_marks']); $ok = $p >= cfg('pass_mark'); ?>
    <tr><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td>
      <td class="num"><?= e(fmt($r['obtained'])) ?></td><td class="num"><?= e(fmt($r['max_marks'])) ?></td><td class="num"><?= e($p) ?>%</td>
      <td><strong><?= e(grade($p)) ?></strong></td><td><span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'Pass' : 'Fail' ?></span></td></tr>
    <?php endforeach; ?>
    <tr><th colspan="2">Total</th><th class="num"><?= e(fmt($to)) ?></th><th class="num"><?= e(fmt($tm)) ?></th><th class="num"><?= $tm > 0 ? e($overall) . '%' : '–' ?></th><th colspan="2"></th></tr>
  </table></div>
  <div class="result-sum">
    <div><span class="muted">Overall</span><br><strong style="font-size:1.4rem"><?= $tm > 0 ? e($overall) . '%' : '–' ?></strong></div>
    <div><span class="muted">Grade</span><br><strong style="font-size:1.4rem"><?= $tm > 0 ? e(grade($overall)) : '–' ?></strong></div>
    <div><span class="muted">Attendance</span><br><strong style="font-size:1.4rem"><?= $att['total'] ? e($att['pct']) . '%' : '–' ?></strong></div>
    <div><span class="muted">Final result</span><br>
      <?php if ($graded === 0): ?><strong>Not available</strong>
      <?php elseif ($pending): ?><strong>Provisional</strong> <small>(<?= (int)$pending ?> subject<?= $pending > 1 ? 's' : '' ?> pending)</small>
      <?php else: ?><span class="badge <?= $passed ? 'ok' : 'bad' ?>" style="font-size:1rem"><?= $passed ? 'PASS' : 'FAIL' ?></span><?php endif; ?></div>
  </div>
  <?php if ($failed): ?><p class="muted">Below the pass mark of <?= (int)cfg('pass_mark') ?>% in: <?= e(implode(', ', $failed)) ?>.</p><?php endif; ?>
  <p class="muted">Grades: A+ ≥90 · A ≥80 · B ≥70 · C ≥60 · D ≥50 · E ≥<?= (int)cfg('pass_mark') ?> · F below. Marks combine all recorded exams per subject.</p>
  <div class="sign"><div>Class teacher</div><div>Controller of Examinations</div><div>Principal</div></div>
</div>
<?php page_footer();
