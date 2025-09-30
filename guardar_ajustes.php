<?php
require 'db.php';
require_once __DIR__ . '/auth_check.php'; // proteger el login y mandarlo a welcome si la persona no ha verificado su email
require 'auth.php'; // protege la página

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $user_id       = $_SESSION['user_id'] ?? null;
    $full_name     = trim($_POST['full_name'] ?? '');
    $currency_pref = $_POST['currency_pref'] ?? 'S/.';
    $timezone      = trim($_POST['timezone'] ?? 'America/New_York');
    $time_format   = ($_POST['time_format'] ?? '12h');
    $remove_avatar = isset($_POST['remove_avatar']) && $_POST['remove_avatar'] === '1';

    if (!$user_id) {
        $_SESSION['ajustes_error'] = "Sesión inválida. Inicia sesión nuevamente.";
        header("Location: login.php");
        exit;
    }

    // ===== Validaciones =====
    // Nombre requerido
    if ($full_name === '') {
        $_SESSION['ajustes_error'] = "El nombre completo no puede estar vacío.";
        header("Location: ajustes.php");
        exit;
    }

    // Formato de hora permitido
    $ALLOWED_FORMATS = ['12h','24h'];
    if (!in_array($time_format, $ALLOWED_FORMATS, true)) {
        $time_format = '12h';
    }

    // Timezones permitidos (LatAm + USA + España) — mantener sincronizado con ajustes.php
    $ALLOWED_TZ = [
        // Argentina
        'America/Argentina/Buenos_Aires','America/Argentina/Cordoba','America/Argentina/Salta',
        'America/Argentina/Mendoza','America/Argentina/Ushuaia',
        // Bolivia
        'America/La_Paz',
        // Brasil
        'America/Sao_Paulo','America/Manaus','America/Cuiaba','America/Fortaleza','America/Belem',
        'America/Recife','America/Bahia','America/Porto_Velho','America/Boa_Vista',
        // Chile
        'America/Santiago','America/Punta_Arenas','Pacific/Easter',
        // Colombia
        'America/Bogota',
        // Costa Rica
        'America/Costa_Rica',
        // Cuba
        'America/Havana',
        // Rep. Dominicana
        'America/Santo_Domingo',
        // Ecuador
        'America/Guayaquil','Pacific/Galapagos',
        // El Salvador
        'America/El_Salvador',
        // Guatemala
        'America/Guatemala',
        // Honduras
        'America/Tegucigalpa',
        // México
        'America/Mexico_City','America/Monterrey','America/Tijuana','America/Merida',
        'America/Cancun','America/Mazatlan','America/Chihuahua','America/Hermosillo',
        // Nicaragua
        'America/Managua',
        // Panamá
        'America/Panama',
        // Paraguay
        'America/Asuncion',
        // Perú
        'America/Lima',
        // Puerto Rico
        'America/Puerto_Rico',
        // Uruguay
        'America/Montevideo',
        // Venezuela
        'America/Caracas',
        // USA
        'America/New_York','America/Chicago','America/Denver','America/Los_Angeles',
        'America/Phoenix','America/Anchorage','Pacific/Honolulu',
        // España
        'Europe/Madrid','Atlantic/Canary',
    ];
    if (!in_array($timezone, $ALLOWED_TZ, true)) {
        // Fallback sano
        $timezone = 'America/New_York';
    }

    try {
        // ===== 1) Actualizar datos básicos en BD =====
        $stmt = $pdo->prepare("
            UPDATE users
               SET full_name     = :full_name,
                   currency_pref = :currency_pref,
                   timezone      = :timezone,
                   time_format   = :time_format
             WHERE id = :id
        ");
        $stmt->execute([
            ':full_name'     => $full_name,
            ':currency_pref' => $currency_pref,
            ':timezone'      => $timezone,
            ':time_format'   => $time_format,
            ':id'            => $user_id
        ]);

        // ===== 2) Actualizar sesión (UI inmediata) =====
        $_SESSION['full_name']     = $full_name;
        $_SESSION['currency_pref'] = $currency_pref;
        $_SESSION['timezone']      = $timezone;
        $_SESSION['time_format']   = $time_format;

        // ===== 3) Manejo de avatar (opcional) =====
        // Requiere que el form tenga enctype="multipart/form-data" y el input: <input type="file" name="avatar" ...>
        $uploadDir = __DIR__ . '/uploads/avatars';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

        // Función para borrar archivos existentes del usuario (cualquier extensión soportada)
        $existing = glob($uploadDir . "/{$user_id}.*");
        $deleteExisting = function() use ($existing) {
            foreach ($existing as $f) { @unlink($f); }
        };

        // Si pidió quitar avatar explícitamente
        if ($remove_avatar) {
            $deleteExisting();
        }

        // Si subió un archivo nuevo
        if (!empty($_FILES['avatar']['name'])) {
            if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $tmp  = $_FILES['avatar']['tmp_name'];
                $info = @getimagesize($tmp);
                if ($info === false) {
                    $_SESSION['ajustes_error'] = "El archivo subido no es una imagen válida.";
                    header("Location: ajustes.php"); exit;
                }
                $mime = $info['mime'];
                if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    $_SESSION['ajustes_error'] = "Formato no permitido. Usa JPG, PNG o WEBP.";
                    header("Location: ajustes.php"); exit;
                }
                if (filesize($tmp) > 2 * 1024 * 1024) { // 2MB
                    $_SESSION['ajustes_error'] = "La imagen supera el límite de 2MB.";
                    header("Location: ajustes.php"); exit;
                }

                // Borrar anteriores y guardar con nueva extensión
                $deleteExisting();
                $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
                $dest = "{$uploadDir}/{$user_id}.{$ext}";

                if (!@move_uploaded_file($tmp, $dest)) {
                    $_SESSION['ajustes_error'] = "No se pudo guardar la imagen.";
                    header("Location: ajustes.php"); exit;
                }
                @chmod($dest, 0644);
            } else {
                // Error de subida conocido
                $code = (int)$_FILES['avatar']['error'];
                $_SESSION['ajustes_error'] = "Error al subir la imagen (código {$code}).";
                header("Location: ajustes.php"); exit;
            }
        }

        $_SESSION['ajustes_success'] = "Tus cambios se han guardado correctamente.";
    } catch (Throwable $e) {
        // Puedes loguear $e->getMessage() para diagnóstico
        $_SESSION['ajustes_error'] = "No se pudieron guardar los ajustes. Intenta de nuevo.";
    }

    header("Location: ajustes.php");
    exit;
}

// Acceso directo sin POST
header("Location: ajustes.php");
exit;
