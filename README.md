# Class Groups

A small PHP and SQLite portal for a class of up to 40 students. Students register with a name, roll number and PIN, answer skill questions, and choose their own group. Groups have up to four members and must have at least two before an admin can lock selection.

## Deploy to Hostinger

1. Use hosting that runs PHP 8.1 or later with **PDO SQLite** and **mbstring** enabled. The site needs writable, persistent files. A static site plan will not work.
2. Upload the contents of this repository into the domain's `public_html` directory, preserving the `private` folder and both `.htaccess` files.
3. Open `/hosting-check.php` on the new domain. Every item must say **Pass**. If PDO SQLite is missing, the current portal cannot run on that account. Make sure PHP can write to `private/portal.sqlite` and the `private` directory. Do not replace the database file when deploying updates after students have registered.
4. Open the domain. The first visit leads to the one-time admin setup page. Enter the separate setup code supplied by the project owner, then create a strong admin password. The setup code itself is not in GitHub.
5. Check that `https://your-domain/private/portal.sqlite` returns **403 Forbidden**. If it downloads, stop using the portal until the hosting server is configured to block access to `private`.
6. Delete `hosting-check.php` from the server after these checks pass.

The repository includes a fresh, empty SQLite database with the default questions. It does **not** contain an admin password or student data. After launch, keep backups of `private/portal.sqlite` outside the web directory. Do not commit a live database containing student records or admin password hashes back to a public GitHub repository.

## How it works

- Students can register while selection is open, create groups, join groups with open places, switch groups, leave groups, and edit their skill answers.
- Students sign back in using their roll number and PIN.
- An admin can add another admin, add or hide questions, move students, and lock or unlock group selection.
- Locking requires every registered student to be assigned and every group to have 2–4 members. Once locked, everyone can view groups but no one can change membership until an admin unlocks them. Registration is also closed while locked.
- Empty groups are removed automatically when their last member leaves.

## Local development

Run `python create_database.py` to create a new empty database and one-time setup code. This refuses to overwrite an existing database unless passed `--replace`, which **erases all portal data**. Then run `php -S localhost:8000` from this folder. PHP must have the `pdo_sqlite` extension enabled.
