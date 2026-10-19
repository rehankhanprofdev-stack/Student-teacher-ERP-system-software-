# Put the Maulana Azad College Portal online for free (InfinityFree)

1. Sign up at infinityfree.com and create a hosting account (pick a free subdomain, e.g. yourname.infinityfree.me).
2. In the control panel open **MySQL Databases** and create a database. Write down:
   host (like sql123.infinityfree.com), database name (like if0_12345678_campusos), username, password.
3. Open `includes/config.php` on your computer and replace the defaults:
   - `db_host`, `db_name`, `db_user`, `db_pass` with the values above
   - `demo_mode` => false
   - `college` => your college name
4. Upload everything inside the `campusos` folder into the **htdocs** folder of your hosting account
   (Online File Manager, or FTP with FileZilla). `index.php` must be directly inside htdocs.
5. Visit `https://yourname.infinityfree.me/install.php` once. You should see "Tables created" and "Demo data added".
6. Delete `install.php` from htdocs.
7. Sign in as admin@campus.test / admin123, then **immediately** go to Users and:
   - add your own admin account, then delete the demo accounts (or reset every demo password).
8. Share the link with your class.

Backups: in the control panel open phpMyAdmin, select the database, Export, and keep the .sql file.

Free-host limits: it can be slow, may sleep or show an ad/notice page, and is not suitable for real
student records. Use it for demos and projects only.
