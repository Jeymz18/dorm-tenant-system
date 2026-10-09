<?php
require_once __DIR__ . '/config/app.php';

if (is_logged_in()) {
    redirect(role_home_path());
}

redirect('/auth/login.php');
