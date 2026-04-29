<?php
// Habilitar mostrar errores para depuración (quitar en producción)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido."]);
    exit();
}

// Cargar variables de entorno (.env)
function loadEnv() {
    $envPaths = [__DIR__ . '/.env', __DIR__ . '/../.env'];
    foreach ($envPaths as $path) {
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    putenv(trim($parts[0]) . '=' . trim($parts[1]));
                    $_ENV[trim($parts[0])] = trim($parts[1]);
                }
            }
            return;
        }
    }
}
loadEnv();

$nombre = $_POST['nombre'] ?? '';
$apellido = $_POST['apellido'] ?? '';
$email_cliente = $_POST['email'] ?? '';
$telefono = $_POST['telefono'] ?? '';
$consulta = $_POST['consulta'] ?? '';
$recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

if (empty($nombre) || empty($email_cliente) || empty($consulta)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Faltan campos obligatorios."]);
    exit();
}

// Verificación de reCAPTCHA
$recaptcha_secret = getenv('RECAPTCHA_SECRET_KEY') ?: '';
if (!empty($recaptcha_secret)) {
    if (empty($recaptcha_response)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Por favor, completa el captcha."]);
        exit();
    }

    $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $recaptcha_secret,
        'response' => $recaptcha_response
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context  = stream_context_create($options);
    $verify_result = file_get_contents($verify_url, false, $context);
    $captcha_success = json_decode($verify_result);

    if (!$captcha_success->success) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Verificación de captcha fallida."]);
        exit();
    }
}

// Incluir PHPMailer manualmente
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

// Variables
$smtp_user = getenv('SMTP_USER') ?: '';
$smtp_pass = getenv('SMTP_PASS') ?: '';
$smtp_host = getenv('SMTP_HOST') ?: '';
$smtp_port = getenv('SMTP_PORT') ?: 465;

$receiver_email = getenv('RECEIVER_EMAIL') ?: '';

// Verificación de seguridad
if (empty($smtp_user) || empty($smtp_pass) || empty($receiver_email)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error de configuración en el servidor (faltan credenciales)."]);
    exit();
}

$subject = "Nuevo Turno Web - " . $nombre . " " . $apellido;

// Versión en texto plano (AltBody)
$text_message = "Has recibido una nueva consulta desde la web:\n\n";
$text_message .= "Nombre: " . $nombre . " " . $apellido . "\n";
$text_message .= "Email del paciente: " . $email_cliente . "\n";
$text_message .= "Teléfono: " . $telefono . "\n\n";
$text_message .= "Consulta/Servicio:\n" . $consulta . "\n";

// Versión HTML (cargando la plantilla)
$html_template_path = __DIR__ . '/email_template.html';
$html_message = "";

if (file_exists($html_template_path)) {
    $html_message = file_get_contents($html_template_path);
    // Reemplazar las variables {{variable}} por los datos reales
    $html_message = str_replace('{{nombre}}', htmlspecialchars($nombre), $html_message);
    $html_message = str_replace('{{apellido}}', htmlspecialchars($apellido), $html_message);
    $html_message = str_replace('{{email_cliente}}', htmlspecialchars($email_cliente), $html_message);
    $html_message = str_replace('{{telefono}}', htmlspecialchars($telefono), $html_message);
    $html_message = str_replace('{{consulta}}', nl2br(htmlspecialchars($consulta)), $html_message);
} else {
    // Si por alguna razón no encuentra la plantilla, usa el texto como HTML básico
    $html_message = nl2br($text_message);
}

$mail = new PHPMailer(true);

try {
    // Configuración del servidor SMTP
    $mail->isSMTP();
    $mail->Host       = $smtp_host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp_user;
    $mail->Password   = $smtp_pass;
    
    // Si el puerto es 465 se usa SMTPS (SSL)
    if ($smtp_port == 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }
    
    $mail->Port       = $smtp_port;
    $mail->CharSet    = 'UTF-8';

    // Remitente y destinatarios
    $mail->setFrom($smtp_user, 'Turnos Mur Nutrición');
    $mail->addAddress($receiver_email); // A dónde llega el correo (tu gmail)
    $mail->addReplyTo($email_cliente, $nombre . ' ' . $apellido); // Para que al darle "Responder" le envíes al paciente

    // Contenido
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html_message;
    $mail->AltBody = $text_message;

    $mail->send();
    http_response_code(200);
    echo json_encode(["success" => true, "message" => "Correo enviado exitosamente."]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error al enviar el correo. Mailer Error: {$mail->ErrorInfo}"]);
}
?>
