<?php
header('Content-Type: application/json; charset=utf-8');

$file = __DIR__ . '/modules/staff.php';
$results = [];

$results['file_exists'] = file_exists($file);

if (file_exists($file)) {
    $content = file_get_contents($file);
    $results['file_size']    = strlen($content);
    $results['first_200']    = substr($content, 0, 200);
    $results['has_php_close']= strpos($content, '</php>') !== false;
    $results['php_close_pos']= strpos($content, '</php>');
    $results['has_script']   = strpos($content, '<script>') !== false;

    // Симулируем что делает handleModule
    $pos = strpos($content, '</php>');
    $jspart = $pos !== false ? substr($content, $pos) : '';
    $results['jspart_length'] = strlen($jspart);
    $results['jspart_first_100'] = substr($jspart, 0, 100);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);