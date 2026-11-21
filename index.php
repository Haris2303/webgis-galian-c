<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// TODO (T-06): ganti placeholder ini dengan redirect ke login.php / dashboard.php
// sesuai status sesi, begitu modul autentikasi tersedia.
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WebGIS Galian C — Kota Sorong</title>
<style>
  body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#F4F6F9;color:#1E3A5F;margin:0;padding:48px 16px;display:flex;justify-content:center;}
  .box{max-width:560px;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.12);padding:32px;}
  h1{margin:0 0 8px;font-size:20px;}
  p{line-height:1.5;color:#333;}
  code{background:#F4F6F9;padding:2px 6px;border-radius:4px;}
</style>
</head>
<body>
<div class="box">
  <h1>WebGIS Galian C — Kota Sorong</h1>
  <p>Bootstrap proyek berhasil dimuat (T-01). Halaman login/dashboard akan menggantikan placeholder ini setelah modul autentikasi (T-06) selesai.</p>
  <p>APP_ENV saat ini: <code><?= e(APP_ENV) ?></code> · BASE_URL: <code><?= e(BASE_URL) ?></code></p>
</div>
</body>
</html>
