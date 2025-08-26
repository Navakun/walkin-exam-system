<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

header('Content-Type: application/json');

$headers = apache_request_headers();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided']);
    exit;
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key('your_secret_key', 'HS256'));

    $data = json_decode(file_get_contents('php://input'), true);
    $examset_id = isset($data['examset_id']) ? intval($data['examset_id']) : 0;

    if ($examset_id <= 0) {
        throw new Exception('Invalid examset ID');
    }

    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Start transaction
    $conn->beginTransaction();

    // Delete questions associations
    $stmt = $conn->prepare("DELETE FROM examset_questions WHERE examset_id = ?");
    $stmt->execute([$examset_id]);

    // Delete the examset
    $stmt = $conn->prepare("DELETE FROM exam_sets WHERE id = ?");
    $stmt->execute([$examset_id]);

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'ลบชุดข้อสอบสำเร็จ'
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
