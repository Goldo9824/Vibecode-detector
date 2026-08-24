<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

AdminAuth::logout();
header('Location: login.php');
