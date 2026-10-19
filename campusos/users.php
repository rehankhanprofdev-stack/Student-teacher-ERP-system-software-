<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login(['admin']);

$courses = q('SELECT id, code, name FROM courses ORDER BY code')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'add') {
            $name = trim((string)$_POST['name']);
            $email = strtolower(trim((string)$_POST['email']));
            $pw = (string)$_POST['password'];
            $role = (string)$_POST['role'];
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a name and a valid email.');
            if (strlen($pw) < 8) throw new RuntimeException('Password must be at least 8 characters.');
            if (!in_array($role, ['admin', 'teacher', 'student'], true)) throw new RuntimeException('Invalid role.');
            $roll = trim((string)($_POST['roll_no'] ?? ''));
            $course = (int)($_POST['course_id'] ?? 0);
            if ($role === 'student' && ($roll === '' || !$course)) throw new RuntimeException('Students need a roll number and a course.');
            db()->beginTransaction();
            q('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)', [$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role]);
            $id = (int)db()->lastInsertId();
            if ($role === 'student') q('INSERT INTO students (user_id,roll_no,course_id) VALUES (?,?,?)', [$id, $roll, $course]);
            db()->commit();
            flash("Added $name.");
        } elseif ($act === 'reset') {
            $pw = (string)$_POST['password'];
            if (strlen($pw) < 8) throw new RuntimeException('Password must be at least 8 characters.');
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), (int)$_POST['id']]);
            flash('Password updated.');
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            if ($id === (int)$u['id']) throw new RuntimeException('You cannot delete your own account.');
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('User deleted.');
        }
    } catch (PDOException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash($ex->getCode() === '23000' ? 'That email or roll number is already in use.' : 'Database error.', 'error');
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash($ex->getMessage(), 'error');
    }
    redirect('users.php' . (isset($_GET['role']) ? '?role=' . urlencode((string)$_GET['role']) : ''));
}

$filter = in_array($_GET['role'] ?? '', ['admin', 'teacher', 'student'], true) ? $_GET['role'] : '';
$sql = 'SELECT u.id, u.name, u.email, u.role, s.roll_no, c.code AS course_code
        FROM users u LEFT JOIN students s ON s.user_id = u.id LEFT JOIN courses c ON c.id = s.course_id';
$p = [];
if ($filter) { $sql .= ' WHERE u.role = ?'; $p[] = $filter; }
$rows = q($sql . ' ORDER BY FIELD(u.role,"admin","teacher","student"), c.code, s.roll_no, u.name', $p)->fetchAll();

page_header('Users');
?>
<div class="card">
  <h2>Add a user</h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Full name <input name="name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <label>Temporary password <input type="text" name="password" minlength="8" required></label>
    <label>Role <select name="role">
      <option value="student">Student</option><option value="teacher">Teacher</option><option value="admin">Admin</option>
    </select></label>
    <label>Roll no. (students) <input name="roll_no"></label>
    <label>Course (students) <select name="course_id"><option value="">Select…</option>
      <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['code']) ?></option><?php endforeach; ?>
    </select></label>
    <div><button class="btn">Add user</button></div>
  </form>
</div>

<div class="card">
  <div class="toolbar">
    <h2 style="margin:0">All users</h2>
    <div class="links">
      <a class="badge <?= $filter === '' ? 'ok' : '' ?>" href="users.php">All</a>
      <?php foreach (['admin', 'teacher', 'student'] as $r): ?>
        <a class="badge <?= $filter === $r ? 'ok' : '' ?>" href="users.php?role=<?= $r ?>"><?= ucfirst($r) ?>s</a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Email</th><th>Role</th><th>Roll / course</th><th>Reset password</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td><td><?= e($r['email']) ?></td>
      <td><span class="badge"><?= e($r['role']) ?></span></td>
      <td><?= $r['roll_no'] ? e($r['roll_no'] . ' · ' . $r['course_code']) : '–' ?></td>
      <td>
        <form method="post" class="row">
          <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="password" placeholder="New password" minlength="8" required style="width:140px">
          <button class="btn small ghost" style="color:var(--brand)">Set</button>
        </form>
      </td>
      <td>
        <?php if ((int)$r['id'] !== (int)$u['id']): ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small danger" data-confirm="Delete <?= e($r['name']) ?> and all of their records?">Delete</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php page_footer();
