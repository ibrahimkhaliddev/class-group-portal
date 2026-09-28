import re
import requests
from pathlib import Path

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

assert 'Groups are locked' in post(admin, 'admin', 'lock', {})
assert 'Groups are locked by an admin' in post(students[0], 'groups', 'leave', {})
assert 'Groups are locked' in post(admin, 'admin', 'move-student', {'student_id':'6','group_id':'1'})
assert 'Group selection is open again' in post(admin, 'admin', 'unlock', {})
assert 'This group is full' in post(admin, 'admin', 'move-student', {'student_id':'6','group_id':'1'})
assert 'Group 2' in page(students[5], 'groups')
print('Setup, admin creation, registration, grouping, capacity, lock, unlock passed.')
