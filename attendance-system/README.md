# QR Code Laboratory Attendance Management System

A complete, self-contained PHP + MySQL + JavaScript attendance system for
school computer laboratories, built to run directly on **XAMPP** with no
external frameworks (no Composer, no Laravel/CodeIgniter — plain PHP,
prepared-statement MySQL, and vanilla JS/AJAX).

**v2 adds:** GPS geofencing (a scan only counts if the student's phone is
physically near the laboratory), administrator-controlled QR activation
(on top of the teacher's own control), rotating QR tokens (anti-screenshot),
and a full student-initiated / teacher-approved enrollment request workflow.

---

## ✨ Features

**Login**
- Role selector (Admin / Teacher / Student tabs) — the selected role must match
  the account being signed into, so no one can log in on the wrong portal
- Password = the user's own ID number (Admin ID / Employee No. / Student No.)

**Administrator**
- Dashboard with live stats, config alerts (pending requests, labs missing
  GPS setup), and Chart.js analytics (trend, status share, lab usage)
- Laboratory Status grid (green/red live indicator per lab)
- Student / Teacher / Subject / Laboratory CRUD (search, filter, pagination, modals)
  — Subjects table shows Code, Subject Name, **Teacher Assigned**, Units, Status, Actions
- **Laboratories now carry GPS coordinates + a geofence radius**, with a
  "Use My Current Location" button while standing in the room
- Class assignment management (teacher ⇄ subject ⇄ lab ⇄ schedule ⇄ course/year/capacity)
- **QR Attendance Management** — activate or deactivate the QR session for
  *any* class (choosing date/start/end/radius), alongside the teacher's own
  control; force-stop any live session
- **Settings page** — configurable max GPS accuracy, default geofence radius,
  and QR token rotation interval (no code edits needed)
- **Enrollment Requests** — system-wide view of every request, with the
  administrator able to **approve or reject directly** (same as the owning
  teacher can) — either party can act; whoever reviewed it is recorded and shown
- Attendance monitoring with multi-filter search
- Report builder with PDF export (print-to-PDF) and Excel export (.xls)
- Notification broadcast to teachers/students

**Teacher**
- Dashboard of assigned classes
- **My Assigned Subjects** — each class is clickable into a full detail hub:
  subject info, pending requests for that class, the enrolled roster, and a
  search box to enroll a student directly
- **Enrollment Requests** — approve or reject student requests (with a
  required reason on rejection); approving enrolls the student immediately
- Student enrollment management per class (with course/year filters + sorting)
- Activate / Deactivate a live QR attendance session (toggle switch)
- QR code **rotates automatically** every few seconds so a screenshot goes stale
- Live "who's scanned" roster (shows every enrolled student, Pending until they scan)
- Attendance history + dedicated "Late Students" report
- Notifications

**Student**
- Dashboard with attendance summary and quick links
- **Request Enrollment** (browse subjects) — browse open classes and request enrollment, reviewed by the teacher or an admin
  (auto-filled request form, optional remarks, live validation)
- **My Enrollment Requests** — track pending/approved/rejected status,
  including the teacher's rejection reason
- **My Subjects** — everything officially enrolled in
- **Scan Attendance** — must grant location access first, then scan; shows
  live location/accuracy/distance status and a full result screen
- Attendance history with filters
- Editable profile (contact number, photo, password)
- Notifications

**Attendance Rules (enforced server-side, in this order)**
1. QR token is cryptographically valid (HMAC-signed, `qr/qr_helper.php`)
2. Session is currently active
3. Session hasn't expired (`session_end`, auto-checked on every relevant page)
4. Student is enrolled in that specific subject
5. Location permission was granted (rejected otherwise)
6. GPS accuracy is within the configured maximum (default 100m)
7. Student is within the laboratory's geofence radius (server-side Haversine
   distance check — the browser's own claims are never trusted)
8. Student hasn't already scanned this session (DB unique constraint + app check)
9. Status = `Present` if scanned on time, `Late` if more than the configured
   threshold (default 15 min) after the class's scheduled start
10. Record inserted inside a DB transaction, with GPS evidence attached

---

## 🗂 Folder Structure

```
attendance-system/
├── admin/                Admin pages + matching ajax_*.php controllers
│   ├── qr_management.php    Admin-controlled QR activation/deactivation
│   ├── settings.php          GPS/rotation configuration
│   ├── enrollment_requests.php   System-wide request oversight
├── teacher/               Teacher pages + ajax controllers
│   ├── class_view.php        Per-class hub: info + roster + requests
│   ├── enrollment_requests.php   Approve/reject requests
├── student/                 Student pages + ajax controllers
│   ├── browse_subjects.php     Request Enrollment (browse + request modal)
│   ├── my_requests.php          My Enrollment Requests
│   ├── my_subjects.php           My Subjects
│   ├── scanner.php                 Location + QR scan UI
│   ├── ajax_scan.php                Core GPS+QR validation engine
├── assets/
│   ├── css/style.css        Full design system
│   └── js/app.js             Toasts, modal helpers, AJAX helpers
├── includes/
│   ├── config.php             DB credentials + app constants (EDIT THIS FIRST)
│   ├── db.php                  PDO connection
│   ├── auth.php                  Login / RBAC / session handling
│   ├── functions.php               Helpers + settings get/set + auto-expire
│   ├── geo.php                       Haversine distance / geofence check
│   ├── header.php / sidebar.php / footer.php   Shared layout
├── qr/
│   ├── qr_helper.php           Builds/validates signed QR payloads + rotation
│   ├── session_manager.php       Shared activate/deactivate (teacher + admin)
│   └── ajax_current_token.php      Polled to refresh the displayed QR
├── database/
│   ├── schema.sql                 Full DB schema + sample data (fresh installs)
│   ├── migration_v2_gps_qr_enrollment.sql   ALTERs a v1 database (GPS/QR/enrollment)
│   ├── migration_v3_admin_enrollment_approval.sql   ALTERs a v2 database (admin approvals)
│   ├── reset_passwords_to_id.php    Sets demo accounts' passwords to their IDs
│   └── generate_password.php         One-time bcrypt hash checker
├── reports/                        (reserved for saved report exports)
├── uploads/photos/                  Student/teacher profile photo uploads
├── index.php, login.php, logout.php
└── README.md                          You are here
```

---

## 🚀 Setup on XAMPP

### Fresh install
1. **Copy the project folder** into `htdocs`, e.g. `C:\xampp\htdocs\attendance-system\`.
2. **Start Apache and MySQL** from the XAMPP Control Panel.
3. **Import the database**: phpMyAdmin → **Import** → choose `database/schema.sql` → **Go**.
   This creates `qr_attendance_system` with sample data, including sample
   GPS coordinates for all 7 laboratories so geofencing works immediately.
4. **Set demo passwords to ID numbers**: visit
   `http://localhost/attendance-system/database/reset_passwords_to_id.php` once.
5. **Check `includes/config.php`** (defaults match a stock XAMPP install).
6. **Open** `http://localhost/attendance-system/`.

### Already have v1 or v2 installed?
Don't re-import `schema.sql` (that would wipe your data). Instead, in
phpMyAdmin → select `qr_attendance_system` → **SQL** tab, run whichever of
these you haven't applied yet (**in order**):
1. `database/migration_v2_gps_qr_enrollment.sql` — adds GPS/QR/enrollment tables.
2. `database/migration_v3_admin_enrollment_approval.sql` — lets admins approve/reject requests directly.

Each migration is safe to run once; running `schema.sql` fresh already
includes everything from both, so only existing installs need these.

### 🔧 Troubleshooting: "The QR code isn't showing up"
There are two independent things that can cause this — the app now tells
you which one it is directly on screen instead of failing silently:

**A red box saying "QR code could not load" appears where the QR should be.**
This means your network/browser can't reach any of the QR library CDNs
(`cdnjs.cloudflare.com`, `cdn.jsdelivr.net`, `unpkg.com`) — common on
restrictive school/campus firewalls. The app automatically tries all three
before giving up, so seeing this error means all three were blocked. Ask
your network administrator to allow those hosts, or try a different
network. The camera scanner (`student/scanner.php`) has the same kind of
fallback for its own library and will show an equivalent message.

**The QR box is just empty/blank with no error message at all.**
This means the *database* is missing a column/table the QR pages expect —
almost always because migration v2 (or v3) hasn't been run yet against an
existing database.
- Run the migrations above, in order, against your actual database.
- Then hard-refresh the page (Ctrl+Shift+R) and re-activate the session.
- If a toast reads "QR code is displayed but auto-refresh is unavailable:
  ...", the initial QR *did* render — only the background rotation check
  is failing (usually the same missing-migration cause). The code shown
  will keep working normally until you deactivate it.

### Demo accounts (password = ID number)
| Role    | Username   | Password (ID number) |
|---------|------------|-----------------------|
| Admin   | `admin`    | `ADM-0001`            |
| Teacher | `tcruz`    | `EMP-001`              |
| Teacher | `jsantos`  | `EMP-002`              |
| Student | `s2023001` | `2023-0001`            |
| Student | `s2023002` | `2023-0002`            |
| Student | `s2023003` | `2023-0003`            |
| Student | `s2023004` | `2023-0004` *(unenrolled — use to test enrollment requests)* |

> 🔒 Delete or move `database/reset_passwords_to_id.php` and
> `database/generate_password.php` once your accounts are set up.

**Before relying on geofencing for real**, replace the sample lab coordinates
with your actual room coordinates: **Admin → Laboratories → Edit → "Use My
Current Location"** while standing in each room.

---

## 🧪 Testing the Complete Workflow

### GPS + QR attendance
1. Log in as **admin** (`admin` / `ADM-0001`) → **QR Attendance Management** →
   pick a class → **Activate**. Or log in as **tcruz** / `EMP-001` →
   **Attendance Session** → flip the toggle.
2. Log in as **s2023001** / `2023-0001` (a different browser/incognito window,
   or your phone — see the HTTPS note below) → **Scan Attendance** →
   **Allow Location** → **Scan QR Code** → point the camera at the QR on the
   other screen.
3. You should see a full result screen with status, distance, and time.
   Try it again — the second scan should be rejected as a duplicate.
4. On a browser far from the sample coordinates (or after editing your OS/
   browser's simulated location to somewhere else), a scan should be rejected
   as "outside the allowed attendance area."

### Enrollment requests
1. Log in as **s2023004** / `2023-0004` → **Request Enrollment** → **Request
   Enrollment** on any class → fill in optional remarks → **Submit Request**.
2. Log in as the owning teacher → **Enrollment Requests** (sidebar badge
   shows the pending count) → **View** → **Approve** or **Reject** (reject
   requires a reason).
3. Back on the student account: **My Enrollment Requests** shows the new
   status (and the rejection reason, if rejected). If approved, the class
   now appears under **My Subjects** and the student can scan attendance
   for it.

### Admin oversight
- **Admin → Enrollment Requests** shows every request system-wide.
- **Admin → Settings** lets you tune GPS accuracy tolerance, default radius,
  and QR rotation interval without editing code.

---

## 📸 Camera & Location Permissions

Both the camera (QR scanning) and Geolocation API require a **secure
context**: `http://localhost/...` works fine on the same computer, but a
phone connecting over your local Wi-Fi via a plain `http://192.168.x.x` URL
will have both blocked by the browser. To test from a real phone:
- Use a tool like **ngrok** (`ngrok http 80`) to get an HTTPS URL, or
- On Android Chrome, add your local IP to
  `chrome://flags/#unsafely-treat-insecure-origin-as-secure` for local testing.

---

## 🧩 How the GPS + QR Attendance Flow Works

1. An admin or the class's teacher activates a session (`qr/session_manager.php`
   — shared logic so both entry points behave identically). This requires the
   laboratory to already have GPS coordinates configured.
2. A random 32-byte `qr_token` is generated and signed into a small JSON
   payload (`qr/qr_helper.php`); the display page polls
   `qr/ajax_current_token.php` every few seconds, which **rotates the token**
   once the configured interval has elapsed and returns the fresh payload —
   so a photographed QR code stops working shortly after.
3. The student's scanner requests location first (`navigator.geolocation`),
   then reads the QR via the camera, then POSTs both the raw QR text and the
   raw lat/lng/accuracy to `student/ajax_scan.php`.
4. The server — never the browser — makes every decision: re-verifies the
   signature, checks the session is active and unexpired, checks enrollment,
   checks GPS accuracy against the configured maximum, computes the
   **Haversine distance** to the laboratory's stored coordinates and compares
   it to the session's allowed radius, checks for a duplicate scan, then
   inserts the record (with the GPS evidence) inside a transaction.
5. The teacher's session page polls for live roster updates (Pending →
   Present/Late) every 4 seconds.
6. Either the teacher or an admin can deactivate the session early; it also
   auto-expires once its configured end time passes (checked lazily on
   relevant page loads — no cron job required).

---

## 🔐 Security Notes

- All queries use **PDO prepared statements** — no raw string concatenation
  of user input into SQL anywhere in the app.
- Passwords are hashed with **bcrypt** (`password_hash` / `password_verify`).
- Session ID is regenerated on login to mitigate session fixation.
- Role-based access control (`require_role()`) guards every protected page,
  and ownership is re-verified server-side on every teacher/admin action
  (a teacher cannot approve another teacher's request or view another
  teacher's class by editing the URL).
- QR payloads are HMAC-signed (`qr/qr_helper.php`) — change `QR_SECRET_KEY`
  in that file before any real deployment.
- GPS validation happens entirely server-side; client-reported coordinates
  are just input to a server-side Haversine calculation, never trusted directly.
- File uploads (profile photos) are restricted to `jpg/jpeg/png/webp`.

---

## 🛠 Tech Stack

- **Backend**: PHP 8 (PDO, prepared statements, password_hash)
- **Database**: MySQL / MariaDB (InnoDB, foreign keys, indexes)
- **Frontend**: HTML5, CSS3 (custom design system), vanilla JavaScript + `fetch` AJAX
- **Charts**: Chart.js (CDN)
- **QR generation**: qrcode.js (CDN) · **QR scanning**: html5-qrcode (CDN)
- **Geolocation**: browser Geolocation API + server-side Haversine formula
- **PDF export**: browser print-to-PDF · **Excel export**: native `.xls` stream

No Composer, no Node build step — everything runs as-is once dropped into
`htdocs` and the database is imported.
