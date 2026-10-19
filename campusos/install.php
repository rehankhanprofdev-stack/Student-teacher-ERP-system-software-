<?php
declare(strict_types=1);
/**
 * One-time installer: creates the database, tables and demo data.
 *   CLI:  php install.php
 *   Web:  open /install.php once, then DELETE this file.
 */
$cfg = require __DIR__ . '/includes/config.php';
$cli = PHP_SAPI === 'cli';
$out = [];
function say(string $m): void { global $out; $out[] = $m; }

try {
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $cfg['db_name']);
    $base = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
    try {
        // Local servers (XAMPP): create the database if it is missing.
        $pdo = new PDO($base, $cfg['db_user'], $cfg['db_pass'], $opts);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");
    } catch (PDOException $e) {
        // Shared hosting: the database already exists and users cannot create databases.
        $pdo = new PDO($base . ';dbname=' . $dbName, $cfg['db_user'], $cfg['db_pass'], $opts);
    }
    say("Database `$dbName` ready.");

    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $stmt) {
        $pdo->exec($stmt);
    }
    say('Tables created.');

    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        say('Users already exist, so demo data was NOT added (nothing was overwritten).');
    } else {
        seed($pdo);
    }
    say('Done. Sign in at index.php. Delete install.php when you are finished.');
} catch (Throwable $e) {
    say('ERROR: ' . $e->getMessage());
}

function seed(PDO $pdo): void {
    mt_srand(42);
    $pdo->beginTransaction();
    $hash = fn(string $p) => password_hash($p, PASSWORD_DEFAULT);
    $addUser = $pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
    $mkUser = function (string $name, string $email, string $pw, string $role) use ($addUser, $pdo, $hash): int {
        $addUser->execute([$name, $email, $hash($pw), $role]);
        return (int)$pdo->lastInsertId();
    };

    $mkUser('Admin User', 'admin@campus.test', 'admin123', 'admin');
    $t = [
        $mkUser('Dr. Meera Kulkarni', 'teacher1@campus.test', 'teacher123', 'teacher'),
        $mkUser('Prof. Arjun Rao', 'teacher2@campus.test', 'teacher123', 'teacher'),
        $mkUser('Ms. Farah Sheikh', 'teacher3@campus.test', 'teacher123', 'teacher'),
    ];

    $pdo->exec("INSERT INTO courses (code,name) VALUES ('BSC-CS','B.Sc. Computer Science, Sem 3'), ('BCOM','B.Com, Sem 3')");
    $cs = (int)$pdo->query("SELECT id FROM courses WHERE code='BSC-CS'")->fetchColumn();
    $bc = (int)$pdo->query("SELECT id FROM courses WHERE code='BCOM'")->fetchColumn();

    $addSub = $pdo->prepare('INSERT INTO subjects (course_id,code,name,teacher_id,max_marks) VALUES (?,?,?,?,100)');
    $subs = [[$cs, 'CS301', 'Data Structures', $t[0]], [$cs, 'CS302', 'Database Systems', $t[0]],
             [$cs, 'CS303', 'Operating Systems', $t[1]], [$cs, 'MA301', 'Discrete Mathematics', $t[1]],
             [$bc, 'AC301', 'Financial Accounting', $t[2]], [$bc, 'EC301', 'Economics', $t[2]],
             [$bc, 'LW301', 'Business Law', $t[1]]];
    $subIds = [];
    foreach ($subs as $s) { $addSub->execute($s); $subIds[] = ['id' => (int)$pdo->lastInsertId(), 'course' => $s[0]]; }

    $names = ['Aarav Patil', 'Diya Joshi', 'Kabir Shah', 'Ananya Iyer', 'Rohan Desai', 'Isha Menon', 'Vihaan Gupta', 'Saanvi Nair',
              'Aditya Verma', 'Riya Kapoor', 'Karan Malhotra', 'Neha Bhatt'];
    $addStu = $pdo->prepare('INSERT INTO students (user_id,roll_no,course_id) VALUES (?,?,?)');
    $students = [];
    foreach ($names as $i => $n) {
        $course = $i < 8 ? $cs : $bc;
        $uid = $mkUser($n, 'student' . ($i + 1) . '@campus.test', 'student123', 'student');
        $roll = ($i < 8 ? 'CS' : 'BC') . '24' . str_pad((string)(($i < 8 ? $i : $i - 8) + 1), 3, '0', STR_PAD_LEFT);
        $addStu->execute([$uid, $roll, $course]);
        $students[] = ['id' => (int)$pdo->lastInsertId(), 'course' => $course, 'att' => 0.62 + mt_rand(0, 36) / 100, 'skill' => 42 + mt_rand(0, 50)];
    }

    // Attendance: last 20 weekdays, up to yesterday.
    $days = [];
    for ($d = new DateTime('yesterday'); count($days) < 20; $d->modify('-1 day')) {
        if ((int)$d->format('N') < 6) $days[] = $d->format('Y-m-d');
    }
    $addAtt = $pdo->prepare('INSERT INTO attendance (student_id,subject_id,att_date,status) VALUES (?,?,?,?)');
    foreach ($students as $st) foreach ($subIds as $sb) {
        if ($sb['course'] !== $st['course']) continue;
        foreach ($days as $day) {
            $r = mt_rand(0, 999) / 1000;
            $status = $r < $st['att'] ? (mt_rand(0, 9) === 0 ? 'late' : 'present') : 'absent';
            $addAtt->execute([$st['id'], $sb['id'], $day, $status]);
        }
    }

    // Marks: two exams per subject.
    $addMark = $pdo->prepare('INSERT INTO marks (student_id,subject_id,exam,obtained,max_marks) VALUES (?,?,?,?,?)');
    foreach ($students as $st) foreach ($subIds as $sb) {
        if ($sb['course'] !== $st['course']) continue;
        foreach ([['Internal 1', 20], ['Midterm', 50]] as [$exam, $max]) {
            $score = max(0, min($max, round($max * ($st['skill'] + mt_rand(-12, 12)) / 100)));
            $addMark->execute([$st['id'], $sb['id'], $exam, $score, $max]);
        }
    }

    // Assignments, a few submissions, notices.
    $addAsg = $pdo->prepare('INSERT INTO assignments (subject_id,title,description,due_date,max_points,created_by) VALUES (?,?,?,?,10,?)');
    $addAsg->execute([$subIds[0]['id'], 'Implement a balanced BST', "Write and explain an AVL tree with insert and delete.\nSubmit your approach and complexity analysis.", date('Y-m-d', strtotime('+7 days')), $t[0]]);
    $a1 = (int)$pdo->lastInsertId();
    $addAsg->execute([$subIds[1]['id'], 'ER diagram for a library', 'Design an ER diagram and normalise it to 3NF. Describe your entities and relationships.', date('Y-m-d', strtotime('+3 days')), $t[0]]);
    $addAsg->execute([$subIds[4]['id'], 'Trial balance exercise', 'Prepare a trial balance from the ledger balances discussed in class.', date('Y-m-d', strtotime('+10 days')), $t[2]]);
    $addSubm = $pdo->prepare('INSERT INTO submissions (assignment_id,student_id,content,grade,feedback) VALUES (?,?,?,?,?)');
    $addSubm->execute([$a1, $students[0]['id'], 'AVL tree with rotations; insert/delete are O(log n).', 9, 'Great work, clear analysis.']);
    $addSubm->execute([$a1, $students[1]['id'], 'Used a red-black tree instead, with notes on why.', null, null]);

    $addNot = $pdo->prepare('INSERT INTO notices (title,body,audience,course_id,created_by) VALUES (?,?,?,?,?)');
    $addNot->execute(['Welcome to the new semester', "Classes begin on schedule. Please check your timetable and keep your attendance above 75%.", 'all', null, 1]);
    $addNot->execute(['Midterm results published', 'Midterm marks are now visible under Marks and on your Result Card.', 'students', null, 1]);
    $addNot->execute(['Faculty meeting on Friday', 'All teachers: please bring your internal assessment sheets.', 'teachers', null, 1]);
    $pdo->commit();

    say('Demo data added. Logins: admin@campus.test / admin123, teacher1@campus.test / teacher123, student1@campus.test / student123.');
}

if ($cli) { echo implode("\n", $out), "\n"; exit(0); }
?><!doctype html><meta charset="utf-8"><title>Maulana Azad College Portal installer</title>
<body style="font:15px system-ui;max-width:640px;margin:8vh auto;padding:0 16px">
<h1>Maulana Azad College Portal installer</h1>
<?php foreach ($out as $line): ?><p><?= htmlspecialchars($line) ?></p><?php endforeach; ?>
<p><a href="index.php">Go to sign in</a>. Remember to delete <code>install.php</code>.</p>
</body>
