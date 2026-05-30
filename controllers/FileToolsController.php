<?php
// =====================================================
// controllers/FileToolsController.php
// =====================================================

class FileToolsController
{
    public function index(): void
    {
        $pageTitle   = 'จัดการไฟล์';
        $pageStyle   = 'file-tools';
        $pageScript  = 'file-tools';
        $loadPdfLibs = true;

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/file-tools/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // ─────────────────────────────────────────────────
    // API: Image processing (PHP GD)
    // ─────────────────────────────────────────────────

    public function apiImage(): void
    {
        if (empty($_FILES['file'])) {
            Response::json(['error' => 'ไม่พบไฟล์'], 422);
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'อัปโหลดไม่สำเร็จ'], 422);
        }
        if ($file['size'] > MAX_UPLOAD_BYTES) {
            Response::json(['error' => 'ไฟล์ขนาดใหญ่เกินไป (' . formatBytes(MAX_UPLOAD_BYTES) . ')'], 422);
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif',
            'image/webp', 'image/bmp', 'image/x-bmp',
        ];
        if (!in_array($mimeType, $allowedMimes)) {
            Response::json(['error' => 'รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, GIF, WEBP, BMP)'], 422);
        }

        $action = Request::input('action', 'convert');
        $src    = $this->gdLoad($file['tmp_name'], $mimeType);
        if (!$src) {
            Response::json(['error' => 'ไม่สามารถอ่านไฟล์รูปภาพได้'], 422);
        }

        $origName = pathinfo(basename($file['name']), PATHINFO_FILENAME);

        switch ($action) {
            case 'convert':
                $this->doConvert($src, $origName, Request::input('format', 'png'));
                break;
            case 'resize':
                $this->doResize($src, $origName, $mimeType);
                break;
            case 'compress':
                $this->doCompress($src, $origName, $mimeType);
                break;
            case 'transform':
                $this->doTransform($src, $origName, $mimeType);
                break;
            default:
                Response::json(['error' => 'action ไม่รู้จัก'], 422);
        }
    }

    private function gdLoad(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg'              => @imagecreatefromjpeg($path),
            'image/png'               => @imagecreatefrompng($path),
            'image/gif'               => @imagecreatefromgif($path),
            'image/webp'              => @imagecreatefromwebp($path),
            'image/bmp', 'image/x-bmp' => @imagecreatefrombmp($path),
            default                   => false,
        };
    }

    private function doConvert($src, string $name, string $format): never
    {
        $format = strtolower($format);
        ob_start();
        switch ($format) {
            case 'jpg': case 'jpeg':
                $bg = imagecreatetruecolor(imagesx($src), imagesy($src));
                imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                imagecopy($bg, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                imagejpeg($bg, null, 92);
                imagedestroy($bg);
                $mime = 'image/jpeg'; $ext = 'jpg';
                break;
            case 'png':
                imagepng($src);
                $mime = 'image/png'; $ext = 'png';
                break;
            case 'gif':
                imagegif($src);
                $mime = 'image/gif'; $ext = 'gif';
                break;
            case 'webp':
                imagewebp($src, null, 90);
                $mime = 'image/webp'; $ext = 'webp';
                break;
            case 'bmp':
                imagebmp($src);
                $mime = 'image/bmp'; $ext = 'bmp';
                break;
            default:
                ob_end_clean();
                Response::json(['error' => 'รูปแบบไฟล์ไม่รองรับ'], 422);
        }
        $data = ob_get_clean();
        imagedestroy($src);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($name . '.' . $ext) . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    private function doResize($src, string $name, string $mime): never
    {
        $targetW     = (int) Request::input('width', 0);
        $targetH     = (int) Request::input('height', 0);
        $keepRatio   = Request::input('ratio', '1') !== '0';

        $origW = imagesx($src);
        $origH = imagesy($src);

        if ($targetW <= 0 && $targetH <= 0) {
            Response::json(['error' => 'กรุณาระบุความกว้างหรือความสูง'], 422);
        }

        if ($keepRatio) {
            if ($targetW > 0 && $targetH <= 0) {
                $targetH = (int) round($origH * $targetW / $origW);
            } elseif ($targetH > 0 && $targetW <= 0) {
                $targetW = (int) round($origW * $targetH / $origH);
            } else {
                $scaleW = $targetW / $origW;
                $scaleH = $targetH / $origH;
                $scale  = min($scaleW, $scaleH);
                $targetW = (int) round($origW * $scale);
                $targetH = (int) round($origH * $scale);
            }
        }

        $dst = imagecreatetruecolor($targetW, $targetH);
        // preserve transparency for PNG/GIF
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);
        imagedestroy($src);

        $this->streamGd($dst, $name, $mime);
    }

    private function doCompress($src, string $name, string $mime): never
    {
        $quality = max(1, min(100, (int) Request::input('quality', 80)));
        $this->streamGd($src, $name, $mime, $quality);
    }

    private function doTransform($src, string $name, string $mime): never
    {
        $op = Request::input('op', '');

        switch ($op) {
            case 'rotate90':
                $src = imagerotate($src, -90, 0);
                break;
            case 'rotate180':
                $src = imagerotate($src, 180, 0);
                break;
            case 'rotate270':
                $src = imagerotate($src, 90, 0);
                break;
            case 'fliph':
                imageflip($src, IMG_FLIP_HORIZONTAL);
                break;
            case 'flipv':
                imageflip($src, IMG_FLIP_VERTICAL);
                break;
            case 'grayscale':
                imagefilter($src, IMG_FILTER_GRAYSCALE);
                break;
            case 'blur':
                $passes = max(1, min(20, (int) Request::input('passes', 5)));
                for ($i = 0; $i < $passes; $i++) {
                    imagefilter($src, IMG_FILTER_GAUSSIAN_BLUR);
                }
                break;
            case 'brightness':
                $level = max(-255, min(255, (int) Request::input('level', 30)));
                imagefilter($src, IMG_FILTER_BRIGHTNESS, $level);
                break;
            case 'contrast':
                $level = max(-100, min(100, (int) Request::input('level', -20)));
                imagefilter($src, IMG_FILTER_CONTRAST, $level);
                break;
            default:
                Response::json(['error' => 'op ไม่รู้จัก'], 422);
        }

        $this->streamGd($src, $name, $mime);
    }

    private function streamGd($img, string $name, string $mime, int $quality = 90): never
    {
        ob_start();
        switch ($mime) {
            case 'image/jpeg':
                imagejpeg($img, null, $quality);
                $ext = 'jpg';
                break;
            case 'image/png':
                // PNG quality is 0-9; map from 100-scale
                $pngQ = (int) round((100 - $quality) / 11);
                imagepng($img, null, $pngQ);
                $ext = 'png';
                break;
            case 'image/gif':
                imagegif($img);
                $ext = 'gif';
                break;
            case 'image/webp':
                imagewebp($img, null, $quality);
                $ext = 'webp';
                break;
            default:
                imagepng($img);
                $mime = 'image/png';
                $ext  = 'png';
        }
        $data = ob_get_clean();
        imagedestroy($img);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($name . '.' . $ext) . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    // ─────────────────────────────────────────────────
    // API: ZIP operations (PHP ZipArchive)
    // ─────────────────────────────────────────────────

    public function apiZipCreate(): void
    {
        if (empty($_FILES['files'])) {
            Response::json(['error' => 'ไม่พบไฟล์'], 422);
        }

        $files = $_FILES['files'];
        // Normalize to array of files
        $list = [];
        if (is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $list[] = [
                        'tmp'  => $files['tmp_name'][$i],
                        'name' => basename($files['name'][$i]),
                    ];
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                $list[] = ['tmp' => $files['tmp_name'], 'name' => basename($files['name'])];
            }
        }

        if (empty($list)) {
            Response::json(['error' => 'ไม่มีไฟล์ที่อัปโหลดสำเร็จ'], 422);
        }

        // Validate extensions
        $blockedExt = ['php','php3','php4','php5','php7','php8','phtml','phar',
                       'cgi','pl','py','sh','rb','asp','aspx','jsp'];
        foreach ($list as $f) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $blockedExt)) {
                Response::json(['error' => 'นามสกุลไฟล์ "' . h($ext) . '" ไม่อนุญาต'], 422);
            }
        }

        $zipName = Request::input('name', 'archive') ?: 'archive';
        $zipName = preg_replace('/[^a-zA-Z0-9ก-๙_\- ]/', '', $zipName) ?: 'archive';
        $tmpZip  = tempnam(sys_get_temp_dir(), 'ft_zip_') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Response::json(['error' => 'บีบอัดเป็น ZIP ไม่สำเร็จ'], 500);
        }
        foreach ($list as $f) {
            $zip->addFile($f['tmp'], $f['name']);
        }
        $zip->close();

        $size = filesize($tmpZip);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . addslashes($zipName . '.zip') . '"');
        header('Content-Length: ' . $size);
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }

    public function apiZipInspect(): void
    {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'ไม่พบไฟล์ ZIP'], 422);
        }

        $tmp  = $_FILES['file']['tmp_name'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmp);
        finfo_close($finfo);

        if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed'])) {
            Response::json(['error' => 'กรุณาอัปโหลดไฟล์ ZIP เท่านั้น'], 422);
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            Response::json(['error' => 'เปิดไฟล์ ZIP ไม่สำเร็จ'], 422);
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entries[] = [
                'index'           => $i,
                'name'            => $stat['name'],
                'size'            => $stat['size'],
                'compressed_size' => $stat['comp_size'],
            ];
        }
        $zip->close();

        Response::json(['ok' => true, 'entries' => $entries, 'total' => count($entries)]);
    }

    public function apiZipExtract(): void
    {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'ไม่พบไฟล์ ZIP'], 422);
        }

        $tmp  = $_FILES['file']['tmp_name'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmp);
        finfo_close($finfo);

        if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed'])) {
            Response::json(['error' => 'กรุณาอัปโหลดไฟล์ ZIP เท่านั้น'], 422);
        }

        $indicesRaw = Request::input('indices', '');
        $indices    = [];
        if ($indicesRaw !== '' && $indicesRaw !== null) {
            foreach (explode(',', $indicesRaw) as $idx) {
                $idx = trim($idx);
                if (is_numeric($idx)) $indices[] = (int) $idx;
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            Response::json(['error' => 'เปิดไฟล์ ZIP ไม่สำเร็จ'], 422);
        }

        // Collect requested entries
        $toExtract = [];
        if (empty($indices)) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat['size'] > 0) { // skip directories
                    $toExtract[] = ['index' => $i, 'name' => $stat['name']];
                }
            }
        } else {
            foreach ($indices as $i) {
                $stat = $zip->statIndex($i);
                if ($stat && $stat['size'] > 0) {
                    $toExtract[] = ['index' => $i, 'name' => $stat['name']];
                }
            }
        }

        if (empty($toExtract)) {
            $zip->close();
            Response::json(['error' => 'ไม่มีไฟล์ที่จะแตก'], 422);
        }

        // Single file: stream directly
        if (count($toExtract) === 1) {
            $entry  = $toExtract[0];
            $stream = $zip->getStream($entry['name']);
            $data   = stream_get_contents($stream);
            fclose($stream);
            $zip->close();

            $filename = basename($entry['name']);
            $finfo2   = new finfo(FILEINFO_MIME_TYPE);
            $mime2    = $finfo2->buffer($data) ?: 'application/octet-stream';

            header('Content-Type: ' . $mime2);
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . strlen($data));
            echo $data;
            exit;
        }

        // Multiple files: re-zip and stream
        $tmpDir = sys_get_temp_dir() . '/ft_extract_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $tmpZip = tempnam(sys_get_temp_dir(), 'ft_out_') . '.zip';

        $outZip = new ZipArchive();
        $outZip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($toExtract as $entry) {
            $stream = $zip->getStream($entry['name']);
            $data   = stream_get_contents($stream);
            fclose($stream);
            $outZip->addFromString(basename($entry['name']), $data);
        }

        $outZip->close();
        $zip->close();

        $size = filesize($tmpZip);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="extracted.zip"');
        header('Content-Length: ' . $size);
        readfile($tmpZip);
        @unlink($tmpZip);
        @rmdir($tmpDir);
        exit;
    }
}
