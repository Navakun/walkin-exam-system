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
    
    if (!isset($data['examset_id']) || !isset($data['question_id'])) {
        throw new Exception('Missing required parameters');
    }

    $examset_id = intval($data['examset_id']);
    $question_id = intval($data['question_id']);

    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare("DELETE FROM examset_questions WHERE examset_id = ? AND question_id = ?");
    $stmt->execute([$examset_id, $question_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Question removed from examset successfully']);
    } else {
        throw new Exception('Question not found in examset');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
