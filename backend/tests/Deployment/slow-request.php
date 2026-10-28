<?php

declare(strict_types=1);

sleep(6);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'status' => 'slow-request-complete',
    'forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
    'forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
], JSON_THROW_ON_ERROR);
