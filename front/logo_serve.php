<?php
include('../../../inc/includes.php');
Session::checkLoginUser();

$configDir = __DIR__ . '/../config/';
foreach (['png','jpg','jpeg','gif','webp'] as $ext) {
    $file = $configDir . 'logo.' . $ext;
    if (file_exists($file)) {
        $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'][$ext];
        header('Content-Type: ' . $mime);
        header('Cache-Control: max-age=3600');
        readfile($file);
        exit;
    }
}
http_response_code(404);
