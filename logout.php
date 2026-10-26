<?php
/**
 * 登出
 */
require_once __DIR__ . '/includes/init.php';
Auth::logout();
redirect(url(''));
