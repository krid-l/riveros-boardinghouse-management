<?php
// includes/uploads.php
// One place for saving uploaded images. Files go to Supabase Storage when the app is
// deployed and to the local uploads/ folder when it is run under WAMP/XAMPP, so the same
// code path works in both places.

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;

/** MIME types accepted for image uploads, mapped to the extension we save them under. */
function allowedImageTypes(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
}

/**
 * Validate and store an uploaded image.
 *
 * @param array  $file   One entry of $_FILES.
 * @param string $folder Bucket name on Supabase / sub-folder of uploads/ locally.
 * @return array ['path' => string|null, 'error' => string|null]
 *               path is a public URL (Supabase) or a project-relative path (local).
 */
function storeUploadedImage(array $file, string $folder): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];   // nothing was chosen
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => 'The file could not be uploaded. Please try again.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['path' => null, 'error' => 'Invalid upload.'];
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return ['path' => null, 'error' => 'The image must be 5MB or smaller.'];
    }

    // Trust the file's contents, never the name or the browser-supplied type.
    $mimeType = @mime_content_type($file['tmp_name']);
    $allowed = allowedImageTypes();
    if (!isset($allowed[$mimeType])) {
        return ['path' => null, 'error' => 'The file must be an image (JPG, PNG, WEBP or GIF).'];
    }

    // We name the file ourselves, so a crafted filename can't put it anywhere unexpected
    // or give it an executable extension.
    $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mimeType];

    $supabaseUrl = getenv('SUPABASE_URL') ?: (function_exists('appConfig') ? appConfig('supabase_url', '') : '');
    $supabaseKey = getenv('SUPABASE_SERVICE_KEY') ?: (function_exists('appConfig') ? appConfig('supabase_service_key', '') : '');

    if ($supabaseUrl && $supabaseKey) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$supabaseUrl/storage/v1/object/$folder/$fileName");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file['tmp_name']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $supabaseKey",
            "Content-Type: $mimeType",
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['path' => "$supabaseUrl/storage/v1/object/public/$folder/$fileName", 'error' => null];
        }
        return ['path' => null, 'error' => 'Could not upload the image to storage. Please try again.'];
    }

    // Local testing (WAMP/XAMPP): keep the file under uploads/.
    $uploadDir = __DIR__ . '/../uploads/' . $folder . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['path' => null, 'error' => 'The uploads folder is not writable.'];
    }
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        return ['path' => null, 'error' => 'Could not save the image. Check that uploads/ is writable.'];
    }
    return ['path' => 'uploads/' . $folder . '/' . $fileName, 'error' => null];
}

/**
 * Turn a stored path into something a page can put in src="".
 * Supabase paths are already absolute URLs; local ones are relative to the project root.
 *
 * @param string $prefix Path back to the project root from the current page, e.g. '../'.
 */
function uploadSrc(?string $path, string $prefix = ''): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;
    return preg_match('#^https?://#i', $path) ? $path : $prefix . $path;
}

/** Delete a locally stored upload. Files kept in Supabase are left alone. */
function deleteLocalUpload(?string $path): void {
    $path = trim((string)$path);
    if ($path === '' || preg_match('#^https?://#i', $path)) return;
    // Only ever touch files inside uploads/.
    if (!preg_match('#^uploads/[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+$#', $path)) return;
    $full = __DIR__ . '/../' . $path;
    if (is_file($full)) @unlink($full);
}
