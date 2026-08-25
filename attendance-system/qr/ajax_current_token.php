<?php
/**
 * qr/ajax_current_token.php
 * Polled by the QR-display screens (teacher session page, admin QR
 * management page) every few seconds. Returns the CURRENT signed QR
 * payload, rotating the underlying token first if the configured
 * rotation interval has elapsed. This is what makes a screenshotted
 * QR code stop working shortly after it's taken.
 *
 * Accessible to: the owning teacher, or any admin. Nobody else.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/qr_helper.php';
require_once __DIR__ . '/session_manager.php';
require_role(['teacher', 'admin']);
header('Content-Type: application/json');

try {
    $sessionId = (int) ($_GET['session_id'] ?? 0);
    if (!$sessionId) throw new Exception('Invalid session.');

    $stmt = $pdo->prepare('SELECT s.*, ts.teacher_id FROM attendance_sessions s JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id WHERE s.session_id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) throw new Exception('Session not found.');

    if ($_SESSION['role'] === 'teacher' && (int) $session['teacher_id'] !== (int) $_SESSION['profile_id']) {
        throw new Exception('Access denied.');
    }
    if ((int) $session['is_active'] !== 1) {
        echo json_encode(['success' => false, 'message' => 'Session is no longer active.', 'active' => false]);
        exit;
    }

    $rotationSeconds = get_setting_int($pdo, 'qr_token_rotation_seconds', 20);
    $payload = qr_maybe_rotate_token($pdo, $session, $rotationSeconds);

    echo json_encode(['success' => true, 'active' => true, 'payload' => $payload]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
