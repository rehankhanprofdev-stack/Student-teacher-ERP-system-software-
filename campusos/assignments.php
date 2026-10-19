<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$isStaff = $u['role'] !== 'student';
$st = $isStaff ? null : student_row((int)$u['id']);

/** Can this staff member manage the assignment? Returns the row or null. */
function own_assignment(array $u, int $id): ?array {
    $a = q('SELECT a.*, sb.name AS subject, sb.course_id FROM assignments a JOIN subjects sb ON sb.id = a.subject_id WHERE a.id = ?', [$id])->fetch();
    if (!$a) return null;
    return subject_ok($u, (int)$a['subject_id']) ? $a : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    try {
        if ($isStaff && $act === 'create') {
            $sub = subject_ok($u, (int)$_POST['subject_id']);
            $title = trim((string)$_POST['title']); $desc = trim((string)$_POST['description']);
            $due = (string)$_POST['due_date']; $pts = (float)$_POST['max_points'];
            if (!$sub) throw new RuntimeException('Pick one of your subjects.');
            if ($title === '' || $desc === '' || !valid_date($due) || $pts <= 0) throw new RuntimeException('Fill in the title, details, a valid due date and points.');
            q('INSERT INTO assignments (subject_id,title,description,due_date,max_points,created_by) VALUES (?,?,?,?,?,?)', [$sub['id'], $title, $desc, $due, $pts, $u['id']]);
            flash('Assignment posted.');
        } elseif ($isStaff && $act === 'delete') {
            if (!own_assignment($u, (int)$_POST['id'])) throw new RuntimeException('Not allowed.');
            q('DELETE FROM assignments WHERE id = ?', [(int)$_POST['id']]);
            flash('Assignment deleted.');
        } elseif ($isStaff && $act === 'grade') {
            $a = own_assignment($u, (int)$_POST['assignment_id']);
            if (!$a) throw new RuntimeException('Not allowed.');
            $raw = trim((string)$_POST['grade']);
            if ($raw === '' || !is_numeric($raw) || (float)$raw < 0 || (float)$raw > (float)$a['max_points']) throw new RuntimeException('Grade must be between 0 and ' . fmt($a['max_points']) . '.');
            q('UPDATE submissions SET grade = ?, feedback = ? WHERE id = ? AND assignment_id = ?',
              [(float)$raw, mb_substr(trim((string)$_POST['feedback']), 0, 255), (int)$_POST['submission_id'], $a['id']]);
            flash('Grade saved.');
            redirect('assignments.php?view=' . (int)$a['id']);
        } elseif (!$isStaff && $act === 'submit') {
            if (!$st) throw new RuntimeException('No student profile.');
            $a = q('SELECT a.id FROM assignments a JOIN subjects sb ON sb.id = a.subject_id WHERE a.id = ? AND sb.course_id = ?', [(int)$_POST['assignment_id'], $st['course_id']])->fetch();
            $content = trim((string)$_POST['content']);
            if (!$a) throw new RuntimeException('Assignment not found.');
            if ($content === '') throw new RuntimeException('Write your answer before submitting.');
            // Graded work is locked; otherwise resubmitting replaces the previous answer.
            $done = q('SELECT grade FROM submissions WHERE assignment_id = ? AND student_id = ?', [$a['id'], $st['id']])->fetch();
            if ($done && $done['grade'] !== null) throw new RuntimeException('This submission has already been graded.');
            q('INSERT INTO submissions (assignment_id,student_id,content) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE content = VALUES(content), submitted_at = CURRENT_TIMESTAMP', [$a['id'], $st['id'], $content]);
            flash('Submitted.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    } catch (PDOException $ex) {
        flash('Database error.', 'error');
    }
    redirect('assignments.php');
}

// ---------- Staff: submissions for one assignment ----------
if ($isStaff && isset($_GET['view'])) {
    $a = own_assignment($u, (int)$_GET['view']);
    page_header('Submissions');
    if (!$a) { echo '<div class="card"><p>Assignment not found.</p></div>'; page_footer(); exit; }
    $rows = q('SELECT s.id AS student_id, s.roll_no, un.name, sb.id AS sub_id, sb.content, sb.submitted_at, sb.grade, sb.feedback
               FROM students s JOIN users un ON un.id = s.user_id
               LEFT JOIN submissions sb ON sb.student_id = s.id AND sb.assignment_id = ?
               WHERE s.course_id = ? ORDER BY s.roll_no', [$a['id'], $a['course_id']])->fetchAll();
    echo '<p><a href="assignments.php">← All assignments</a></p><div class="card"><h2>' . e($a['title']) . ' <span class="badge">' . e($a['subject']) . '</span></h2>'
       . '<p class="muted">Due ' . e(date('j M Y', strtotime($a['due_date']))) . ' · out of ' . e(fmt($a['max_points'])) . '</p><p style="white-space:pre-wrap">' . e($a['description']) . '</p></div>';
    echo '<div class="card"><div class="table-wrap"><table><tr><th>Student</th><th>Submission</th><th>Grade</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e($r['roll_no']) . '<br>' . e($r['name']) . '</td><td style="max-width:380px">';
        if ($r['sub_id'] === null) echo '<span class="badge warn">Not submitted</span>';
        else {
            $late = date('Y-m-d', strtotime($r['submitted_at'])) > $a['due_date'];
            echo '<small>' . e(date('j M, H:i', strtotime($r['submitted_at']))) . '</small> ' . ($late ? '<span class="badge bad">Late</span>' : '')
               . '<div style="white-space:pre-wrap">' . e($r['content']) . '</div>';
        }
        echo '</td><td>';
        if ($r['sub_id'] !== null) {
            echo '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="action" value="grade"><input type="hidden" name="assignment_id" value="' . (int)$a['id'] . '">'
               . '<input type="hidden" name="submission_id" value="' . (int)$r['sub_id'] . '">'
               . '<input type="number" name="grade" step="0.5" min="0" max="' . e($a['max_points']) . '" value="' . ($r['grade'] !== null ? e(fmt($r['grade'])) : '') . '" placeholder="/ ' . e(fmt($a['max_points'])) . '">'
               . '<input name="feedback" placeholder="Feedback" value="' . e($r['feedback']) . '" maxlength="255" style="width:160px"><button class="btn small">Save</button></form>';
        } else echo '–';
        echo '</td></tr>';
    }
    echo '</table></div></div>';
    page_footer(); exit;
}

page_header('Assignments');

// ---------- Staff: list + create ----------
if ($isStaff) {
    $subjects = accessible_subjects($u);
    ?>
<div class="card"><h2>Post an assignment</h2>
<?php if (!$subjects): ?><div class="empty">No subjects are assigned to you yet.</div><?php else: ?>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label>Subject <select name="subject_id"><?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['course_code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?></select></label>
  <label>Title <input name="title" required maxlength="160"></label>
  <label>Due date <input type="date" name="due_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>" required></label>
  <label>Points <input type="number" name="max_points" value="10" min="1" step="0.5" required></label>
  <label style="grid-column:1/-1">Details <textarea name="description" required></textarea></label>
  <div><button class="btn">Post assignment</button></div>
</form><?php endif; ?></div>
<?php
    $ids = array_column($subjects, 'id');
    $list = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $list = q("SELECT a.*, sb.name AS subject, c.code AS course_code,
                          (SELECT COUNT(*) FROM students s WHERE s.course_id = sb.course_id) AS roster,
                          (SELECT COUNT(*) FROM submissions x WHERE x.assignment_id = a.id) AS submitted,
                          (SELECT COUNT(*) FROM submissions x WHERE x.assignment_id = a.id AND x.grade IS NULL) AS ungraded
                   FROM assignments a JOIN subjects sb ON sb.id = a.subject_id JOIN courses c ON c.id = sb.course_id
                   WHERE a.subject_id IN ($in) ORDER BY a.due_date DESC", $ids)->fetchAll();
    }
    echo '<div class="card"><h2>Posted assignments</h2>';
    if (!$list) echo '<div class="empty">Nothing posted yet.</div>';
    else {
        echo '<div class="table-wrap"><table><tr><th>Title</th><th>Subject</th><th>Due</th><th>Submitted</th><th></th></tr>';
        foreach ($list as $a) {
            echo '<tr><td><strong>' . e($a['title']) . '</strong></td><td>' . e($a['course_code'] . ' · ' . $a['subject']) . '</td><td>' . e(date('j M Y', strtotime($a['due_date']))) . '</td>'
               . '<td>' . (int)$a['submitted'] . ' / ' . (int)$a['roster'] . ($a['ungraded'] ? ' <span class="badge warn">' . (int)$a['ungraded'] . ' to grade</span>' : '') . '</td>'
               . '<td class="row"><a class="btn small" href="assignments.php?view=' . (int)$a['id'] . '">Open</a>'
               . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
               . '<button class="btn small danger" data-confirm="Delete this assignment and all submissions?">Delete</button></form></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
    page_footer(); exit;
}

// ---------- Student ----------
if (!$st) { echo '<div class="card"><p>No student profile is linked to your account.</p></div>'; page_footer(); exit; }
$list = q('SELECT a.*, sb.name AS subject, s.id AS sub_id, s.content, s.submitted_at, s.grade, s.feedback
           FROM assignments a JOIN subjects sb ON sb.id = a.subject_id
           LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
           WHERE sb.course_id = ? ORDER BY a.due_date DESC', [$st['id'], $st['course_id']])->fetchAll();
if (!$list) echo '<div class="card"><div class="empty">No assignments yet.</div></div>';
foreach ($list as $a) {
    $overdue = $a['due_date'] < date('Y-m-d');
    echo '<div class="card"><div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">' . e($a['title']) . ' <span class="badge">' . e($a['subject']) . '</span></h2><div>';
    if ($a['grade'] !== null) echo '<span class="badge ok">Graded ' . e(fmt($a['grade'])) . ' / ' . e(fmt($a['max_points'])) . '</span>';
    elseif ($a['sub_id']) echo '<span class="badge">Submitted</span>';
    elseif ($overdue) echo '<span class="badge bad">Overdue</span>';
    else echo '<span class="badge warn">Pending</span>';
    echo '</div></div><p class="muted">Due ' . e(date('D, j M Y', strtotime($a['due_date']))) . ' · ' . e(fmt($a['max_points'])) . ' points</p>'
       . '<p style="white-space:pre-wrap">' . e($a['description']) . '</p>';
    if ($a['feedback']) echo '<p><strong>Feedback:</strong> ' . e($a['feedback']) . '</p>';
    if ($a['grade'] !== null) { echo '<p class="muted">Your answer is locked because it has been graded.</p></div>'; continue; }
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="submit"><input type="hidden" name="assignment_id" value="' . (int)$a['id'] . '">'
       . '<label>' . ($a['sub_id'] ? 'Your answer (submitting again replaces it)' : 'Your answer') . '<textarea name="content" required>' . e($a['content'] ?? '') . '</textarea></label>'
       . '<p><button class="btn">' . ($a['sub_id'] ? 'Update submission' : 'Submit') . '</button></p></form></div>';
}
page_footer();
