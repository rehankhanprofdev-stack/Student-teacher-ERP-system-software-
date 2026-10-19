<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

// ---------- Student view ----------
if ($u['role'] === 'student') {
    $st = student_row((int)$u['id']);
    page_header('My Attendance');
    if (!$st) { echo '<div class="card"><p>No student profile is linked to your account.</p></div>'; page_footer(); exit; }
    $rows = student_attendance((int)$st['id']);
    $tot = attendance_totals($rows);
    echo '<div class="stats"><div class="stat ' . ($tot['total'] && $tot['pct'] < cfg('min_att') ? 'warn' : 'good') . '"><div class="stat-v">'
       . ($tot['total'] ? e($tot['pct']) . '%' : '–') . '</div><div class="stat-l">Overall attendance</div><div class="stat-h">'
       . (int)$tot['attended'] . ' of ' . (int)$tot['total'] . ' classes</div></div></div>';
    echo '<div class="card"><div class="table-wrap"><table><tr><th>Subject</th><th class="num">Attended</th><th class="num">Total</th><th class="num">%</th><th style="width:30%">Progress</th><th>Status</th></tr>';
    foreach ($rows as $r) {
        $p = pct($r['attended'], $r['total']);
        // Classes you could still miss, or must attend, to stay at the minimum.
        $min = (int)cfg('min_att');
        $note = '';
        if ($r['total'] > 0) {
            if ($p >= $min) { $skip = (int)floor($r['attended'] * 100 / $min - $r['total']); $note = $skip > 0 ? "Can miss $skip more" : 'On track'; }
            else { $need = (int)ceil(($min * $r['total'] - 100 * $r['attended']) / (100 - $min)); $note = "Attend next $need"; }
        }
        echo '<tr><td>' . e($r['name']) . '</td><td class="num">' . (int)$r['attended'] . '</td><td class="num">' . (int)$r['total'] . '</td>'
           . '<td class="num">' . ($r['total'] ? e($p) . '%' : '–') . '</td><td>' . ($r['total'] ? bar($p) : '') . '</td>'
           . '<td>' . ($r['total'] ? '<span class="badge ' . ($p < $min ? 'bad' : 'ok') . '">' . e($note) . '</span>' : '<small>no classes yet</small>') . '</td></tr>';
    }
    echo '</table></div><p class="muted">Late arrivals count as present. Minimum required: ' . (int)cfg('min_att') . '%.</p></div>';
    page_footer(); exit;
}

// ---------- Staff view ----------
$subjects = accessible_subjects($u);
$sid = (int)($_REQUEST['subject'] ?? ($subjects[0]['id'] ?? 0));
$subject = $sid ? subject_ok($u, $sid) : null;
$date = (string)($_REQUEST['date'] ?? date('Y-m-d'));
if (!valid_date($date) || $date > date('Y-m-d')) $date = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $subject) {
    $students = q('SELECT id FROM students WHERE course_id = ?', [$subject['course_id']])->fetchAll(PDO::FETCH_COLUMN);
    $stmt = db()->prepare('INSERT INTO attendance (student_id,subject_id,att_date,status) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE status = VALUES(status)');
    db()->beginTransaction();
    $n = 0;
    foreach ($students as $stuId) {
        $status = $_POST['status'][$stuId] ?? '';
        if (in_array($status, ['present', 'absent', 'late'], true)) { $stmt->execute([$stuId, $sid, $date, $status]); $n++; }
    }
    db()->commit();
    flash("Attendance saved for $n students on " . date('j M Y', strtotime($date)) . '.');
    redirect('attendance.php?subject=' . $sid . '&date=' . $date);
}

page_header('Attendance');
if (!$subjects) { echo '<div class="card"><div class="empty">No subjects are assigned to you yet.</div></div>'; page_footer(); exit; }
?>
<div class="card">
  <form method="get" class="toolbar">
    <label>Subject <select name="subject" onchange="this.form.submit()">
      <?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $s['id'] == $sid ? 'selected' : '' ?>><?= e($s['course_code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Date <input type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" onchange="this.form.submit()"></label>
    <noscript><button class="btn">Load</button></noscript>
  </form>
<?php if ($subject):
    $stu = q("SELECT s.id, s.roll_no, un.name,
                     (SELECT status FROM attendance a WHERE a.student_id = s.id AND a.subject_id = ? AND a.att_date = ?) AS today,
                     (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.subject_id = ?) AS total,
                     (SELECT COALESCE(SUM(a.status IN ('present','late')),0) FROM attendance a WHERE a.student_id = s.id AND a.subject_id = ?) AS attended
              FROM students s JOIN users un ON un.id = s.user_id WHERE s.course_id = ? ORDER BY s.roll_no",
              [$sid, $date, $sid, $sid, $subject['course_id']])->fetchAll();
    if (!$stu): ?><div class="empty">No students enrolled in this course.</div><?php else: ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="subject" value="<?= $sid ?>"><input type="hidden" name="date" value="<?= e($date) ?>">
    <div class="toolbar no-print">
      <button class="btn ghost small" style="color:var(--ok)" data-mark-all="present">All present</button>
      <button class="btn ghost small" style="color:var(--bad)" data-mark-all="absent">All absent</button>
    </div>
    <div class="table-wrap"><table>
      <tr><th>Roll</th><th>Student</th><th>Overall in subject</th><th><?= e(date('D j M', strtotime($date))) ?></th></tr>
      <?php foreach ($stu as $r): $p = pct($r['attended'], $r['total']); $cur = $r['today'] ?: 'present'; ?>
      <tr><td><?= e($r['roll_no']) ?></td><td><?= e($r['name']) ?></td>
        <td style="width:26%"><?= $r['total'] ? e($p) . '%' . bar($p) : '<small>no data</small>' ?></td>
        <td><div class="seg">
          <?php foreach (['present' => ['P', 'p'], 'late' => ['L', 'l'], 'absent' => ['A', 'a']] as $val => [$lbl, $c]): ?>
          <label><input type="radio" name="status[<?= (int)$r['id'] ?>]" value="<?= $val ?>" <?= $cur === $val ? 'checked' : '' ?>><span class="<?= $c ?>" title="<?= ucfirst($val) ?>"><?= $lbl ?></span></label>
          <?php endforeach; ?>
        </div><?= $r['today'] ? '' : ' <small>not saved yet</small>' ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <p><button class="btn">Save attendance</button></p>
  </form>
  <?php endif; endif; ?>
</div>
<?php page_footer();
