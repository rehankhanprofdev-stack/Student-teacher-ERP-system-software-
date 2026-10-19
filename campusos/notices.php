<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$isStaff = $u['role'] !== 'student';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStaff) {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'post') {
            $title = trim((string)$_POST['title']); $body = trim((string)$_POST['body']);
            $aud = (string)$_POST['audience']; $course = (int)($_POST['course_id'] ?? 0) ?: null;
            if ($title === '' || $body === '') throw new RuntimeException('Enter a title and a message.');
            if (!in_array($aud, ['all', 'students', 'teachers'], true)) throw new RuntimeException('Invalid audience.');
            q('INSERT INTO notices (title,body,audience,course_id,created_by) VALUES (?,?,?,?,?)', [$title, $body, $aud, $aud === 'teachers' ? null : $course, $u['id']]);
            flash('Notice published.');
        } elseif ($act === 'delete') {
            $n = q('SELECT created_by FROM notices WHERE id = ?', [(int)$_POST['id']])->fetch();
            if (!$n || ($u['role'] !== 'admin' && (int)$n['created_by'] !== (int)$u['id'])) throw new RuntimeException('You can only delete your own notices.');
            q('DELETE FROM notices WHERE id = ?', [(int)$_POST['id']]);
            flash('Notice deleted.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect('notices.php');
}

page_header('Notices');
if ($isStaff):
    $courses = q('SELECT id, code FROM courses ORDER BY code')->fetchAll(); ?>
<div class="card"><h2>Post a notice</h2>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="post">
  <label>Title <input name="title" required maxlength="160"></label>
  <label>Audience <select name="audience"><option value="all">Everyone</option><option value="students">Students</option><option value="teachers">Teachers</option></select></label>
  <label>Course (students only) <select name="course_id"><option value="">All courses</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['code']) ?></option><?php endforeach; ?></select></label>
  <label style="grid-column:1/-1">Message <textarea name="body" required></textarea></label>
  <div><button class="btn">Publish</button></div>
</form></div>
<?php endif; ?>
<div class="card">
<?php $rows = visible_notices($u);
if (!$rows) echo '<div class="empty">No notices yet.</div>';
foreach ($rows as $n): ?>
  <div class="notice">
    <div class="row" style="justify-content:space-between">
      <div><strong><?= e($n['title']) ?></strong>
        <span class="badge"><?= e(ucfirst($n['audience'])) ?></span><?= $n['course_code'] ? ' <span class="badge">' . e($n['course_code']) . '</span>' : '' ?></div>
      <small><?= e($n['author'] ?? 'Unknown') ?> · <?= e(date('j M Y, H:i', strtotime($n['created_at']))) ?></small>
    </div>
    <p><?= e($n['body']) ?></p>
    <?php if ($isStaff && ($u['role'] === 'admin' || (int)$n['created_by'] === (int)$u['id'])): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
      <button class="btn small danger" data-confirm="Delete this notice?">Delete</button></form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php page_footer();
