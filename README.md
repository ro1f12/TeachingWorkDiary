# Teaching & Work Diary — Professional Build

PHP 8.2 + MySQL/MariaDB + Bootstrap-style responsive UI for XAMPP.

## Installation
1. Copy this folder to `C:\xampp\htdocs\TeachingWorkDiary`.
2. Start Apache and MySQL in XAMPP.
3. Open `http://localhost/TeachingWorkDiary/install.php`.
4. Create/import the database using the installer.
5. Login with the seeded administrator credentials shown by the installer.
6. After installation, rename/delete `install.php`.

The application deliberately keeps timetable records separate from diary records. Timetable entries are templates; materialized diary records preserve the original scheduled teacher/date/time while allowing actual values to be edited.

## PDF dependencies
The project declares Dompdf and PHPMailer in `composer.json`. Run `composer install` in the project folder when internet/package access is available. The web application works for browser printing without Composer, while PDF/email functions use the libraries when installed.


Masters are editable: Teachers, Programmes, Semesters, Papers, Rooms/Labs, and Class Strength. Holidays are also fully editable. The 2026 Gauhati University holiday calendar is pre-seeded; multi-day official holidays are stored date-by-date. Ambubasi Nibriti is not seeded because the GU notice does not provide its exact Gregorian date in the published 2026 list.


Holiday behavior: official and local holidays are treated as college closure dates by the diary materializer. Restricted holidays remain editable reference records and do not automatically mark scheduled classes as holidays, because the GU notice treats them as optional restricted holidays.
