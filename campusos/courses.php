<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'add_course') {
            $code = strtoupper(trim((string)$_POST['code'])); $name = trim((string)$_POST['name']);
            if ($code === '' || $name === '') throw new RuntimeException('Enter a course code and name.');
            q('INSERT INTO courses (code,name) VALUES (?,?)', [$code, $name]);
            flash('Course added.');
        } elseif ($act === 'delete_course') {
            q('DELETE FROM courses WHERE id = ?', [(int)$_POST['id']]);
            flash('Course deleted along with its subjects and student records.');
        } elseif ($act === 'add_subject') {
            $code = strtoupper(trim((string)$_POST['code'])); $name = trim((string)$_POST['name']);
            $max = (int)$_POST['max_marks'];
            if ($code === '' || $name === '' || $max < 1) throw new RuntimeException('Enter a code, name and positive max marks.');
            q('INSERT INTO subjects (course_id,code,name,teacher_id,max_marks) VALUES (?,?,?,?,?)',
              [(int)$_POST['course_id'], $code, $name, ((int)$_POST['teacher_id']) ?: null, $max]);
            flash('Subject added.');
        } elseif ($act === 'assign') {
            q('UPDATE subjects SET teacher_id = ? WHERE id = ?', [((int)$_POST['teacher_id']) ?: null, (int)$_POST['id']]);
            flash('Teacher updated.');
        } elseif ($act === 'delete_subject') {
            q('DELETE FROM subjects WHERE id = ?', [(int)$_POST['id']]);
            flash('Subject deleted.');
        }
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'That code already exists (or the teacher/course is invalid).' : 'Database error.', 'error');
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect('courses.php');
}

$courses = q('SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.course_id = c.id) AS students FROM courses c ORDER BY c.code')->fetchAll();
$teachers = q("SELECT id, name FROM users WHERE role='teacher' ORDER BY name")->fetchAll();
$subjects = q('SELECT * FROM subjects ORDER BY name')->fetchAll();
$by = [];
foreach ($subjects as $s) $by[$s['course_id']][] = $s;

function teacher_options(array $teachers, $sel = null): string {
    $h = '<option value="">Unassigned</option>';
    foreach ($teachers as $t) $h .= '<option value="' . (int)$t['id'] . '"' . ((int)$sel === (int)$t['id'] ? ' selected' : '') . '>' . e($t['name']) . '</option>';
    return $h;
}

page_header('Courses & Subjects');
?>
<div class="grid">
  <div class="card">
    <h2>Add course</h2>
    <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add_course">
      <label>Code <input name="code" required placeholder="BSC-IT"></label>
      <label>Name <input name="name" required placeholder="B.Sc. IT, Sem 1"></label>
      <div><button class="btn">Add course</button></div>
    </form>
  </div>
  <div class="card">
    <h2>Add subject</h2>
    <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add_subject">
      <label>Course <select name="course_id" required><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['code']) ?></option><?php endforeach; ?></select></label>
      <label>Code <input name="code" required></label>
      <label>Name <input name="name" required></label>
      <label>Teacher <select name="teacher_id"><?= teacher_options($teachers) ?></select></label>
      <label>Max marks <input type="number" name="max_marks" value="100" min="1" required></label>
      <div><button class="btn">Add subject</button></div>
    </form>
  </div>
</div>

<?php foreach ($courses as $c): ?>
<div class="card">
  <div class="toolbar" style="justify-content:space-between">
    <div><h2 style="margin:0"><?= e($c['code']) ?> <small><?= e($c['name']) ?></small></h2><small><?= (int)$c['students'] ?> students</small></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_course"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button class="btn small danger" data-confirm="Delete <?= e($c['code']) ?> and ALL its subjects, students, attendance and marks?">Delete course</button></form>
  </div>
  <?php if (empty($by[$c['id']])): ?><div class="empty">No subjects yet.</div><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Code</th><th>Subject</th><th class="num">Max</th><th>Teacher</th><th></th></tr>
    <?php foreach ($by[$c['id']] as $s): ?>
    <tr><td><?= e($s['code']) ?></td><td><?= e($s['name']) ?></td><td class="num"><?= (int)$s['max_marks'] ?></td>
      <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <select name="teacher_id"><?= teacher_options($teachers, $s['teacher_id']) ?></select><button class="btn small ghost" style="color:var(--brand)">Save</button></form></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_subject"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <button class="btn small danger" data-confirm="Delete subject <?= e($s['name']) ?> and its attendance and marks?">Delete</button></form></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php page_footer();
