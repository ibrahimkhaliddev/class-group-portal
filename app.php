<?php
declare(strict_types=1);

const DB_FILE = __DIR__ . '/private/portal.sqlite';
const SEED_DB_FILE = __DIR__ . '/seed/portal.sqlite';
const LEVELS = ['Just starting', 'Some experience', 'Comfortable', 'Very confident'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('SQLite support is not enabled in PHP. Enable pdo_sqlite on your hosting account.');
    if (!is_file(DB_FILE)) {
        $lock = fopen(__DIR__ . '/private/install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('The database folder is not writable.');
        try {
            if (!is_file(DB_FILE)) {
                $temp = DB_FILE . '.new';
                if (!copy(SEED_DB_FILE, $temp) || !rename($temp, DB_FILE)) throw new RuntimeException('Could not create the live database from the included starter file.');
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function one(string $sql, array $params = []): ?array
{
    $s = db()->prepare($sql); $s->execute($params); $row = $s->fetch();
    return $row === false ? null : $row;
}

function rows(string $sql, array $params = []): array
{
    $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll();
}

function run(string $sql, array $params = []): void
{
    $s = db()->prepare($sql); $s->execute($params);
}

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page): string { return 'index.php?page=' . rawurlencode($page); }
function go(string $page): never { header('Location: ' . url($page)); exit; }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = [$type, $message]; }

function csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function require_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Your session expired. Refresh the page and try again.');
}

function student(): ?array
{
    return isset($_SESSION['student_id']) ? one('SELECT * FROM students WHERE id = ? AND archived_at IS NULL', [(int)$_SESSION['student_id']]) : null;
}

function admin(): ?array
{
    return isset($_SESSION['admin_id']) ? one('SELECT id, username FROM admins WHERE id = ?', [(int)$_SESSION['admin_id']]) : null;
}

function locked(): bool { return (one("SELECT value FROM settings WHERE key = 'groups_locked'")['value'] ?? '0') === '1'; }

function check_login_limit(string $identity): void
{
    $attempt = one('SELECT locked_until FROM login_attempts WHERE identity = ?', [$identity]);
    if ($attempt && (int)$attempt['locked_until'] > time()) throw new RuntimeException('Too many sign-in attempts. Try again in 15 minutes.');
}

function login_failed(string $identity): void
{
    $attempt = one('SELECT failures, locked_until FROM login_attempts WHERE identity = ?', [$identity]);
    $failures = $attempt && (int)$attempt['locked_until'] === 0 ? (int)$attempt['failures'] + 1 : 1;
    $until = $failures >= 5 ? time() + 900 : 0;
    run('INSERT INTO login_attempts(identity,failures,locked_until) VALUES(?,?,?) ON CONFLICT(identity) DO UPDATE SET failures=excluded.failures, locked_until=excluded.locked_until', [$identity,$failures,$until]);
}

function require_student(): array
{
    $s = student(); if (!$s) go('student-login'); return $s;
}

function require_admin(): array
{
    $a = admin(); if (!$a) go('admin-login'); return $a;
}

function question_rows(): array { return rows('SELECT * FROM questions WHERE active = 1 ORDER BY position, id'); }

function group_rows(): array
{
    return rows('SELECT g.*, COUNT(s.id) AS member_count, (SELECT COUNT(*) FROM students linked WHERE linked.group_id = g.id) AS linked_count FROM groups g LEFT JOIN students s ON s.group_id = g.id AND s.archived_at IS NULL WHERE g.archived_at IS NULL GROUP BY g.id ORDER BY g.id');
}

function ensure_group_archive_column(): void
{
    $columns = array_column(rows('PRAGMA table_info(groups)'), 'name');
    if (in_array('archived_at', $columns, true)) return;
    $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
    try {
        $columns = array_column(rows('PRAGMA table_info(groups)'), 'name');
        if (!in_array('archived_at', $columns, true)) $pdo->exec('ALTER TABLE groups ADD COLUMN archived_at TEXT');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
}

function ensure_student_archive_column(): void
{
    $columns = array_column(rows('PRAGMA table_info(students)'), 'name');
    if (in_array('archived_at', $columns, true)) return;
    $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
    try {
        $columns = array_column(rows('PRAGMA table_info(students)'), 'name');
        if (!in_array('archived_at', $columns, true)) $pdo->exec('ALTER TABLE students ADD COLUMN archived_at TEXT');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
}

function ensure_fixed_groups(): void
{
    if ((one("SELECT value FROM settings WHERE key = 'fixed_groups_ready'")['value'] ?? '') === '1') return;
    $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ((one("SELECT value FROM settings WHERE key = 'fixed_groups_ready'")['value'] ?? '') !== '1') {
            $existing = rows('SELECT id FROM groups ORDER BY id');
            $prefix = '__group_upgrade_' . bin2hex(random_bytes(6)) . '_';
            foreach ($existing as $g) run('UPDATE groups SET name = ? WHERE id = ?', [$prefix . $g['id'], $g['id']]);
            foreach ($existing as $index => $g) run('UPDATE groups SET name = ? WHERE id = ?', ['Group ' . ($index + 1), $g['id']]);
            for ($i = count($existing) + 1; $i <= 10; $i++) run('INSERT INTO groups(name) VALUES(?)', ['Group ' . $i]);
            run("INSERT INTO settings(key,value) VALUES('fixed_groups_ready','1') ON CONFLICT(key) DO UPDATE SET value='1'");
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
}

function skill_summary(int $studentId): string
{
    $skills = rows('SELECT q.label FROM answers a JOIN questions q ON q.id = a.question_id WHERE a.student_id = ? AND a.level >= 2 ORDER BY a.level DESC, q.position LIMIT 2', [$studentId]);
    return $skills ? implode(' · ', array_column($skills, 'label')) : 'Exploring their skills';
}

function take_answers(int $studentId): void
{
    $answers = $_POST['answer'] ?? [];
    foreach (question_rows() as $q) {
        $value = $answers[$q['id']] ?? null;
        if (!is_scalar($value) || !in_array((string)$value, ['0','1','2','3'], true)) throw new RuntimeException('Please answer every question.');
        run('INSERT INTO answers(student_id, question_id, level) VALUES(?,?,?) ON CONFLICT(student_id,question_id) DO UPDATE SET level=excluded.level', [$studentId, $q['id'], (int)$value]);
    }
}

function membership_change(int $studentId, ?int $groupId): void
{
    $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (locked()) throw new RuntimeException('Groups are locked. An admin must unlock them before changes can be made.');
        if ($groupId !== null) {
            $g = one('SELECT id FROM groups WHERE id = ? AND archived_at IS NULL', [$groupId]);
            if (!$g) throw new RuntimeException('That group no longer exists.');
            $current = one('SELECT group_id FROM students WHERE id = ? AND archived_at IS NULL', [$studentId]);
            if (!$current) throw new RuntimeException('Student not found.');
            if ((int)$current['group_id'] !== $groupId) {
                $count = (int)one('SELECT COUNT(*) AS n FROM students WHERE group_id = ? AND archived_at IS NULL', [$groupId])['n'];
                if ($count >= 4) throw new RuntimeException('This group is full. Choose another group.');
            }
        }
        run('UPDATE students SET group_id = ? WHERE id = ? AND archived_at IS NULL', [$groupId, $studentId]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
}

function handle_post(): void
{
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'setup') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username) || strlen($password) < 10) throw new RuntimeException('Use a username of 3–32 letters or numbers and a password of at least 10 characters.');
        $storedCode = one("SELECT value FROM settings WHERE key = 'setup_code_hash'")['value'] ?? '';
        if (!$storedCode || !hash_equals($storedCode, hash('sha256', (string)($_POST['setup_code'] ?? '')))) throw new RuntimeException('Setup code is incorrect.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if ((int)one('SELECT COUNT(*) AS n FROM admins')['n'] > 0) throw new RuntimeException('Setup is already complete.');
            run('INSERT INTO admins(username,password_hash) VALUES(?,?)', [$username, password_hash($password, PASSWORD_DEFAULT)]);
            run("UPDATE settings SET value = 'used' WHERE key = 'setup_code_hash'");
            $id = (int)$pdo->lastInsertId(); $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        session_regenerate_id(true); $_SESSION['admin_id'] = $id; unset($_SESSION['student_id']);
        flash('Admin account created.'); go('admin');
    }
    if ($action === 'student-register') {
        if (locked()) throw new RuntimeException('Student registration is closed while groups are locked.');
        $name = trim((string)($_POST['name'] ?? '')); $roll = strtoupper(trim((string)($_POST['roll'] ?? ''))); $pin = (string)($_POST['pin'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new RuntimeException('Enter your full name (2–80 characters).');
        if (!preg_match('/^[A-Z0-9][A-Z0-9\/-]{1,29}$/', $roll)) throw new RuntimeException('Enter a valid roll number.');
        if (!preg_match('/^\d{4,8}$/', $pin)) throw new RuntimeException('Choose a 4–8 digit PIN.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (locked()) throw new RuntimeException('Student registration is closed while groups are locked.');
            if ((int)one('SELECT COUNT(*) AS n FROM students WHERE archived_at IS NULL')['n'] >= 40) throw new RuntimeException('The class limit of 40 students has been reached.');
            if (one('SELECT id FROM students WHERE roll_number = ?', [$roll])) throw new RuntimeException('This roll number is already registered. Sign in instead.');
            run('INSERT INTO students(name,roll_number,pin_hash) VALUES(?,?,?)', [$name,$roll,password_hash($pin,PASSWORD_DEFAULT)]);
            $id = (int)$pdo->lastInsertId(); take_answers($id); $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        session_regenerate_id(true); $_SESSION['student_id'] = $id; unset($_SESSION['admin_id']);
        flash('Your profile is ready. Choose one of the class groups.'); go('groups');
    }
    if ($action === 'student-login') {
        $roll = strtoupper(trim((string)($_POST['roll'] ?? ''))); $identity = 'student:' . $roll; check_login_limit($identity);
        $s = one('SELECT * FROM students WHERE roll_number = ? AND archived_at IS NULL', [$roll]);
        if (!$s || !password_verify((string)($_POST['pin'] ?? ''), $s['pin_hash'])) { login_failed($identity); throw new RuntimeException('Roll number or PIN is incorrect.'); }
        run('UPDATE login_attempts SET failures = 0, locked_until = 0 WHERE identity = ?', [$identity]);
        session_regenerate_id(true); $_SESSION['student_id'] = (int)$s['id']; unset($_SESSION['admin_id']);
        if ((int)one('SELECT COUNT(*) AS n FROM answers WHERE student_id = ?', [(int)$s['id']])['n'] === 0 && question_rows()) {
            flash('Please complete your skills questionnaire.'); go('profile');
        }
        go('groups');
    }
    if ($action === 'admin-login') {
        $username = trim((string)($_POST['username'] ?? '')); $identity = 'admin:' . strtolower($username); check_login_limit($identity);
        $a = one('SELECT * FROM admins WHERE username = ?', [$username]);
        if (!$a || !password_verify((string)($_POST['password'] ?? ''), $a['password_hash'])) { login_failed($identity); throw new RuntimeException('Username or password is incorrect.'); }
        run('UPDATE login_attempts SET failures = 0, locked_until = 0 WHERE identity = ?', [$identity]);
        session_regenerate_id(true); $_SESSION['admin_id'] = (int)$a['id']; unset($_SESSION['student_id']); go('admin');
    }
    if ($action === 'logout') { $_SESSION = []; session_regenerate_id(true); flash('You have signed out.'); go('home'); }
    if ($action === 'save-details') {
        $s = require_student();
        $name = trim((string)($_POST['name'] ?? ''));
        $roll = strtoupper(trim((string)($_POST['roll'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new RuntimeException('Enter your full name (2–80 characters).');
        if (!preg_match('/^[A-Z0-9][A-Z0-9\/-]{1,29}$/', $roll)) throw new RuntimeException('Enter a valid roll number.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (one('SELECT id FROM students WHERE roll_number = ? AND id <> ?', [$roll, (int)$s['id']])) throw new RuntimeException('This roll number is already registered.');
            run('UPDATE students SET name = ?, roll_number = ? WHERE id = ? AND archived_at IS NULL', [$name, $roll, (int)$s['id']]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        flash('Your details were updated.'); go('profile');
    }
    if (in_array($action, ['join','leave','save-answers'], true)) {
        $s = require_student();
        if (locked()) throw new RuntimeException('Groups are locked by an admin.');
        if ($action === 'save-answers') {
            $firstAnswers = (int)one('SELECT COUNT(*) AS n FROM answers WHERE student_id = ?', [(int)$s['id']])['n'] === 0;
            take_answers((int)$s['id']); flash('Your skills were updated.'); go($firstAnswers ? 'groups' : 'profile');
        }
        if ($action === 'join') { membership_change((int)$s['id'], filter_input(INPUT_POST,'group_id',FILTER_VALIDATE_INT) ?: null); flash('You joined the group.'); go('groups'); }
        if ($action === 'leave') { membership_change((int)$s['id'], null); flash('You left the group.'); go('groups'); }
    }
    $a = require_admin();
    if ($action === 'add-group') {
        $name = trim((string)($_POST['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) throw new RuntimeException('Enter a group name of 2–60 characters.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (locked()) throw new RuntimeException('Unlock groups before adding a group.');
            if (one('SELECT id FROM groups WHERE name = ? COLLATE NOCASE', [$name])) throw new RuntimeException('This group name already exists. Restore it if it was removed.');
            run('INSERT INTO groups(name,created_by) VALUES(?,?)', [$name, (int)$a['id']]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        flash('Group created.'); go('admin');
    }
    if ($action === 'archive-group' || $action === 'restore-group') {
        $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
        if (!$groupId) throw new RuntimeException('Choose a group.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (locked()) throw new RuntimeException('Unlock groups before changing groups.');
            $group = one('SELECT id, archived_at FROM groups WHERE id = ?', [$groupId]);
            if (!$group) throw new RuntimeException('Group not found.');
            if ($action === 'archive-group') {
                if ($group['archived_at'] !== null) throw new RuntimeException('This group is already removed.');
                if ((int)one('SELECT COUNT(*) AS n FROM students WHERE group_id = ?', [$groupId])['n'] > 0) throw new RuntimeException('Move all students out of this group before removing it.');
                run('UPDATE groups SET archived_at = CURRENT_TIMESTAMP WHERE id = ?', [$groupId]);
            } else {
                if ($group['archived_at'] === null) throw new RuntimeException('This group is already active.');
                run('UPDATE groups SET archived_at = NULL WHERE id = ?', [$groupId]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        flash($action === 'archive-group' ? 'Group removed from the active list. Its record is saved below.' : 'Group restored.'); go('admin');
    }
    if ($action === 'add-student') {
        $name = trim((string)($_POST['name'] ?? ''));
        $roll = strtoupper(trim((string)($_POST['roll'] ?? '')));
        $pin = (string)($_POST['pin'] ?? '');
        $groupRaw = trim((string)($_POST['group_id'] ?? ''));
        $groupId = $groupRaw === '' ? null : filter_var($groupRaw, FILTER_VALIDATE_INT);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new RuntimeException('Enter a full name of 2–80 characters.');
        if (!preg_match('/^[A-Z0-9][A-Z0-9\/-]{1,29}$/', $roll)) throw new RuntimeException('Enter a valid roll number.');
        if (!preg_match('/^\d{4,8}$/', $pin)) throw new RuntimeException('Choose a 4–8 digit PIN.');
        if ($groupId === false) throw new RuntimeException('Choose a valid group.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (locked()) throw new RuntimeException('Unlock groups before adding a student.');
            if ((int)one('SELECT COUNT(*) AS n FROM students WHERE archived_at IS NULL')['n'] >= 40) throw new RuntimeException('The class limit of 40 students has been reached.');
            if (one('SELECT id FROM students WHERE roll_number = ?', [$roll])) throw new RuntimeException('This roll number is already registered.');
            if ($groupId !== null) {
                if (!one('SELECT id FROM groups WHERE id = ? AND archived_at IS NULL', [$groupId])) throw new RuntimeException('Choose an active group.');
                if ((int)one('SELECT COUNT(*) AS n FROM students WHERE group_id = ? AND archived_at IS NULL', [$groupId])['n'] >= 4) throw new RuntimeException('This group is full. Choose another group.');
            }
            run('INSERT INTO students(name,roll_number,pin_hash,group_id) VALUES(?,?,?,?)', [$name,$roll,password_hash($pin,PASSWORD_DEFAULT),$groupId]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        flash('Student added. Give them their roll number and temporary PIN so they can sign in and complete their skills.'); go('admin');
    }
    if ($action === 'add-admin') {
        $username = trim((string)($_POST['username'] ?? '')); $password = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/',$username) || strlen($password) < 10) throw new RuntimeException('Use a username of 3–32 letters or numbers and a password of at least 10 characters.');
        run('INSERT INTO admins(username,password_hash) VALUES(?,?)', [$username,password_hash($password,PASSWORD_DEFAULT)]); flash('Admin added.'); go('admins');
    }
    if ($action === 'archive-student' || $action === 'restore-student') {
        $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        if (!$studentId) throw new RuntimeException('Choose a student.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (locked()) throw new RuntimeException('Unlock groups before changing student accounts.');
            $target = one('SELECT id, group_id, archived_at FROM students WHERE id = ?', [$studentId]);
            if (!$target) throw new RuntimeException('Student not found.');
            if ($action === 'archive-student') {
                if ($target['archived_at'] !== null) throw new RuntimeException('This student is already removed from the active class.');
                run('UPDATE students SET archived_at = CURRENT_TIMESTAMP WHERE id = ?', [$studentId]);
            } else {
                if ($target['archived_at'] === null) throw new RuntimeException('This student is already active.');
                if ((int)one('SELECT COUNT(*) AS n FROM students WHERE archived_at IS NULL')['n'] >= 40) throw new RuntimeException('The class limit of 40 active students has been reached.');
                if ($target['group_id'] !== null && !one('SELECT id FROM groups WHERE id = ? AND archived_at IS NULL', [(int)$target['group_id']])) throw new RuntimeException('Restore their previous group before restoring this student.');
                if ($target['group_id'] !== null && (int)one('SELECT COUNT(*) AS n FROM students WHERE group_id = ? AND archived_at IS NULL', [(int)$target['group_id']])['n'] >= 4) throw new RuntimeException('Their previous group is full. Make space before restoring this student.');
                run('UPDATE students SET archived_at = NULL WHERE id = ?', [$studentId]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        flash($action === 'archive-student' ? 'Student removed from the active class. Their profile, answers, and group history are saved below.' : 'Student restored with their existing profile and group.');
        go('admin');
    }
    if ($action === 'lock') {
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            $bad = rows('SELECT g.name, COUNT(s.id) AS n FROM groups g LEFT JOIN students s ON s.group_id = g.id AND s.archived_at IS NULL WHERE g.archived_at IS NULL GROUP BY g.id HAVING n > 0 AND (n < 2 OR n > 4)');
            $ungrouped = (int)one('SELECT COUNT(*) AS n FROM students WHERE group_id IS NULL AND archived_at IS NULL')['n'];
            if ($bad || $ungrouped || (int)one('SELECT COUNT(*) AS n FROM students WHERE archived_at IS NULL')['n'] === 0) throw new RuntimeException('Assign every registered student and make sure each occupied group has 2–4 members before locking.');
            run("UPDATE settings SET value = '1' WHERE key = 'groups_locked'"); $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
        flash('Groups are locked. Students can only view them.'); go('admin');
    }
    if ($action === 'unlock') { run("UPDATE settings SET value = '0' WHERE key = 'groups_locked'"); flash('Group selection is open again.'); go('admin'); }
    if ($action === 'move-student') {
        $studentId = filter_input(INPUT_POST,'student_id',FILTER_VALIDATE_INT); $groupId = filter_input(INPUT_POST,'group_id',FILTER_VALIDATE_INT);
        if (!$studentId) throw new RuntimeException('Choose a student.');
        membership_change($studentId, $groupId ?: null); flash('Student group updated.'); go('admin');
    }
    if ($action === 'add-question') {
        $label = trim((string)($_POST['label'] ?? ''));
        if (mb_strlen($label) < 3 || mb_strlen($label) > 90) throw new RuntimeException('Question must be 3–90 characters.');
        run('INSERT INTO questions(label,position) VALUES(?,(SELECT COALESCE(MAX(position),0)+1 FROM questions))', [$label]); flash('Question added.'); go('questions');
    }
    if ($action === 'toggle-question') {
        $id = filter_input(INPUT_POST,'question_id',FILTER_VALIDATE_INT); if (!$id) throw new RuntimeException('Choose a question.');
        run('UPDATE questions SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id = ?', [$id]); flash('Question updated.'); go('questions');
    }
    throw new RuntimeException('Unknown action.');
}
