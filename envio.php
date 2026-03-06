<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// PHPMailer manual
require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Honeypot
$website = isset($_POST['website']) ? trim((string)$_POST['website']) : '';
if ($website !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

// Campos
$nombre   = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
$empresa  = isset($_POST['empresa']) ? trim((string)$_POST['empresa']) : '';
$email    = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$telefono = isset($_POST['telefono']) ? trim((string)$_POST['telefono']) : '';
$mensaje  = isset($_POST['mensaje']) ? trim((string)$_POST['mensaje']) : '';
$asunto   = isset($_POST['_subject']) ? trim((string)$_POST['_subject']) : 'Nuevo presupuesto';

if (mb_strlen($nombre) < 2) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Nombre inválido']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Email inválido']);
    exit;
}

if ($telefono === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Teléfono requerido']);
    exit;
}

if ($mensaje === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Mensaje requerido']);
    exit;
}

// Configuración SMTP
$smtpHost = 'localhost';
$smtpPort = 587;
$smtpUser = 'info@marcosbeltran.es';
$smtpPass = 'PON_AQUI_LA_PASSWORD_DEL_CORREO';
$smtpSecure = PHPMailer::ENCRYPTION_STARTTLS;

// Destino
$mailTo = 'info@marcosbeltran.es';
$mailFrom = 'info@marcosbeltran.es';
$mailFromName = 'Formulario web';

// Cuerpo
$cuerpo = "";
$cuerpo .= "Nombre: " . $nombre . "\n";
$cuerpo .= "Empresa: " . $empresa . "\n";
$cuerpo .= "Email: " . $email . "\n";
$cuerpo .= "Teléfono: " . $telefono . "\n\n";
$cuerpo .= "Mensaje:\n" . $mensaje . "\n";

try {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->Port = $smtpPort;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = $smtpSecure;

    $mail->setFrom($mailFrom, $mailFromName);
    $mail->addAddress($mailTo);
    $mail->addReplyTo($email, $nombre);

    $mail->Subject = $asunto;
    $mail->Body = $cuerpo;
    $mail->AltBody = $cuerpo;
    $mail->isHTML(false);

    $mail->send();

    echo json_encode(['ok' => true]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Error SMTP: ' . $mail->ErrorInfo
    ]);
    exit;
}