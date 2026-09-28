import re
import requests
from pathlib import Path
import sqlite3

BASE = 'http://127.0.0.1:8123/index.php?page='

def page(session, name):
    response = session.get(BASE + name)
    assert response.status_code == 200, (name, response.status_code)
    return response.text

def post(session, name, action, fields):
    html = page(session, name)
    token = re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
    response = session.post(BASE + name, data={'csrf': token, 'action': action, **fields}, allow_redirects=True)
    assert response.status_code == 200, (action, response.status_code)
    return response.text

admin = requests.Session()
assert 'Create the first admin' in page(admin, 'home')
assert 'Class overview' in post(admin, 'setup', 'setup', {'setup_code':Path('.setup-code.txt').read_text().strip(),'username':'classadmin','password':'StrongPass1234!'})
assert 'Admin added' in post(admin, 'admins', 'add-admin', {'username':'helper','password':'AnotherPass1234!'})

students = []
for i in range(1, 7):
    s = requests.Session()
    html = post(s, 'register', 'student-register', {
        'name': f'Student {i}', 'roll': f'ROLL{i:02}', 'pin':'123456',
        **{f'answer[{q}]':str(i%4) for q in range(1,9)},
    })
    assert 'Choose your group' in html, (i, html[:300])
    students.append(s)

assert 'Group 1' in page(students[0], 'groups')
assert 'Group 10' in page(students[0], 'groups')
for i, group in [(0,1),(1,1),(2,1),(3,1),(4,2),(5,2)]:
    assert 'You joined the group' in post(students[i], 'groups', 'join', {'group_id':str(group)})

assert 'Student 2' in page(students[4], 'groups')
assert 'You joined the group' in post(students[1], 'groups', 'join', {'group_id':'2'})
assert 'You joined the group' in post(students[1], 'groups', 'join', {'group_id':'1'})
assert 'Personal details' in page(students[0], 'profile')
assert 'This roll number is already registered' in post(students[0], 'profile', 'save-details', {'name':'Wrong Change','roll':'ROLL02'})
assert 'Student 1' in page(students[0], 'profile')
assert 'Your details were updated' in post(students[0], 'profile', 'save-details', {'name':'Corrected Student','roll':'NEW01'})
assert 'Corrected Student' in page(students[1], 'groups')
with sqlite3.connect('private/portal.sqlite') as db:
    student_id, saved_group = db.execute('SELECT id,group_id FROM students WHERE roll_number=? AND name=?', ('NEW01','Corrected Student')).fetchone()
    saved_answers = db.execute('SELECT COUNT(*) FROM answers WHERE student_id=?', (student_id,)).fetchone()[0]
assert saved_group == 1 and saved_answers == 8
assert 'Roll number or PIN is incorrect' in post(requests.Session(), 'student-login', 'student-login', {'roll':'ROLL01','pin':'123456'})
assert 'Corrected Student' in post(requests.Session(), 'student-login', 'student-login', {'roll':'NEW01','pin':'123456'})

assert 'Groups are locked' in post(admin, 'admin', 'lock', {})
assert 'Your details were updated' in post(students[0], 'profile', 'save-details', {'name':'Corrected Student Again','roll':'NEW02'})
with sqlite3.connect('private/portal.sqlite') as db:
    locked_group = db.execute('SELECT group_id FROM students WHERE id=? AND roll_number=?', (student_id,'NEW02')).fetchone()
    locked_answers = db.execute('SELECT COUNT(*) FROM answers WHERE student_id=?', (student_id,)).fetchone()[0]
assert locked_group == (1,) and locked_answers == saved_answers
assert 'Groups are locked by an admin' in post(students[0], 'groups', 'leave', {})
assert 'Groups are locked' in post(admin, 'admin', 'move-student', {'student_id':'6','group_id':'1'})
assert 'Unlock groups before adding a student' in post(admin, 'admin', 'add-student', {'name':'Added Student','roll':'ADDED01','pin':'123456','group_id':'3'})
assert 'Group selection is open again' in post(admin, 'admin', 'unlock', {})
assert 'This group is full' in post(admin, 'admin', 'move-student', {'student_id':'6','group_id':'1'})
assert 'Group 2' in page(students[5], 'groups')

assert 'Student added' in post(admin, 'admin', 'add-student', {'name':'Added Student','roll':'ADDED01','pin':'123456','group_id':'3'})
added = requests.Session()
assert 'Please complete your skills questionnaire' in post(added, 'student-login', 'student-login', {'roll':'ADDED01','pin':'123456'})
assert 'Group 3' in post(added, 'profile', 'save-answers', {f'answer[{q}]':'2' for q in range(1,9)})
assert 'This roll number is already registered' in post(admin, 'admin', 'add-student', {'name':'Duplicate Student','roll':'ADDED01','pin':'123456','group_id':''})

with sqlite3.connect('private/portal.sqlite') as db:
    added_id, previous_group = db.execute('SELECT id,group_id FROM students WHERE roll_number=?', ('ADDED01',)).fetchone()
    answer_count = db.execute('SELECT COUNT(*) FROM answers WHERE student_id=?', (added_id,)).fetchone()[0]
assert answer_count == 8
assert 'Student removed from the active class' in post(admin, 'admin', 'archive-student', {'student_id':str(added_id)})
with sqlite3.connect('private/portal.sqlite') as db:
    archived_at, saved_group = db.execute('SELECT archived_at,group_id FROM students WHERE id=?', (added_id,)).fetchone()
    saved_answers = db.execute('SELECT COUNT(*) FROM answers WHERE student_id=?', (added_id,)).fetchone()[0]
assert archived_at is not None and saved_group == previous_group and saved_answers == answer_count
assert 'Roll number or PIN is incorrect' in post(requests.Session(), 'student-login', 'student-login', {'roll':'ADDED01','pin':'123456'})
assert 'Student restored' in post(admin, 'admin', 'restore-student', {'student_id':str(added_id)})
assert 'Group 3' in post(requests.Session(), 'student-login', 'student-login', {'roll':'ADDED01','pin':'123456'})
print('Setup, registration, profile edits, groups, lock, admin-added student, reversible removal, and retained answers passed.')
