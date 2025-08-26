<?php
function jsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function sanitizeString($str) {
    return mb_convert_encoding($str, 'UTF-8', mb_detect_encoding($str));
}
