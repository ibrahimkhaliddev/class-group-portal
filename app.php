<?php
declare(strict_types=1);

const DB_FILE = __DIR__ . '/private/portal.sqlite';
const LEVELS = ['Just starting', 'Some experience', 'Comfortable', 'Very confident'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('SQLite support is not enabled in PHP. Enable pdo_sqlite on your hosting account.');
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
    return isset($_SESSION['student_id']) ? one('SELECT * FROM students WHERE id = ?', [(int)$_SESSION['student_id']]) : null;
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
    $failures = $attempt && (int)$attempt['locked_until'] <= time() ? (int)$attempt['failures'] + 1 : 1;
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
    return rows('SELECT g.*, COUNT(s.id) AS member_count FROM groups g LEFT JOIN students s ON s.group_id = g.id GROUP BY g.id ORDER BY g.id');
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
            $g = one('SELECT id FROM groups WHERE id = ?', [$groupId]);
            if (!$g) throw new RuntimeException('That group no longer exists.');
            $current = one('SELECT group_id FROM students WHERE id = ?', [$studentId]);
            if (!$current) throw new RuntimeException('Student not found.');
            if ((int)$current['group_id'] !== $groupId) {
                $count = (int)one('SELECT COUNT(*) AS n FROM students WHERE group_id = ?', [$groupId])['n'];
                if ($count >= 4) throw new RuntimeException('This group is full. Choose another group.');
            }
        }
        run('UPDATE students SET group_id = ? WHERE id = ?', [$groupId, $studentId]);
        $pdo->exec('DELETE FROM groups WHERE id NOT IN (SELECT DISTINCT group_id FROM students WHERE group_id IS NOT NULL)');
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
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
            run("DELETE FROM settings WHERE key = 'setup_code_hash'");
            $id = (int)$pdo->lastInsertId(); $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
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
            if ((int)one('SELECT COUNT(*) AS n FROM students')['n'] >= 40) throw new RuntimeException('The class limit of 40 students has been reached.');
            if (one('SELECT id FROM students WHERE roll_number = ?', [$roll])) throw new RuntimeException('This roll number is already registered. Sign in instead.');
            run('INSERT INTO students(name,roll_number,pin_hash) VALUES(?,?,?)', [$name,$roll,password_hash($pin,PASSWORD_DEFAULT)]);
            $id = (int)$pdo->lastInsertId(); take_answers($id); $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        session_regenerate_id(true); $_SESSION['student_id'] = $id; unset($_SESSION['admin_id']);
        flash('Your profile is ready. Choose a group.'); go('groups');
    }
    if ($action === 'student-login') {
        $roll = strtoupper(trim((string)($_POST['roll'] ?? ''))); $identity = 'student:' . $roll; check_login_limit($identity);
        $s = one('SELECT * FROM students WHERE roll_number = ?', [$roll]);
        if (!$s || !password_verify((string)($_POST['pin'] ?? ''), $s['pin_hash'])) { login_failed($identity); throw new RuntimeException('Roll number or PIN is incorrect.'); }
        run('DELETE FROM login_attempts WHERE identity = ?', [$identity]);
        session_regenerate_id(true); $_SESSION['student_id'] = (int)$s['id']; unset($_SESSION['admin_id']); go('groups');
    }
    if ($action === 'admin-login') {
        $username = trim((string)($_POST['username'] ?? '')); $identity = 'admin:' . strtolower($username); check_login_limit($identity);
        $a = one('SELECT * FROM admins WHERE username = ?', [$username]);
        if (!$a || !password_verify((string)($_POST['password'] ?? ''), $a['password_hash'])) { login_failed($identity); throw new RuntimeException('Username or password is incorrect.'); }
        run('DELETE FROM login_attempts WHERE identity = ?', [$identity]);
        session_regenerate_id(true); $_SESSION['admin_id'] = (int)$a['id']; unset($_SESSION['student_id']); go('admin');
    }
    if ($action === 'logout') { $_SESSION = []; session_regenerate_id(true); flash('You have signed out.'); go('home'); }
    if (in_array($action, ['join','leave','create-group','save-answers'], true)) {
        $s = require_student();
        if (locked()) throw new RuntimeException('Groups are locked by an admin.');
        if ($action === 'save-answers') { take_answers((int)$s['id']); flash('Your skills were updated.'); go('profile'); }
        if ($action === 'join') { membership_change((int)$s['id'], filter_input(INPUT_POST,'group_id',FILTER_VALIDATE_INT) ?: null); flash('You joined the group.'); go('groups'); }
        if ($action === 'leave') { membership_change((int)$s['id'], null); flash('You left the group.'); go('groups'); }
        $name = trim((string)($_POST['group_name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 40) throw new RuntimeException('Group name must be 3–40 characters.');
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (locked()) throw new RuntimeException('Groups are locked by an admin.');
            run('INSERT INTO groups(name,created_by) VALUES(?,?)', [$name,(int)$s['id']]);
            $groupId = (int)$pdo->lastInsertId(); run('UPDATE students SET group_id = ? WHERE id = ?', [$groupId,(int)$s['id']]);
            $pdo->exec('DELETE FROM groups WHERE id != ' . $groupId . ' AND id NOT IN (SELECT DISTINCT group_id FROM students WHERE group_id IS NOT NULL)');
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        flash('Group created. Invite classmates to join.'); go('groups');
    }
    $a = require_admin();
    if ($action === 'add-admin') {
        $username = trim((string)($_POST['username'] ?? '')); $password = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/',$username) || strlen($password) < 10) throw new RuntimeException('Use a username of 3–32 letters or numbers and a password of at least 10 characters.');
        run('INSERT INTO admins(username,password_hash) VALUES(?,?)', [$username,password_hash($password,PASSWORD_DEFAULT)]); flash('Admin added.'); go('admins');
    }
    if ($action === 'lock') {
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            $bad = rows('SELECT g.name, COUNT(s.id) AS n FROM groups g LEFT JOIN students s ON s.group_id = g.id GROUP BY g.id HAVING n < 2 OR n > 4');
            $ungrouped = (int)one('SELECT COUNT(*) AS n FROM students WHERE group_id IS NULL')['n'];
            if ($bad || $ungrouped || (int)one('SELECT COUNT(*) AS n FROM groups')['n'] === 0) throw new RuntimeException('Assign every registered student and make sure every group has 2–4 members before locking.');
            run("UPDATE settings SET value = '1' WHERE key = 'groups_locked'"); $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
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
