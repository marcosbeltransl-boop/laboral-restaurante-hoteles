<?php
/**
 * Recibe el formulario de presupuesto, envía email, cuenta envíos reales y registra IP/fecha/navegador.
 * Requiere PHP con mail() o configurar SMTP según tu servidor.
 *
 * Seguridad: el servidor ejecuta este archivo y solo envía la respuesta (JSON); el código fuente
 * no se sirve al navegador. Solo se aceptan peticiones POST (GET/otros devuelven 405).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// Cargar .env si existe (Composer: vlucas/phpdotenv)
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    if (class_exists('Dotenv\\Dotenv')) {
        try {
            Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
        } catch (\Throwable $e) {
            // Si falla la carga del .env, continuamos con valores por defecto.
        }
    }
}

// Fallback: cargar .env aunque no exista vendor/ (parser simple)
// Esto permite que MAIL_* / SMTP_* funcionen sin vlucas/phpdotenv.
function cargarDotEnvSimple($path)
{
    if (!is_string($path) || $path === '' || !file_exists($path)) return;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return;

    $lines = preg_split("/\r\n|\n|\r/", $raw);
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($key === '' || preg_match('/\s/', $key)) continue;

        // Quitar comillas si las hay
        if (strlen($val) >= 2) {
            $q = $val[0];
            if (($q === '"' || $q === "'") && $val[strlen($val) - 1] === $q) {
                $val = substr($val, 1, -1);
            }
        }

        // No sobreescribir variables ya definidas
        if (getenv($key) !== false) continue;

        @putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
}

cargarDotEnvSimple(__DIR__ . '/.env');

function envOrDefault($key, $default = '')
{
    $v = getenv($key);
    if ($v === false) return $default;
    $v = trim((string) $v);
    return $v !== '' ? $v : $default;
}

function envBool($key, $default = false)
{
    $v = strtolower(trim((string) envOrDefault($key, $default ? '1' : '0')));
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
}

function clienteIp()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim((string) $parts[0]);
    }
    return !empty($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

// ——— Configuración del correo (estructura MAIL_* / SMTP_*) ———
// (Compatibilidad) Si no existen MAIL_* se usan EMAIL_* anteriores.
$mailToEmail = envOrDefault('MAIL_TO_EMAIL', envOrDefault('EMAIL_INFO', 'info@marcosbeltran.es'));
$mailToName = envOrDefault('MAIL_TO_NAME', '');

$mailFromEmail = envOrDefault('MAIL_FROM_EMAIL', envOrDefault('EMAIL_FORMULARIO', 'solicitud-desde-formulario@marcosbeltran.es'));
$mailFromName = envOrDefault('MAIL_FROM_NAME', envOrDefault('EMAIL_FROM_NAME', 'Marcos Beltrán'));

$mailSendCopyToClient = envBool('MAIL_SEND_COPY_TO_CLIENT', true);
$mailRateLimitSeconds = (int) envOrDefault('MAIL_RATE_LIMIT_SECONDS', '0');

$carpetaData = __DIR__ . '/data';
$carpetaSessions = $carpetaData . '/sessions';
$archivoContador = $carpetaData . '/contador.txt';
$archivoLog = $carpetaData . '/accesos.log';
$archivoRateLimit = $carpetaData . '/rate_limit.json';
$archivoSmtpDebug = $carpetaData . '/smtp_debug.log';
$archivoMailError = $carpetaData . '/mail_error.log';
$archivoCtaEventos = $carpetaData . '/cta-eventos.log';
$archivoEstadisticasCta = $carpetaData . '/estadisticas-cta.md';

// Honeypot: si "website" viene rellenado, es un bot — no contar, no registrar, no enviar
$honeypot = isset($_POST['website']) ? trim((string) $_POST['website']) : '';
$esReal = ($honeypot === '');

function respuestaOk() {
    http_response_code(200);
    echo json_encode(['ok' => true]);
}

function respuestaError($mensaje = 'Error al procesar', $statusCode = 500) {
    http_response_code($statusCode);
    echo json_encode(['ok' => false, 'error' => $mensaje]);
}

// Si es bot, devolver OK sin hacer nada (no filtrar comportamiento)
if (!$esReal) {
    respuestaOk();
    exit;
}

// Crear carpeta data si no existe y asegurar que no sea accesible por web
if (!is_dir($carpetaData)) {
    @mkdir($carpetaData, 0755, true);
}
$htaccess = $carpetaData . '/.htaccess';
if (is_dir($carpetaData) && !file_exists($htaccess)) {
    $contenido = "# Bloquear todo acceso por web\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
    @file_put_contents($htaccess, $contenido);
}

// Estructura adicional (como en otros proyectos): sessions/ y ficheros estándar
if (is_dir($carpetaData) && !is_dir($carpetaSessions)) {
    @mkdir($carpetaSessions, 0755, true);
}

function asegurarArchivo($ruta)
{
    if ($ruta === '') return;
    if (!file_exists($ruta)) {
        @file_put_contents($ruta, '');
    }
}

asegurarArchivo($archivoLog);
asegurarArchivo($archivoContador);
asegurarArchivo($archivoSmtpDebug);
asegurarArchivo($archivoMailError);
asegurarArchivo($archivoCtaEventos);
asegurarArchivo($archivoEstadisticasCta);

function logMailError($archivoMailError, $mensaje, $contexto = [])
{
    if ($archivoMailError === '') return;
    $line = date('Y-m-d H:i:s') . ' ' . (string) $mensaje . "\n";
    @file_put_contents($archivoMailError, $line, FILE_APPEND | LOCK_EX);
}

// Rate limit básico por IP (solo para envíos reales)
if ($mailRateLimitSeconds > 0 && is_dir($carpetaData) && is_writable($carpetaData)) {
    $ip = clienteIp();
    $now = time();
    $map = [];
    if (file_exists($archivoRateLimit)) {
        $raw = @file_get_contents($archivoRateLimit);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $map = $decoded;
        }
    }
    $last = isset($map[$ip]) ? (int) $map[$ip] : 0;
    if ($last > 0 && ($now - $last) < $mailRateLimitSeconds) {
        respuestaError('Demasiados envíos seguidos. Espera unos segundos y prueba de nuevo.', 429);
        exit;
    }
    $map[$ip] = $now;
    @file_put_contents($archivoRateLimit, json_encode($map, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// Enviar email solo si es envío real
$nombre = isset($_POST['nombre']) ? trim((string) $_POST['nombre']) : '';
$empresa = isset($_POST['empresa']) ? trim((string) $_POST['empresa']) : '';
$telefono = isset($_POST['telefono']) ? trim((string) $_POST['telefono']) : '';
$email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$mensaje = isset($_POST['mensaje']) ? trim((string) $_POST['mensaje']) : '';
$asunto = isset($_POST['_subject']) ? trim((string) $_POST['_subject']) : 'Nuevo presupuesto';

if (strlen($nombre) < 2) {
    respuestaError('Nombre inválido', 422);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respuestaError('Email inválido', 422);
    exit;
}
if ($telefono === '') {
    respuestaError('Teléfono requerido', 422);
    exit;
}

$cuerpo = "Nombre: $nombre\n";
$cuerpo .= "Empresa: $empresa\n";
$cuerpo .= "Teléfono: $telefono\n";
$cuerpo .= "Email: $email\n\n";
$cuerpo .= "Mensaje:\n$mensaje\n";

function enviarEmail($toEmail, $toName, $subject, $body, $fromEmail, $fromName, $replyToEmail = '', $replyToName = '', $debugFile = '', $mailErrorFile = '')
{
    $resultado = [
        'ok' => false,
        'used' => 'mail', // smtp|mail
        'error' => '',
    ];

    $smtpHost = envOrDefault('SMTP_HOST', '');
    $smtpPort = (int) envOrDefault('SMTP_PORT', '587');
    $smtpUser = envOrDefault('SMTP_USER', '');
    $smtpPass = envOrDefault('SMTP_PASS', '');
    $smtpSecure = strtolower(envOrDefault('SMTP_SECURE', 'tls')); // tls|ssl|none
    $smtpVerifyPeer = envBool('SMTP_VERIFY_PEER', true);
    $smtpAllowSelfSigned = envBool('SMTP_ALLOW_SELF_SIGNED', false);
    $transport = strtolower(envOrDefault('MAIL_TRANSPORT', ''));

    $shouldUseSmtp = ($transport === 'smtp') || ($transport === '' && $smtpHost !== '');
    if ($transport === 'mail') $shouldUseSmtp = false;

    $smtpWantedButNoLib = false;
    if ($shouldUseSmtp && !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $smtpWantedButNoLib = true;
        if ($mailErrorFile !== '') {
            logMailError($mailErrorFile, 'envio.php SMTP activo pero falta vendor/ (PHPMailer). Se intenta mail().');
        }
    }

    // SMTP (solo si está configurado y PHPMailer está disponible)
    if ($shouldUseSmtp && $smtpHost !== '' && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $resultado['used'] = 'smtp';
            $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->isSMTP();
            $mailer->Host = $smtpHost;
            $mailer->Port = $smtpPort > 0 ? $smtpPort : 587;

            $mailer->SMTPAuth = ($smtpUser !== '' || $smtpPass !== '');
            if ($mailer->SMTPAuth) {
                $mailer->Username = $smtpUser;
                $mailer->Password = $smtpPass;
            }

            if ($smtpSecure === 'ssl') {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtpSecure === 'tls') {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            if ($smtpSecure === 'ssl' || $smtpSecure === 'tls') {
                $mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => $smtpVerifyPeer,
                        'verify_peer_name' => $smtpVerifyPeer,
                        'allow_self_signed' => $smtpAllowSelfSigned,
                    ],
                ];
            }

            if (envBool('MAIL_DEBUG', false) && $debugFile !== '') {
                $mailer->SMTPDebug = 2;
                $mailer->Debugoutput = function ($str, $level) use ($debugFile) {
                    $line = date('Y-m-d H:i:s') . ' ' . trim((string) $str) . "\n";
                    @file_put_contents($debugFile, $line, FILE_APPEND | LOCK_EX);
                };
            }

            $mailer->setFrom($fromEmail, $fromName);
            if ($toName !== '') $mailer->addAddress($toEmail, $toName);
            else $mailer->addAddress($toEmail);

            if ($replyToEmail !== '') {
                $mailer->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $replyToEmail);
            }

            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->AltBody = $body;
            $mailer->isHTML(false);

            $mailer->send();
            $resultado['ok'] = true;
            return $resultado;

        } catch (\Throwable $e) {
            $resultado['error'] = $e->getMessage();
            return $resultado;
        }
    }

    // Fallback mail() (mejorado con envelope sender -f)
    $cabeceras = "Content-Type: text/plain; charset=UTF-8\r\n";
    $fromHeader = $fromName !== '' ? ($fromName . " <" . $fromEmail . ">") : $fromEmail;
    $cabeceras .= "From: $fromHeader\r\n";
    if ($replyToEmail !== '') {
        $replyHeader = $replyToName !== '' ? ($replyToName . " <" . $replyToEmail . ">") : $replyToEmail;
        $cabeceras .= "Reply-To: $replyHeader\r\n";
    }

    $toHeader = $toName !== '' ? ($toName . " <" . $toEmail . ">") : $toEmail;

    // Envelope sender (muy útil en Plesk)
    $params = '';
    if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $params = '-f ' . escapeshellarg($fromEmail);
    }

    $ok = ($params !== '')
        ? @mail($toHeader, $subject, $body, $cabeceras, $params)
        : @mail($toHeader, $subject, $body, $cabeceras);

    $resultado['used'] = 'mail';
    $resultado['ok'] = (bool) $ok;
    if (!$ok) {
        $resultado['error'] = $smtpWantedButNoLib
            ? 'SMTP activo pero falta vendor/ (PHPMailer) y mail() falló'
            : 'mail() falló';
    }
    return $resultado;
}

// 2) Notificación al equipo comercial
$r2 = enviarEmail($mailToEmail, $mailToName, $asunto, $cuerpo, $mailFromEmail, $mailFromName, $email, $nombre, $archivoSmtpDebug, $archivoMailError);
if (!$r2['ok']) {
    if ($r2['used'] === 'mail') {
        logMailError($archivoMailError, 'envio.php mail() falló → ' . $mailToEmail . ' (info)');
    } else {
        logMailError($archivoMailError, 'envio.php fallo envío → ' . $mailToEmail . ' (equipo) — ' . ($r2['error'] !== '' ? $r2['error'] : 'SMTP falló'));
    }
    respuestaError('No se pudo enviar la solicitud a info@');
    exit;
}

// 1) Copia al cliente (opcional) — no debe bloquear el envío al equipo
if ($mailSendCopyToClient) {
    $asuntoCliente = 'Copia de tu solicitud de presupuesto — ' . $mailFromName;
    $r1 = enviarEmail($email, $nombre, $asuntoCliente, $cuerpo, $mailFromEmail, $mailFromName, $mailToEmail, $mailToName, $archivoSmtpDebug, $archivoMailError);
    if (!$r1['ok']) {
        if ($r1['used'] === 'mail') {
            logMailError($archivoMailError, 'envio.php mail() falló → ' . $email . ' (cliente)');
        } else {
            logMailError($archivoMailError, 'envio.php fallo envío → ' . $email . ' (cliente) — ' . ($r1['error'] !== '' ? $r1['error'] : 'SMTP falló'));
        }
        // No hacemos exit: el equipo ya recibió el email
    }
}

function generarEstadisticasCta($archivoEventos, $archivoStats)
{
    if (!file_exists($archivoEventos)) return;
    $raw = @file_get_contents($archivoEventos);
    if (!is_string($raw) || trim($raw) === '') return;

    $lineas = [];
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln !== '') $lineas[] = $ln;
    }

    $totales = [
        'whatsapp' => 0,
        'llamar' => 0,
        'presupuesto' => 0,
        'asesor_whatsapp' => 0,
        'formulario' => 0,
    ];

    foreach ($lineas as $ln) {
        $parts = explode("\t", $ln);
        if (count($parts) < 3) continue;
        $accion = strtolower(trim((string) $parts[1]));
        if (isset($totales[$accion])) $totales[$accion]++;
    }

    $total = array_sum($totales);
    $ultima = '';
    if (!empty($lineas)) {
        $parts = explode("\t", $lineas[count($lineas) - 1]);
        $ultima = isset($parts[0]) ? trim((string) $parts[0]) : '';
    }

    $ultimos = array_slice($lineas, -80);

    $md = [];
    $md[] = '# Estadísticas de CTAs';
    $md[] = '';
    $md[] = '**Solo visibles desde Plesk (carpeta data protegida por .htaccess).**';
    $md[] = '';
    if ($ultima !== '') {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $ultima);
        $md[] = 'Última actualización: ' . ($dt ? $dt->format('d/m/Y H:i:s') : $ultima);
    } else {
        $md[] = 'Última actualización: ' . date('d/m/Y H:i:s');
    }
    $md[] = '';
    $md[] = '## Totales';
    $md[] = '';
    $md[] = '| CTA | Clics / Envíos |';
    $md[] = '|-----|----------------|';
    $md[] = '| Hablar por WhatsApp | ' . $totales['whatsapp'] . ' |';
    $md[] = '| Llamar ahora | ' . $totales['llamar'] . ' |';
    $md[] = '| Pedir Presupuesto | ' . $totales['presupuesto'] . ' |';
    $md[] = '| Habla con un Asesor por WhatsApp | ' . $totales['asesor_whatsapp'] . ' |';
    $md[] = '| Formulario enviado | ' . $totales['formulario'] . ' |';
    $md[] = '| **Total** | **' . $total . '** |';
    $md[] = '';
    $md[] = '## Últimos 80 eventos (fecha – acción – IP)';
    $md[] = '';
    $md[] = '```';
    foreach ($ultimos as $ln) {
        $md[] = $ln;
    }
    $md[] = '```';

    @file_put_contents($archivoStats, implode("\n", $md) . "\n", LOCK_EX);
}

function registrarCtaEvento($archivoEventos, $archivoStats, $accion, $ip)
{
    if ($archivoEventos === '' || $accion === '') return;
    $linea = date('Y-m-d H:i:s') . "\t" . strtolower($accion) . "\t" . (string) $ip . "\n";
    @file_put_contents($archivoEventos, $linea, FILE_APPEND | LOCK_EX);
    generarEstadisticasCta($archivoEventos, $archivoStats);
}

// Contador y log solo si el envío ha sido exitoso
if (is_dir($carpetaData) && is_writable($carpetaData)) {
    $n = 0;
    if (file_exists($archivoContador)) {
        $n = (int) trim((string) @file_get_contents($archivoContador));
    }
    $n++;
    @file_put_contents($archivoContador, (string) $n, LOCK_EX);

    $ip = clienteIp();
    $fecha = date('Y-m-d H:i:s');
    $navegador = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
    $linea = $ip . "\t" . $fecha . "\t" . str_replace(["\r", "\n", "\t"], ' ', $navegador) . "\n";
    @file_put_contents($archivoLog, $linea, FILE_APPEND | LOCK_EX);

    // Registrar envío correcto del formulario en el mismo log de CTAs
    registrarCtaEvento($archivoCtaEventos, $archivoEstadisticasCta, 'formulario', $ip);
}

respuestaOk();
