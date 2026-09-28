<?php
declare(strict_types=1);
session_start(['cookie_httponly' => true, 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
require __DIR__ . '/app.php';
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$page = (string)($_GET['page'] ?? 'home');
$allowed = ['home','register','student-login','admin-login','setup','groups','profile','admin','admins','questions'];
if (!in_array($page,$allowed,true)) $page = 'home';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { handle_post(); } catch (Throwable $e) { flash($e instanceof PDOException ? 'That value is already in use, or the database could not save it.' : $e->getMessage(), 'error'); go($page); }
}
$hasAdmin = (int)one('SELECT COUNT(*) AS n FROM admins')['n'] > 0;
if (!$hasAdmin && $page !== 'setup') go('setup');
if ($hasAdmin && $page === 'setup') go('home');
if (in_array($page,['groups','profile'],true)) $currentStudent = require_student();
if (in_array($page,['admin','admins','questions'],true)) $currentAdmin = require_admin();
$notice = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$titles = ['home'=>'Find your group','register'=>'Get started','student-login'=>'Student sign in','admin-login'=>'Admin sign in','setup'=>'Set up the portal','groups'=>'Choose your group','profile'=>'Your skills','admin'=>'Overview','admins'=>'Admins','questions'=>'Questions'];
function form_start(string $action, string $page, string $class = ''): void { echo '<form method="post" action="'.h(url($page)).'" class="'.h($class).'">'.csrf().'<input type="hidden" name="action" value="'.h($action).'">'; }
function member_label(array $member): string { return $member['name'] . ' · ' . $member['roll_number']; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($titles[$page]) ?> · Class Groups</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header"><div class="wrap header-inner">
  <a class="brand" href="<?= h(url('home')) ?>"><span class="brand-mark">CG</span><span>Class Groups</span></a>
  <nav aria-label="Main navigation">
    <?php if (student()): ?><a href="<?= h(url('groups')) ?>">Groups</a><a href="<?= h(url('profile')) ?>">My skills</a>
    <?php elseif (admin()): ?><a href="<?= h(url('admin')) ?>">Overview</a><a href="<?= h(url('questions')) ?>">Questions</a><a href="<?= h(url('admins')) ?>">Admins</a>
    <?php else: ?><a href="<?= h(url('student-login')) ?>">Student sign in</a><a href="<?= h(url('admin-login')) ?>">Admin</a><?php endif; ?>
    <?php if (student() || admin()): ?><?php form_start('logout',$page,'nav-form'); ?><button type="submit" class="text-button">Sign out</button></form><?php endif; ?>
  </nav>
</div></header>
<main class="wrap">
<?php if ($notice): ?><div class="notice <?= h($notice[0]) ?>" role="status"><?= h($notice[1]) ?></div><?php endif; ?>

<?php if ($page === 'home'): ?>
  <section class="hero"><div class="eyebrow">YOUR CLASS, YOUR CHOICE</div><h1>Find your group.</h1><p>Tell your classmates what you’re good at, then create or join a group. Each group has 2 to 4 members.</p>
    <div class="hero-actions"><a class="button" href="<?= h(url('register')) ?>">Get started <span aria-hidden="true">→</span></a><a class="button secondary" href="<?= h(url('student-login')) ?>">I already joined</a></div>
  </section>
  <section class="steps"><div><span class="step-num">01</span><h2>Set up your profile</h2><p>Enter your name and roll number.</p></div><div><span class="step-num">02</span><h2>Share your skills</h2><p>Answer a few quick questions.</p></div><div><span class="step-num">03</span><h2>Choose a group</h2><p>Join classmates or start a new group.</p></div></section>
<?php elseif ($page === 'setup'): ?>
  <div class="narrow"><div class="page-intro"><div class="eyebrow">ONE-TIME SETUP</div><h1>Create the first admin</h1><p>This account will manage the class. Keep its password somewhere safe.</p></div><div class="card">
    <?php form_start('setup','setup','stack'); ?><label>Setup code<input name="setup_code" autocomplete="off" required><small>Use the one-time code provided with the project.</small></label><label>Username<input name="username" autocomplete="username" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9._-]+"></label><label>Password<input type="password" name="password" autocomplete="new-password" required minlength="10"></label><button class="button" type="submit">Create admin account</button></form>
  </div></div>
<?php elseif ($page === 'register'): ?>
  <div class="narrow"><div class="page-intro"><div class="eyebrow">STEP 1 OF 2</div><h1>Tell us about yourself</h1><p>These answers help classmates see the skills you bring to a group.</p></div><div class="card">
    <?php form_start('student-register','register','stack'); ?><div class="field-pair"><label>Full name<input name="name" autocomplete="name" required minlength="2" maxlength="80" placeholder="Your full name"></label><label>Roll number<input name="roll" required maxlength="30" placeholder="e.g. 1234"></label></div><label>Choose a 4–8 digit PIN<input name="pin" type="password" inputmode="numeric" autocomplete="new-password" required minlength="4" maxlength="8" pattern="[0-9]{4,8}"><small>Use this PIN to sign in later and manage your group.</small></label>
    <div class="form-divider"></div><h2>Your skills</h2><p class="muted tight">How comfortable are you with each area?</p>
    <?php foreach (question_rows() as $q): ?><fieldset class="question"><legend><?= h($q['label']) ?></legend><div class="choice-row"><?php foreach (LEVELS as $i=>$level): ?><label class="choice"><input type="radio" name="answer[<?= (int)$q['id'] ?>]" value="<?= $i ?>" required><span><?= h($level) ?></span></label><?php endforeach; ?></div></fieldset><?php endforeach; ?>
    <button class="button" type="submit">Continue to groups <span aria-hidden="true">→</span></button></form>
  </div><p class="under-card">Already registered? <a href="<?= h(url('student-login')) ?>">Sign in</a></p></div>
<?php elseif ($page === 'student-login' || $page === 'admin-login'): ?>
  <?php $isAdminLogin = $page === 'admin-login'; ?><div class="narrow"><div class="page-intro"><div class="eyebrow"><?= $isAdminLogin ? 'ADMIN ACCESS' : 'WELCOME BACK' ?></div><h1><?= $isAdminLogin ? 'Admin sign in' : 'Student sign in' ?></h1><p><?= $isAdminLogin ? 'Manage your class and group selection.' : 'Return to your group and update your skills.' ?></p></div><div class="card">
  <?php form_start($page,$page,'stack'); ?><?php if ($isAdminLogin): ?><label>Username<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><?php else: ?><label>Roll number<input name="roll" required autocomplete="username"></label><label>PIN<input type="password" name="pin" inputmode="numeric" autocomplete="current-password" required></label><?php endif; ?><button class="button" type="submit">Sign in</button></form></div>
  <?php if (!$isAdminLogin): ?><p class="under-card">New here? <a href="<?= h(url('register')) ?>">Create your profile</a></p><?php endif; ?></div>
<?php elseif ($page === 'groups'): ?>
  <?php $groups = group_rows(); $myGroup = $currentStudent['group_id'] ? (int)$currentStudent['group_id'] : null; ?>
  <div class="page-intro split-intro"><div><div class="eyebrow">STEP 2 OF 2</div><h1>Choose your group</h1><p>Welcome, <?= h($currentStudent['name']) ?>. Find classmates to work with.</p></div><span class="status <?= locked() ? 'locked' : 'open' ?>"><?= locked() ? 'Group selection locked' : 'Group selection open' ?></span></div>
  <?php if ($myGroup): $mine = one('SELECT * FROM groups WHERE id = ?',[$myGroup]); ?><div class="current-group"><div><div class="small-label">YOUR GROUP</div><h2><?= h($mine['name']) ?></h2><p>You can see your group members below.</p></div><?php if (!locked()): ?><?php form_start('leave','groups'); ?><button class="button secondary" type="submit">Leave group</button></form><?php endif; ?></div><?php endif; ?>
  <?php if (!$myGroup && !locked()): ?><div class="create-box"><div><h2>Start a new group</h2><p>Name it so classmates can find it.</p></div><?php form_start('create-group','groups','inline-form'); ?><label class="sr-only" for="group-name">Group name</label><input id="group-name" name="group_name" placeholder="Group name" required minlength="3" maxlength="40"><button class="button" type="submit">Create group</button></form></div><?php endif; ?>
  <div class="section-heading"><div><h2>All groups</h2><p><?= count($groups) ?> <?= count($groups) === 1 ? 'group' : 'groups' ?> so far</p></div></div>
  <?php if (!$groups): ?><div class="empty-state">No groups yet. Be the first to create one.</div><?php else: ?><div class="group-grid"><?php foreach ($groups as $g): ?>
    <article class="group-card <?= $myGroup === (int)$g['id'] ? 'is-mine' : '' ?>"><div class="group-top"><div><div class="small-label">GROUP <?= (int)$g['id'] ?></div><h3><?= h($g['name']) ?></h3></div><span class="seats <?= (int)$g['member_count'] >= 4 ? 'full' : '' ?>"><?= (int)$g['member_count'] ?>/4</span></div>
    <div class="seat-track"><span style="width:<?= 25*(int)$g['member_count'] ?>%"></span></div><ul class="member-list"><?php foreach (rows('SELECT name,roll_number,id FROM students WHERE group_id = ? ORDER BY name',[(int)$g['id']]) as $m): ?><li><span class="avatar"><?= h(mb_strtoupper(mb_substr($m['name'],0,1))) ?></span><span><?= h($m['name']) ?><?php if ($m['id'] == $currentStudent['id']): ?> <em>(you)</em><?php endif; ?><small><?= h(skill_summary((int)$m['id'])) ?></small></span></li><?php endforeach; ?></ul>
    <div class="group-foot"><?php if ($myGroup === (int)$g['id']): ?><span class="joined">✓ Your group</span><?php elseif (!locked() && (int)$g['member_count'] < 4): ?><?php form_start('join','groups'); ?><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>"><button class="button secondary small" type="submit"><?= $myGroup ? 'Switch to this group' : 'Join group' ?></button></form><?php else: ?><span class="muted"><?= locked() ? 'Selection closed' : 'Group is full' ?></span><?php endif; ?></div>
    </article><?php endforeach; ?></div><?php endif; ?>
<?php elseif ($page === 'profile'): ?>
  <div class="narrow"><div class="page-intro"><div class="eyebrow">YOUR PROFILE</div><h1>Your skills</h1><p>Update your answers while group selection is open.</p></div><div class="card"><div class="identity"><div class="avatar large"><?= h(mb_strtoupper(mb_substr($currentStudent['name'],0,1))) ?></div><div><strong><?= h($currentStudent['name']) ?></strong><small><?= h($currentStudent['roll_number']) ?></small></div></div>
    <?php form_start('save-answers','profile','stack'); ?><?php foreach (question_rows() as $q): $answer = one('SELECT level FROM answers WHERE student_id = ? AND question_id = ?',[(int)$currentStudent['id'],(int)$q['id']]); ?><fieldset class="question"><legend><?= h($q['label']) ?></legend><div class="choice-row"><?php foreach (LEVELS as $i=>$level): ?><label class="choice"><input type="radio" name="answer[<?= (int)$q['id'] ?>]" value="<?= $i ?>" <?= (int)($answer['level'] ?? -1) === $i ? 'checked' : '' ?> <?= locked() ? 'disabled' : '' ?> required><span><?= h($level) ?></span></label><?php endforeach; ?></div></fieldset><?php endforeach; ?><?php if (!locked()): ?><button class="button" type="submit">Save changes</button><?php else: ?><p class="muted">Skills are read-only while groups are locked.</p><?php endif; ?></form>
  </div></div>
<?php elseif ($page === 'admin'): ?>
  <?php $students = rows('SELECT s.*, g.name AS group_name FROM students s LEFT JOIN groups g ON g.id=s.group_id ORDER BY s.name'); $groups = group_rows(); $ungrouped = count(array_filter($students,fn($s)=>$s['group_id']===null)); $small = count(array_filter($groups,fn($g)=>(int)$g['member_count']<2)); ?>
  <div class="page-intro split-intro"><div><div class="eyebrow">ADMIN DASHBOARD</div><h1>Class overview</h1><p>Manage group selection and see who still needs a group.</p></div><span class="status <?= locked() ? 'locked' : 'open' ?>"><?= locked() ? 'Groups locked' : 'Selection open' ?></span></div>
  <div class="stats"><div><strong><?= count($students) ?><span>/40</span></strong><small>Students registered</small></div><div><strong><?= count($groups) ?></strong><small>Groups created</small></div><div><strong><?= $ungrouped ?></strong><small>Without a group</small></div><div><strong><?= $small ?></strong><small>Groups under 2</small></div></div>
  <div class="admin-lock"><div><h2><?= locked() ? 'Groups are locked' : 'Ready to lock groups?' ?></h2><p><?= locked() ? 'Group membership is read-only for everyone. Unlock to make changes.' : 'Every registered student needs a group, and each group needs 2–4 members.' ?></p></div><?php form_start(locked()?'unlock':'lock','admin'); ?><button class="button <?= locked() ? 'secondary' : '' ?>" type="submit"><?= locked() ? 'Unlock groups' : 'Lock groups' ?></button></form></div>
  <div class="section-heading"><div><h2>Groups</h2><p>Current members and available spaces</p></div></div><div class="admin-group-list"><?php if (!$groups): ?><div class="empty-state">No groups have been created yet.</div><?php endif; ?><?php foreach ($groups as $g): ?><div class="admin-group"><div><strong><?= h($g['name']) ?></strong><small><?= h(implode(', ',array_column(rows('SELECT name FROM students WHERE group_id = ? ORDER BY name',[(int)$g['id']]),'name'))) ?></small></div><span class="seats <?= (int)$g['member_count'] >= 4 ? 'full' : '' ?>"><?= (int)$g['member_count'] ?>/4</span></div><?php endforeach; ?></div>
  <div class="section-heading"><div><h2>Students</h2><p>Move a student if a group needs an adjustment</p></div></div><div class="table-wrap"><table><thead><tr><th>Student</th><th>Roll number</th><th>Group</th><th>Change group</th></tr></thead><tbody><?php foreach ($students as $s): ?><tr><td><strong><?= h($s['name']) ?></strong></td><td><?= h($s['roll_number']) ?></td><td><?= $s['group_name'] ? h($s['group_name']) : '<span class="pill">Not assigned</span>' ?></td><td><?php if (locked()): ?><span class="muted">Unlock to edit</span><?php else: ?><?php form_start('move-student','admin','table-form'); ?><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>"><label class="sr-only" for="move-<?= (int)$s['id'] ?>">Group for <?= h($s['name']) ?></label><select id="move-<?= (int)$s['id'] ?>" name="group_id"><option value="">No group</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$s['group_id'] === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?> (<?= (int)$g['member_count'] ?>/4)</option><?php endforeach; ?></select><button type="submit" class="button secondary small">Save</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$students): ?><div class="empty-state">No students have registered yet.</div><?php endif; ?></div>
<?php elseif ($page === 'admins'): ?>
  <div class="page-intro"><div class="eyebrow">ACCESS</div><h1>Admins</h1><p>Add another admin to help manage the class.</p></div><div class="two-col"><div class="card"><h2>Add an admin</h2><?php form_start('add-admin','admins','stack'); ?><label>Username<input name="username" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9._-]+"></label><label>Temporary password<input name="password" type="password" required minlength="10" autocomplete="new-password"><small>Share this password with the new admin directly.</small></label><button class="button" type="submit">Add admin</button></form></div><div class="card"><h2>Current admins</h2><ul class="simple-list"><?php foreach (rows('SELECT username,created_at FROM admins ORDER BY id') as $a): ?><li><span class="avatar"><?= h(strtoupper(substr($a['username'],0,1))) ?></span><span><strong><?= h($a['username']) ?></strong><small>Added <?= h(substr($a['created_at'],0,10)) ?></small></span></li><?php endforeach; ?></ul></div></div>
<?php elseif ($page === 'questions'): ?>
  <div class="page-intro"><div class="eyebrow">QUESTIONNAIRE</div><h1>Skill questions</h1><p>Students choose one confidence level for each active question.</p></div><div class="two-col"><div class="card"><h2>Add a question</h2><?php form_start('add-question','questions','stack'); ?><label>Skill or topic<input name="label" required minlength="3" maxlength="90" placeholder="e.g. Data analysis"></label><button class="button" type="submit">Add question</button></form></div><div class="card"><h2>All questions</h2><ul class="question-list"><?php foreach (rows('SELECT * FROM questions ORDER BY position,id') as $q): ?><li><span><?= h($q['label']) ?><small><?= $q['active'] ? 'Active' : 'Hidden from new responses' ?></small></span><?php form_start('toggle-question','questions'); ?><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>"><button class="text-button" type="submit"><?= $q['active'] ? 'Hide' : 'Show' ?></button></form></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
</main><footer class="site-footer"><div class="wrap">Class Groups <span>·</span> A simple place to get organized.</div></footer>
</body></html>
