<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Forbidden\n"); }
$base = 'http://localhost/YouthSync/api';
$cookie = sys_get_temp_dir() . '/ys_p1_check.txt';
@unlink($cookie);
function req($method, $url, $json, $cookie) {
  $ch = curl_init($url);
  $headers = ['Accept: application/json'];
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_TIMEOUT => 25,
  ]);
  if ($json !== null) {
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $raw = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $body = substr($raw, $headerSize);
  curl_close($ch);
  return ['status'=>$status,'body'=>$body,'data'=>json_decode($body,true) ?: []];
}
$failed=0;
$check=function($n,$ok,$d='') use (&$failed){ echo ($ok?'PASS':'FAIL')." $n".(!$ok&&$d?" :: $d":'')."\n"; if(!$ok)$failed++; };

$anonCookie = sys_get_temp_dir().'/ys_p1_anon.txt'; @unlink($anonCookie);
$anon = req('GET', "$base/sk/activity", null, $anonCookie);
$check('anon_activity_401', $anon['status']===401, (string)$anon['status']);
$anon2Cookie = sys_get_temp_dir().'/ys_p1_anon2.txt'; @unlink($anon2Cookie);
$anon2 = req('GET', "$base/sk/deliveries/sms", null, $anon2Cookie);
$check('anon_sms_401', $anon2['status']===401, (string)$anon2['status']);

$login = req('POST', "$base/auth/login", json_encode(['email'=>'sk1@demo.test','password'=>'YouthSync1!']), $cookie);
$check('sk_login', $login['status']===200, (string)$login['status']);

$act = req('GET', "$base/sk/activity?perPage=20", null, $cookie);
$check('sk_activity_200', $act['status']===200, substr($act['body'],0,200));
$items = $act['data']['data']['items'] ?? [];
$check('sk_activity_items_array', is_array($items));

$sms = req('GET', "$base/sk/deliveries/sms?perPage=20", null, $cookie);
$check('sk_sms_deliveries_200', $sms['status']===200, substr($sms['body'],0,200));
$email = req('GET', "$base/sk/deliveries/email?perPage=20", null, $cookie);
$check('sk_email_deliveries_200', $email['status']===200, substr($email['body'],0,200));

$rep = req('GET', "$base/sk/reports/youth", null, $cookie);
$check('sk_reports_youth_200', $rep['status']===200, substr($rep['body'],0,200));

$sub = req('GET', "$base/sk/subscription", null, $cookie);
$check('sk_subscription_200', $sub['status']===200, substr($sub['body'],0,200));

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();
spl_autoload_register(static function ($class) {
  $prefix = 'YouthSync\\';
  if (!str_starts_with($class, $prefix)) return;
  $relative = substr($class, strlen($prefix));
  $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
  if (is_file($file)) require $file;
});
$pdo->prepare("DELETE FROM organizations WHERE email = :email")->execute(['email'=>'ys-expiry-probe@example.test']);
$pdo->prepare("INSERT INTO organizations (name, barangay, municipality, province, chairperson, email, contact, status, plan, sub_status, cycle, started_on, expires_at) VALUES ('Expiry Probe','Probe','Probe','Probe','Probe','ys-expiry-probe@example.test','09170000000','active','premium','trial','trial',CURDATE(),DATE_SUB(CURDATE(), INTERVAL 1 DAY))")->execute();
$orgId = (int)$pdo->lastInsertId();
$row = $pdo->query("SELECT id, plan, sub_status, cycle, expires_at FROM organizations WHERE id = {$orgId}")->fetch(PDO::FETCH_ASSOC);
$row = YouthSync\Org\PlanLimits::applyExpiry($pdo, $row);
$check('expiry_flipped', ($row['sub_status'] ?? '') === 'expired', json_encode($row));
$dbStatus = $pdo->query("SELECT sub_status FROM organizations WHERE id = {$orgId}")->fetchColumn();
$check('expiry_persisted', $dbStatus === 'expired', (string)$dbStatus);
$pdo->prepare('DELETE FROM organizations WHERE id = :id AND email = :email')->execute(['id'=>$orgId,'email'=>'ys-expiry-probe@example.test']);

echo $failed===0 ? "ALL PASSED\n" : "FAILED $failed\n";
exit($failed===0?0:1);