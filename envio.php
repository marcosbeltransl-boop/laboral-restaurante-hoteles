<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Mostrar errores mientras depuras
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Campos del formulario
$subject  = trim((string)($_POST['_subject'] ?? 'Nuevo presupuesto'));
$website  = trim((string)($_POST['website'] ?? ''));
$nombre   = trim((string)($_POST['nombre'] ?? ''));
$empresa  = trim((string)($_POST['empresa'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));
$telefono = trim((string)($_POST['telefono'] ?? ''));
$mensaje  = trim((string)($_POST['mensaje'] ?? ''));

// Honeypot
if ($website !== '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Solicitud no válida'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validaciones
if (strlen($nombre) < 2) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Nombre inválido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Email inválido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($telefono === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Teléfono obligatorio'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mensaje === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Mensaje obligatorio'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $mail = new PHPMailer(true);

    // SMTP
    $mail->isSMTP();
    $mail->Host       = 'localhost';
    $mail->Port       = 587;
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@marcosbeltran.es';
    $mail->Password   = 'YQPHiZ7m42ciCFh';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

    $mail->CharSet = 'UTF-8';

    // Remitente y destino
    $mail->setFrom('info@marcosbeltran.es', 'Web marcosbeltran.es');
    $mail->addAddress('info@marcosbeltran.es', 'Marcos Beltran');
    $mail->addReplyTo($email, $nombre);

    // Contenido
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body =
        "Nuevo formulario de contacto\n\n" .
        "Nombre: {$nombre}\n" .
        "Empresa: " . ($empresa !== '' ? $empresa : '-') . "\n" .
        "Email: {$email}\n" .
        "Teléfono: {$telefono}\n\n" .
        "Mensaje:\n{$mensaje}\n";

    $mail->send();

    echo json_encode([
        'ok' => true,
        'message' => 'Mensaje enviado correctamente'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'No se pudo enviar el mensaje',
        'details' => $mail->ErrorInfo ?: $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}