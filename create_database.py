"""Create a fresh portal database and a one-time setup code (for development only)."""

import hashlib
import pathlib
import secrets
import sqlite3

ROOT = pathlib.Path(__file__).resolve().parent
DB = ROOT / 'seed' / 'portal.sqlite'

if DB.exists():
    raise SystemExit('Starter database already exists. No files were changed.')
DB.parent.mkdir(exist_ok=True)
code = secrets.token_urlsafe(24)
with sqlite3.connect(DB) as connection:
    connection.executescript((ROOT / 'schema.sql').read_text(encoding='utf-8'))
    connection.execute('INSERT INTO settings(key,value) VALUES(?,?)', ('setup_code_hash', hashlib.sha256(code.encode()).hexdigest()))

(ROOT / '.setup-code.txt').write_text(code + '\n', encoding='utf-8')
print('Created fresh starter database. The one-time admin setup code is in .setup-code.txt (excluded from Git).')
