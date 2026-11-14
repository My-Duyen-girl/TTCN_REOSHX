<?php

const MAIL_FROM = 'no-reply@petcare.local';
const MAIL_FROM_NAME = 'Pet Care & Spa';
function gui_mail_xac_thuc(string $email, string $link): bool
{
    $subject = 'Kích hoạt tài khoản Pet Care & Spa';
    $message = "Xin chào,\n\nVui lòng mở liên kết sau để kích hoạt tài khoản:\n$link\n\nLiên kết có thời hạn 24 giờ.";
    $headers = "From: ".MAIL_FROM_NAME." <".MAIL_FROM.">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', $message, $headers);
}