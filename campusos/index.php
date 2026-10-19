<?php
require __DIR__ . '/includes/bootstrap.php';
if (user()) redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $now = time();
    if (($_SESSION['lock_until'] ?? 0) > $now) {
        $error = 'Too many attempts. Try again in ' . ($_SESSION['lock_until'] - $now) . ' seconds.';
    } else {
        $row = q('SELECT id, password_hash FROM users WHERE email = ?', [strtolower(trim((string)($_POST['email'] ?? '')))])->fetch();
        if ($row && password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            unset($_SESSION['fails'], $_SESSION['lock_until']);
            redirect('dashboard.php');
        }
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = $now + 30; $_SESSION['fails'] = 0; }
        $error = 'Invalid email or password.';
    }
}
page_header('Sign in');
?>
<div class="login-card">
  <div class="brand">Maulana Azad<span>College Portal</span></div>
  <p class="muted"><?= e(cfg('college')) ?></p>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Email <input type="email" name="email" required autofocus autocomplete="username"></label>
    <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn">Sign in</button>
  </form>
  <?php if (cfg('demo_mode')): ?>
  <div class="demo">
    <strong>Demo logins</strong><br>
    Admin: <code>admin@campus.test</code> / <code>admin123</code><br>
    Teacher: <code>teacher1@campus.test</code> / <code>teacher123</code><br>
    Student: <code>student1@campus.test</code> / <code>student123</code>
  </div>
  <?php endif; ?>
</div>
<?php page_footer();
