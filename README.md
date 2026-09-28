# Class Groups

A small PHP and SQLite portal for a class of up to 40 students. Students register with a name, roll number and PIN, answer skill questions, and choose from ten pre-created groups. Groups have up to four members and occupied groups must have at least two before an admin can lock selection.

## Deploy to Hostinger

1. Use hosting that runs PHP 8.1 or later with **PDO SQLite** and **mbstring** enabled. The site needs writable, persistent files. A static site plan will not work.
2. Upload the contents of this repository into the domain's `public_html` directory, preserving the `private` folder and both `.htaccess` files.
3. Open `/hosting-check.php` on the new domain. Every item must say **Pass**. If PDO SQLite is missing, the current portal cannot run on that account. The check creates the live database in `private/portal.sqlite` from the starter database in `seed/portal.sqlite`. PHP must be able to write to the `private` directory.
4. Open the domain. The first visit leads to the one-time admin setup page. Enter the separate setup code supplied by the project owner, then create a strong admin password. The setup code itself is not in GitHub.
5. Check that `https://your-domain/private/portal.sqlite` returns **403 Forbidden**. If it downloads, stop using the portal until the hosting server is configured to block access to `private`.
6. Delete `hosting-check.php` from the server after these checks pass.

The repository includes a fresh, empty SQLite **starter** database with the default questions. It does **not** contain an admin password or student data. The live `private/portal.sqlite` is excluded from Git so ordinary code updates do not replace it. After launch, keep backups of the live database outside the web directory. Changing or disconnecting the Git deployment can still affect files in `public_html`; back up the live database before doing either. Do not commit a live database containing student records or admin password hashes to a public GitHub repository.

## How it works

- Students can register while selection is open, see who is in every group, join groups with open places, switch groups, leave groups, and edit their skill answers.
- Students sign back in using their roll number and PIN.
- An admin can add students with a temporary PIN and optionally assign a group, add another admin, add or hide questions, move students, and lock or unlock group selection. Admin-added students sign in with their roll number and PIN and complete their own skills questionnaire.
- The portal provides Group 1 through Group 10 from the start. Empty groups remain available. Existing custom groups are renamed in order when an older live database upgrades, preserving their members.
- Locking requires every registered student to be assigned and every occupied group to have 2–4 members. Empty pre-created groups do not block locking. Once locked, everyone can view groups but no one can change membership until an admin unlocks them. Registration is also closed while locked.

## Local development

Run `python create_database.py` to create a new empty database and one-time setup code. This refuses to overwrite an existing database unless passed `--replace`, which **erases all portal data**. Then run `php -S localhost:8000` from this folder. PHP must have the `pdo_sqlite` extension enabled.
