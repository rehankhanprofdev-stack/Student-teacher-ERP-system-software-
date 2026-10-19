<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
page_header('Dashboard');

function stat_card(string $label, $value, string $hint = '', string $tone = ''): void {
    echo '<div class="stat ' . e($tone) . '"><div class="stat-v">' . e($value) . '</div><div class="stat-l">' . e($label) . '</div>'
       . ($hint !== '' ? '<div class="stat-h">' . e($hint) . '</div>' : '') . '</div>';
}
function notices_card(array $u): void {
    echo '<div class="card"><h2>Latest notices</h2>';
    $rows = visible_notices($u, 5);
    if (!$rows) echo '<div class="empty">No notices yet.</div>';
    foreach ($rows as $n) {
        echo '<div class="notice"><strong>' . e($n['title']) . '</strong> <small>' . e(date('j M', strtotime($n['created_at']))) . '</small>'
           . '<p>' . e(mb_strimwidth($n['body'], 0, 160, '…')) . '</p></div>';
    }
    echo '<p><a href="' . e(url('notices.php')) . '">All notices →</a></p></div>';
}

if ($u['role'] === 'admin') {
    $count = fn(string $sql): int => (int)q($sql)->fetchColumn();
    $low = $count("SELECT COUNT(*) FROM (SELECT student_id FROM attendance GROUP BY student_id
                   HAVING SUM(status IN ('present','late')) / COUNT(*) * 100 < " . (int)cfg('min_att') . ") t");
    echo '<div class="stats">';
    stat_card('Students', $count('SELECT COUNT(*) FROM students'));
    stat_card('Teachers', $count("SELECT COUNT(*) FROM users WHERE role='teacher'"));
    stat_card('Courses', $count('SELECT COUNT(*) FROM courses'));
    stat_card('Subjects', $count('SELECT COUNT(*) FROM subjects'));
    stat_card('Below ' . cfg('min_att') . '% attendance', $low, 'students', $low ? 'warn' : 'good');
    echo '</div><div class="grid">';
    notices_card($u);
    echo '<div class="card"><h2>Quick actions</h2><div class="links">'
       . '<a class="btn" href="' . e(url('users.php')) . '">Add user</a>'
       . '<a class="btn" href="' . e(url('courses.php')) . '">Courses &amp; subjects</a>'
       . '<a class="btn" href="' . e(url('notices.php')) . '">Post notice</a>'
       . '<a class="btn" href="' . e(url('reports.php')) . '">Reports</a></div></div></div>';

} elseif ($u['role'] === 'teacher') {
    $subs = accessible_subjects($u);
    echo '<div class="card"><h2>My subjects</h2>';
    if (!$subs) echo '<div class="empty">No subjects have been assigned to you yet.</div>';
    else {
        echo '<div class="table-wrap"><table><tr><th>Subject</th><th>Course</th><th class="num">Students</th><th>Attendance</th><th class="num">Avg marks</th><th class="num">To grade</th></tr>';
        foreach ($subs as $s) {
            $stu = (int)q('SELECT COUNT(*) FROM students WHERE course_id = ?', [$s['course_id']])->fetchColumn();
            $att = q("SELECT COUNT(*) t, COALESCE(SUM(status IN ('present','late')),0) a FROM attendance WHERE subject_id = ?", [$s['id']])->fetch();
            $mk = q('SELECT COALESCE(SUM(obtained),0) o, COALESCE(SUM(max_marks),0) m FROM marks WHERE subject_id = ?', [$s['id']])->fetch();
            $pend = (int)q('SELECT COUNT(*) FROM submissions sb JOIN assignments a ON a.id = sb.assignment_id WHERE a.subject_id = ? AND sb.grade IS NULL', [$s['id']])->fetchColumn();
            $ap = pct($att['a'], $att['t']);
            echo '<tr><td><strong>' . e($s['name']) . '</strong><br><small>' . e($s['code']) . '</small></td><td>' . e($s['course_code']) . '</td>'
               . '<td class="num">' . $stu . '</td><td>' . ($att['t'] ? e($ap) . '%' . bar($ap) : '<small>no data</small>') . '</td>'
               . '<td class="num">' . ($mk['m'] > 0 ? e(pct($mk['o'], $mk['m'])) . '%' : '–') . '</td>'
               . '<td class="num">' . ($pend ? '<span class="badge warn">' . $pend . '</span>' : '0') . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '<div class="links" style="margin-top:12px"><a class="btn" href="' . e(url('attendance.php')) . '">Take attendance</a>'
       . '<a class="btn" href="' . e(url('marks.php')) . '">Enter marks</a>'
       . '<a class="btn" href="' . e(url('assignments.php')) . '">Assignments</a></div></div>';
    notices_card($u);

} else {
    $st = student_row((int)$u['id']);
    if (!$st) {
        echo '<div class="card"><h2>No student profile</h2><p>Ask the administrator to link your account to a course.</p></div>';
        page_footer(); exit;
    }
    $attRows = student_attendance((int)$st['id']);
    $resRows = student_results((int)$st['id']);
    $at = attendance_totals($attRows);
    $to = 0; $tm = 0;
    foreach ($resRows as $r) { $to += $r['obtained']; $tm += $r['max_marks']; }
    $mp = pct($to, $tm);
    $due = q('SELECT a.*, sb.name AS subject FROM assignments a JOIN subjects sb ON sb.id = a.subject_id
              WHERE sb.course_id = ? AND a.due_date >= CURDATE()
                AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?)
              ORDER BY a.due_date LIMIT 5', [$st['course_id'], $st['id']])->fetchAll();

    echo '<p class="muted">' . e($st['name']) . ' · Roll ' . e($st['roll_no']) . ' · ' . e($st['course_name']) . '</p><div class="stats">';
    stat_card('Attendance', $at['total'] ? $at['pct'] . '%' : '–', $at['attended'] . ' of ' . $at['total'] . ' classes', ($at['total'] && $at['pct'] < cfg('min_att')) ? 'warn' : 'good');
    stat_card('Overall marks', $tm > 0 ? $mp . '%' : '–', fmt($to) . ' / ' . fmt($tm));
    stat_card('Grade', $tm > 0 ? grade($mp) : '–');
    stat_card('Assignments due', count($due), 'not yet submitted', count($due) ? 'warn' : 'good');
    echo '</div><div class="grid"><div class="card"><h2>Attendance by subject</h2>';
    foreach ($attRows as $r) {
        $p = pct($r['attended'], $r['total']);
        echo '<div style="margin-bottom:10px"><div class="row" style="justify-content:space-between"><span>' . e($r['name']) . '</span><strong>'
           . ($r['total'] ? e($p) . '%' : '–') . '</strong></div>' . ($r['total'] ? bar($p) : '') . '</div>';
    }
    echo '</div><div class="card"><h2>Marks by subject</h2><div class="table-wrap"><table><tr><th>Subject</th><th class="num">Marks</th><th class="num">%</th></tr>';
    foreach ($resRows as $r) {
        echo '<tr><td>' . e($r['name']) . '</td><td class="num">' . ($r['exams'] ? fmt($r['obtained']) . ' / ' . fmt($r['max_marks']) : '–') . '</td>'
           . '<td class="num">' . ($r['exams'] ? e(pct($r['obtained'], $r['max_marks'])) . '%' : '–') . '</td></tr>';
    }
    echo '</table></div><p><a href="' . e(url('result.php')) . '">Open result card →</a></p></div></div><div class="grid">';
    echo '<div class="card"><h2>Upcoming assignments</h2>';
    if (!$due) echo '<div class="empty">Nothing pending. 🎉</div>';
    foreach ($due as $a) {
        echo '<div class="notice"><strong>' . e($a['title']) . '</strong> <span class="badge">' . e($a['subject']) . '</span>'
           . '<p>Due ' . e(date('D, j M', strtotime($a['due_date']))) . '</p></div>';
    }
    echo '<p><a href="' . e(url('assignments.php')) . '">All assignments →</a></p></div>';
    notices_card($u);
    echo '</div>';
}
page_footer();
