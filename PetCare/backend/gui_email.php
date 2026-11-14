<?php
require_once __DIR__.'/../config/mail.php';

if (basename($_SERVER['SCRIPT_FILENAME']??'') === basename(__FILE__))
{
    http_response_code(404); exit;
}
