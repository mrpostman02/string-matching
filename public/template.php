<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
session_write_close();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="input-template.csv"');
echo (new \StringMatching\BatchInput())->template();