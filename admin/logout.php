<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

sprawdz_csrf();
wyloguj();
przekieruj('login.php');
