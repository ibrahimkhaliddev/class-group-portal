PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    roll_number TEXT NOT NULL UNIQUE COLLATE NOCASE,
    pin_hash TEXT NOT NULL,
    group_id INTEGER REFERENCES groups(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL,
    position INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1))
);

CREATE TABLE IF NOT EXISTS answers (
    student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    level INTEGER NOT NULL CHECK(level BETWEEN 0 AND 3),
    PRIMARY KEY(student_id, question_id)
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    identity TEXT PRIMARY KEY,
    failures INTEGER NOT NULL,
    locked_until INTEGER NOT NULL DEFAULT 0
);

INSERT OR IGNORE INTO settings(key,value) VALUES('groups_locked','0');

INSERT INTO questions(label,position) SELECT 'MS Word and formatting',1 WHERE NOT EXISTS (SELECT 1 FROM questions);
INSERT INTO questions(label,position) SELECT 'Writing and documentation',2 WHERE (SELECT COUNT(*) FROM questions)=1;
INSERT INTO questions(label,position) SELECT 'Presentations and public speaking',3 WHERE (SELECT COUNT(*) FROM questions)=2;
INSERT INTO questions(label,position) SELECT 'Research and finding information',4 WHERE (SELECT COUNT(*) FROM questions)=3;
INSERT INTO questions(label,position) SELECT 'Planning and managing assignments',5 WHERE (SELECT COUNT(*) FROM questions)=4;
INSERT INTO questions(label,position) SELECT 'Spreadsheets and data',6 WHERE (SELECT COUNT(*) FROM questions)=5;
INSERT INTO questions(label,position) SELECT 'Design and visual work',7 WHERE (SELECT COUNT(*) FROM questions)=6;
INSERT INTO questions(label,position) SELECT 'Coding and technical work',8 WHERE (SELECT COUNT(*) FROM questions)=7;
