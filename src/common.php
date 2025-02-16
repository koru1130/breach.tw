<?php
require_once 'config.php';

function get_ip(){
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?: $_SERVER['REMOTE_ADDR'];
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function simple_email($to, $name, $subject, $content, $code){
    require 'vendor/autoload.php';
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = SMTP_SEME;
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->CharSet = "utf-8";
    $mail->isHTML(true);
    $mail->WordWrap = 50;
    $mail->setFrom(SMTP_EMAIL, SMTP_NICK);
    $mail->AddAddress($to, $name);
    $mail->AddReplyTo(SMTP_EMAIL,SMTP_NICK);
    $mail->Subject = $subject;
    $mail->Body = str_replace("§code§", $code, $content);
    return $mail->Send();
}

function mail_verify($email, $name, $hash, $code){
    $content = EMAIL_VERIFICATION_CONTENT;
    $content = str_replace("§name§", $name, $content);
    $content = str_replace("§hash§", $hash, $content);
    $content = str_replace("§code§", $code, $content);
    return simple_email($email, $name, EMAIL_VERIFICATION_SUBJECT, $content, $code);
}

function is_account_verify($email){
    global $db;
    $stmt = $db->prepare("SELECT `id` FROM `subscribers` WHERE email=:email AND `disable`=0 AND `email_verify`=1");
	$stmt->execute([
        'email' => $email
	]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC)["id"];
    if ($res == ""){
        return 0;
    }else{
        return 1;
    }
}

function is_account_exist($email){
    global $db;
    $stmt = $db->prepare("SELECT `id` FROM `subscribers` WHERE email=:email AND `disable`=0");
	$stmt->execute([
        'email' => $email
	]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC)["id"];
    if ($res == ""){
        return 0;
    }else{
        return 1;
    }
}

function search($hash){
    $res = [];

    global $db;
    $stmt = $db->prepare("SELECT `name`, `birth`, `address`, `social_id`, `email`, `phone`, `fb_id` FROM `taiwan_social`.`person` WHERE `name_sid_sha1`=:hash");
    $stmt->execute([
        'hash' => $_GET['hash']
    ]);

    $res['status'] = '0';
    $res['result']['fields'] = array_remove_null_return_keys($stmt->fetchall(PDO::FETCH_ASSOC));

    search_log($_GET['hash'], $res['result']['fields']);
    
    return $res;
}

function subscribe($name, $email, $hash){
    $res = [];

    if (is_account_exist($email)){
        $res['status'] = '1';
        if (is_account_verify($email)){
            $res['error'] = '此 E-mail 已訂閱過洩漏訊息，將會發測試信給您。';
            // TODO: Send test mail
        }else{
            $res['error'] = '此 E-mail 已訂閱過洩漏訊息，但尚未驗證 E-mail，請前往您的電子郵箱確認。';
        }
    }else{
        $code = sha1(sprintf("%10d", mt_rand(1, 9999999999)) . $hash);
        if(mail_verify($email, $name, $hash, $code)){
            global $db;
            $stmt = $db->prepare("INSERT INTO `subscribers`(`name`, `email`, `hash`, `email_verify_code`, `sub_ip`, `sub_time`) VALUES (:name, :email, :hash, :code, :ip, NOW())");
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'hash' => $hash,
                'code' => $code,
                'ip' => get_ip()
            ]);

            $res = search($_GET['hash']);
        }else{
            $res['status'] = '1';
            $res['error'] = 'E-mail 發送錯誤';
        }
    }
    return $res;
}

function verify_code($code){    
    global $db;
    $stmt = $db->prepare("SELECT `id` FROM `subscribers` WHERE `email_verify_code`=:code AND `email_verify`=0");
    $stmt->execute([
        'code' => $code
    ]);
    $id = $stmt->fetch(PDO::FETCH_ASSOC)["id"];
    if ($id != ""){
        $stmt = $db->prepare("UPDATE `subscribers` SET `email_verify`=1, `email_verify_time`=NOW(),`email_verify_ip`=:ip WHERE `id`=:id");
        $stmt->execute([
            'id' => $id,
            'ip' => get_ip()
        ]);
        return 1;
    }else{
        return 0;
    }
}

function is_sha1($str) {
    return (bool) preg_match('/^[0-9a-f]{40}$/i', $str);
}

function search_log($hash, $res){
    global $db;
    $stmt = $db->prepare('INSERT INTO `search_log`(`hash`, `isbreach`, `ip`) VALUES (:hash, :isbreach, :ip)');
    $stmt->execute([
        'hash' => $hash,
        'isbreach' => ($res != array() ? '1' : '0'),
        'ip' => get_ip()
    ]);
}

function array_remove_null_return_keys($array)
{
    $keys = array();
    foreach ($array as $_key => $sarr) {
        foreach ($sarr as $key => $value) {
            if (!(is_null($sarr[$key]) or trim($sarr[$key]) == '')) {
                array_push($keys, $key);
            }
        }
    }

    return array_unique($keys);
}
?>
