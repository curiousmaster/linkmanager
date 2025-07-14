<?php
require_once 'auth.php';
session_start();
session_destroy();
header("Location: /");
exit;

