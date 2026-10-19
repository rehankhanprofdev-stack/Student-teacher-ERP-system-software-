<?php
function nav_items(string $role): array {
    $all3 = ['admin', 'teacher', 'student'];
    $items = [
        'dashboard.php'   => ['Dashboard', $all3],
        'attendance.php'  => ['Attendance', $all3],
        'marks.php'       => ['Marks', $all3],
        'assignments.php' => ['Assignments', $all3],
        'notices.php'     => ['Notices', $all3],
        'result.php'      => ['Result Card', $all3],
        'reports.php'     => ['Reports', ['admin', 'teacher']],
        'users.php'       => ['Users', ['admin']],
        'courses.php'     => ['Courses & Subjects', ['admin']],
    ];
    return array_filter($items, fn($v) => in_array($role, $v[1], true));
}

function page_header(string $title): void {
    $u = user();
    $cur = basename($_SERVER['SCRIPT_NAME']);
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(cfg('app_name')) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body>
<?php if ($u): ?>
<div class="shell">
  <aside class="side">
    <div class="brand">Maulana Azad<span>College Portal</span></div>
    <button class="navtoggle" type="button" data-nav-toggle aria-label="Menu">Menu</button>
    <nav>
      <?php foreach (nav_items($u['role']) as $file => $item): ?>
        <a href="<?= e(url($file)) ?>" class="<?= $cur === $file ? 'active' : '' ?>"><?= e($item[0]) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <strong><?= e($u['name']) ?></strong>
      <small><?= e(ucfirst($u['role'])) ?></small>
      <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn ghost small">Sign out</button></form>
    </div>
  </aside>
  <main class="main">
    <header class="topbar"><h1><?= e($title) ?></h1><span class="muted"><?= e(date('D, j M Y')) ?></span></header>
<?php else: ?>
<main class="solo">
<?php endif;
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="alert ' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function page_footer(): void {
    echo '</main>';
    if (user()) echo '</div>';
    echo '<script src="' . e(url('assets/app.js')) . '"></script></body></html>';
}
