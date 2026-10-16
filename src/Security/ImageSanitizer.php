<?php
namespace App\Security;

use RuntimeException;

class ImageSanitizer {
    private const MAX_SIZE = 2 * 1024 * 1024; // 2 MB
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png'
    ];

    public function sanitizeAndSave(array $file, string $targetDir): string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Eroare la încărcarea fișierului.");
        }
        if ($file['size'] > self::MAX_SIZE) {
            throw new RuntimeException("Fișierul depășește limita de 2MB.");
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!array_key_exists($mime, self::ALLOWED_MIMES)) {
            throw new RuntimeException("Extensie/MIME nepermis. Doar JPG și PNG sunt acceptate.");
        }

        // Anti-steganografie: recreăm imaginea în memorie prin GD pentru a distruge metadatele EXIF și payload-urile ascunse
        $sourceImage = ($mime === 'image/jpeg') 
            ? @imagecreatefromjpeg($file['tmp_name']) 
            : @imagecreatefrompng($file['tmp_name']);

        if (!$sourceImage) {
            throw new RuntimeException("Imaginea este coruptă sau conține cod malițios.");
        }

        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        $cleanImage = imagecreatetruecolor($width, $height);
        imagecopy($cleanImage, $sourceImage, 0, 0, 0, 0, $width, $height);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $safeFilename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIMES[$mime];
        $destination = rtrim($targetDir, '/') . '/' . $safeFilename;

        if ($mime === 'image/jpeg') {
            imagejpeg($cleanImage, $destination, 90);
        } else {
            imagepng($cleanImage, $destination, 9);
        }

        imagedestroy($sourceImage);
        imagedestroy($cleanImage);

        return $safeFilename;
    }
}
