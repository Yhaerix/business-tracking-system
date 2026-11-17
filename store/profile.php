<?php
/**
 * Customer Profile Redirect Alias
 * Smoothly routes traffic to store/account.php
 */

declare(strict_types=1);

$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '?tab=profile';
header('Location: account.php' . $query, true, 302);
exit;
