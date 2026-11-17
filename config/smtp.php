<?php
/**
 * SMTP Mail Transport Configuration
 * Small Business Sales and Expense Tracking System
 *
 * To send real live emails to customer inboxes (e.g. Gmail, Outlook, Yahoo):
 * 1. Set 'enabled' => true
 * 2. Set 'username' and 'password' (e.g. a Google 16-character App Password)
 *
 * If 'enabled' is false, emails are safely logged to logs/mail_sandbox.log for local testing.
 */

return [
    'enabled'    => false,                 // Change to true to enable live email delivery
    'host'       => 'smtp.gmail.com',     // SMTP Server (e.g. smtp.gmail.com)
    'port'       => 587,                  // 587 for TLS, 465 for SSL
    'secure'     => 'tls',                // 'tls' or 'ssl'
    'username'   => '',                   // Your SMTP email address
    'password'   => '',                   // Your SMTP password or App Password
    'from_email' => 'no-reply@prestigeapple.ph',
    'from_name'  => 'Prestige Apple Store'
];
