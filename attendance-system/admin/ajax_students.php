<?php
/**
 * admin/ajax_students.php
 * Handles create / update / delete of student records via AJAX.
 * Always returns JSON: { success: bool, message: string }
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $studentNumber = clean($_POST['student_number'] ?? '');
        $fullName      = clean($_POST['full_name'] ?? '');
        $programId     = $_POST['program_id'] !== '' ? (int) $_POST['program_id'] : null;
        $yearLevel     = (int) ($_POST['year_level'] ?? 1);
        $email         = clean($_POST['email'] ?? '');
        $contact       = clean($_POST['contact_number'] ?? '');
        $username      = clean($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $status        = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($studentNumber === '' || $fullName === '' || $email === '' || $username === '' || $password === '') {
            throw new Exception('Please fill in all required fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please provide a valid email address.');
        }

        $pdo->beginTransaction();

        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
        $check->execute([$username, $email]);
        if ($check->fetchColumn() > 0) throw new Exception('Username or email already in use.');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ins = $pdo->prepare('INSERT INTO users (username, password, role, email, status) VALUES (?, ?, "student", ?, ?)');
        $ins->execute([$username, $hash, $email, $status]);
        $userId = $pdo->lastInsertId();

        $ins2 = $pdo->prepare('INSERT INTO students (user_id, student_number, full_name, program_id, year_level, contact_number) VALUES (?, ?, ?, ?, ?, ?)');
        $ins2->execute([$userId, $studentNumber, $fullName, $programId, $yearLevel, $contact]);

        create_notification($pdo, $userId, 'Welcome!', "Your student account has been created. Username: $username");
        log_activity($pdo, $_SESSION['user_id'], "Added student: $fullName");

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Student added successfully.']);

    } elseif ($action === 'update') {
        $studentId     = (int) ($_POST['student_id'] ?? 0);
        $studentNumber = clean($_POST['student_number'] ?? '');
        $fullName      = clean($_POST['full_name'] ?? '');
        $programId     = $_POST['program_id'] !== '' ? (int) $_POST['program_id'] : null;
        $yearLevel     = (int) ($_POST['year_level'] ?? 1);
        $email         = clean($_POST['email'] ?? '');
        $contact       = clean($_POST['contact_number'] ?? '');
        $username      = clean($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $status        = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (!$studentId || $studentNumber === '' || $fullName === '' || $email === '' || $username === '') {
            throw new Exception('Please fill in all required fields.');
        }

        $find = $pdo->prepare('SELECT user_id FROM students WHERE student_id = ?');
        $find->execute([$studentId]);
        $userId = $find->fetchColumn();
        if (!$userId) throw new Exception('Student not found.');

        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
        $check->execute([$username, $email, $userId]);
        if ($check->fetchColumn() > 0) throw new Exception('Username or email already used by another account.');

        $pdo->beginTransaction();

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare('UPDATE users SET username=?, email=?, status=?, password=? WHERE user_id=?');
            $upd->execute([$username, $email, $status, $hash, $userId]);
        } else {
            $upd = $pdo->prepare('UPDATE users SET username=?, email=?, status=? WHERE user_id=?');
            $upd->execute([$username, $email, $status, $userId]);
        }

        $upd2 = $pdo->prepare('UPDATE students SET student_number=?, full_name=?, program_id=?, year_level=?, contact_number=? WHERE student_id=?');
        $upd2->execute([$studentNumber, $fullName, $programId, $yearLevel, $contact, $studentId]);

        log_activity($pdo, $_SESSION['user_id'], "Updated student: $fullName");
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);

    } elseif ($action === 'delete') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if (!$studentId) throw new Exception('Invalid student.');

        $find = $pdo->prepare('SELECT user_id, full_name FROM students WHERE student_id = ?');
        $find->execute([$studentId]);
        $row = $find->fetch();
        if (!$row) throw new Exception('Student not found.');

        // Deleting the user cascades to students, enrollments, attendance_records via FK
        $del = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
        $del->execute([$row['user_id']]);

        log_activity($pdo, $_SESSION['user_id'], "Deleted student: {$row['full_name']}");
        echo json_encode(['success' => true, 'message' => 'Student deleted successfully.']);

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
