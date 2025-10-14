#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bulk_emailer.php — prod-ready bulk sender
 * - SMTP or SendGrid via .env
 * - Auto {{today}} (Africa/Lagos) and {{ref_id}}
 * - INTERNAL_DIRECTIVE=true => no List-Unsubscribe
 * - GLOBAL_CC / GLOBAL_BCC (.env) + per-row cc/bcc columns
 */

chdir(__DIR__);
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use SendGrid\Mail\Mail as SGMail;

// ---------------------- env & utils ----------------------
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

function envv(string $k, $d=null){ return $_ENV[$k] ?? $d; }
function parse_email_list(?string $s): array {
  $out = [];
  foreach (explode(',', (string)$s) as $raw) {
    $em = trim($raw);
    if ($em && filter_var($em, FILTER_VALIDATE_EMAIL)) $out[] = $em;
  }
  return $out;
}
function log_line(string $msg): void {
  $tz  = new DateTimeZone(envv('TIMEZONE','Africa/Lagos'));
  $ts  = (new DateTimeImmutable('now',$tz))->format('Y-m-d H:i:s');
  $log = envv('LOG_PATH', __DIR__.'/logs/mailer.log');
  if (!is_dir(dirname($log))) mkdir(dirname($log),0775,true);
  file_put_contents($log, "[$ts] $msg\n", FILE_APPEND);
}
function build_body(string $tpl, array $fields): string {
  $out = $tpl;
  foreach($fields as $k=>$v){
    $out = str_replace('{{'.$k.'}}', htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'), $out);
  }
  // common aliases
  $out = str_replace('{{name}}', htmlspecialchars((string)($fields['name'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'), $out);
  return $out;
}

// ---------------------- config ----------------------
$dryRun     = filter_var(envv('DRY_RUN','false'), FILTER_VALIDATE_BOOLEAN);
$testMode   = filter_var(envv('TEST_MODE','false'), FILTER_VALIDATE_BOOLEAN);
$transport  = strtolower((string) envv('MAIL_TRANSPORT','smtp')); // smtp | sendgrid
$subject    = (string) envv('SUBJECT','');
$template   = (string) envv('TEMPLATE_PATH','templates/notice.html');
$csvPath    = (string) envv('RECIPIENTS_CSV','data/recipients.csv');
$ratePerMin = (int) envv('RATE_LIMIT_PER_MINUTE', 30);
$maxRetries = (int) envv('MAX_RETRIES', 2);
$retryDelay = (int) envv('RETRY_DELAY_SECONDS', 5);
$from       = (string) envv('MAIL_FROM','');
$fromName   = (string) envv('MAIL_FROM_NAME','');
$replyTo    = (string) envv('MAIL_REPLY_TO','');
$internal   = filter_var(envv('INTERNAL_DIRECTIVE','false'), FILTER_VALIDATE_BOOLEAN);

if (!$subject || !is_file($template)) { fwrite(STDERR,"Missing SUBJECT or TEMPLATE_PATH.\n"); exit(1); }
$htmlTpl = file_get_contents($template);

// ---------------------- recipients ----------------------
$recs = [];
$csvHas = ['cc'=>false,'bcc'=>false];

if ($testMode) {
  $recs[] = ['email'=>envv('TEST_TO','test@example.org'), 'name'=>'Test User', 'fields'=>[]];
} else {
  if (!is_file($csvPath)) { fwrite(STDERR,"Recipients CSV not found: $csvPath\n"); exit(1); }
  if (($h=fopen($csvPath,'r'))===false){ fwrite(STDERR,"Cannot open CSV.\n"); exit(1); }
  $header = fgetcsv($h);
  if (!$header) { fwrite(STDERR,"CSV header missing.\n"); exit(1); }
  $norm = array_map(fn($c)=>strtolower(trim((string)$c)),$header);
  $iEmail = array_search('email',$norm);
  $iName  = array_search('name',$norm);
  $csvHas['cc']  = array_search('cc',$norm)  !== false;
  $csvHas['bcc'] = array_search('bcc',$norm) !== false;
  if ($iEmail===false){ fwrite(STDERR,"CSV must include 'email' column.\n"); exit(1); }

  while(($row=fgetcsv($h))!==false){
    $email = trim($row[$iEmail] ?? '');
    if ($email && filter_var($email,FILTER_VALIDATE_EMAIL)){
      $name   = trim($iName!==false ? ($row[$iName] ?? '') : '');
      $fields = [];
      foreach($header as $i=>$col){ $fields[strtolower((string)$col)] = $row[$i] ?? ''; }
      $recs[] = ['email'=>$email,'name'=>$name,'fields'=>$fields];
    }
  }
  fclose($h);
}
if (!$recs){ fwrite(STDERR,"No valid recipients.\n"); exit(1); }

// ---------------------- runtime tokens ----------------------
$tz = new DateTimeZone(envv('TIMEZONE','Africa/Lagos'));
$todayHuman = (new DateTimeImmutable('now',$tz))->format('d M Y'); // e.g., 14 Oct 2025
$dayStamp   = (new DateTimeImmutable('now',$tz))->format('Ymd');   // e.g., 20251014

$interval = max(60 / max(1,$ratePerMin), 0.0);
$lastSend = 0.0;

$sent=0; $fail=0; $errs=[];

foreach($recs as $idx=>$_r){
  $email  = $_r['email'];
  $name   = $_r['name'] ?? '';
  $fields = $_r['fields'] ?? [];

  // Auto inject tokens if missing
  if (empty($fields['today']))  { $fields['today']  = $todayHuman; }
  if (empty($fields['ref_id'])) { $fields['ref_id'] = sprintf('%s-%04d', $dayStamp, $idx+1); }

  $bodyHtml = build_body($htmlTpl, array_merge($fields, ['name'=>$name, 'email'=>$email]));

  // Unsubscribe header (disabled for internal directives)
  $listUnsub = '';
  if (!$internal) {
    $unsubBase = (string) envv('UNSUB_BASE','');
    if ($unsubBase) { $listUnsub = '<'.rtrim($unsubBase,'/').'/'.urlencode($email).'>'; }
  }

  // rate limit
  $now = microtime(true); $sleep = $interval - ($now - $lastSend);
  if ($sleep > 0) usleep((int)($sleep*1_000_000));

  // per-row CC/BCC from CSV (if present)
  $ccRow  = $csvHas['cc']  ? ($fields['cc']  ?? '') : '';
  $bccRow = $csvHas['bcc'] ? ($fields['bcc'] ?? '') : '';

  // global copies
  $globalCC  = parse_email_list(envv('GLOBAL_CC',''));
  $globalBCC = parse_email_list(envv('GLOBAL_BCC',''));

  $ok=false; $attempt=0; $errMsg='';
  if ($dryRun){ log_line("DRY_RUN: would send to $email"); $sent++; $lastSend=microtime(true); continue; }

  while(!$ok && $attempt <= $maxRetries){
    $attempt++;
    try {
      if ($transport==='smtp'){
        $m = new PHPMailer(true);
        $secure = strtolower((string) envv('SMTP_SECURE','tls'));
        $m->isSMTP();
        $m->Host     = envv('SMTP_HOST');
        $m->Port     = (int) envv('SMTP_PORT',587);
        $m->SMTPAuth = true;
        $m->Username = envv('SMTP_USER');
        $m->Password = envv('SMTP_PASS');
        if ($secure==='ssl'){ $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; }
        elseif ($secure==='tls'){ $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; }

        $m->setFrom($from, $fromName);
        if ($replyTo) $m->addReplyTo($replyTo);
        $m->addAddress($email, $name);

        // CC/BCC: global + per-row
        foreach ($globalCC as $cc)  { $m->addCC($cc); }
        foreach ($globalBCC as $bc) { $m->addBCC($bc); }
        foreach (parse_email_list($ccRow)  as $cc) { $m->addCC($cc); }
        foreach (parse_email_list($bccRow) as $bc) { $m->addBCC($bc); }

        if ($listUnsub) $m->addCustomHeader('List-Unsubscribe', $listUnsub);

        // optional DKIM
        if (envv('DKIM_DOMAIN')){
          $m->DKIM_domain = envv('DKIM_DOMAIN');
          $m->DKIM_selector = envv('DKIM_SELECTOR');
          $m->DKIM_private_string = @file_get_contents((string) envv('DKIM_PRIVATE_KEY_PATH')) ?: '';
          $m->DKIM_passphrase = envv('DKIM_PASSPHRASE','');
        }

        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body    = $bodyHtml;
        $m->AltBody = strip_tags($bodyHtml);
        $m->send();

      } else { // sendgrid
        $sg = new \SendGrid(envv('SENDGRID_API_KEY'));
        $msg = new SGMail();
        $msg->setFrom($from, $fromName);
        if ($replyTo) $msg->setReplyTo($replyTo);
        $msg->setSubject($subject);
        $msg->addTo($email, $name, $fields);
        $msg->addContent('text/plain', strip_tags($bodyHtml));
        $msg->addContent('text/html', $bodyHtml);

        // CC/BCC: global + per-row
        foreach ($globalCC as $cc)  { $msg->addCc($cc); }
        foreach ($globalBCC as $bc) { $msg->addBcc($bc); }
        foreach (parse_email_list($ccRow)  as $cc) { $msg->addCc($cc); }
        foreach (parse_email_list($bccRow) as $bc) { $msg->addBcc($bc); }

        if ($listUnsub) $msg->addHeader('List-Unsubscribe', $listUnsub);

        $resp = $sg->send($msg);
        $code = $resp->statusCode();
        if ($code < 200 || $code >= 300) {
          throw new RuntimeException('SendGrid non-2xx: '.$code.' '.$resp->body());
        }
      }
      $ok=true;

    } catch (\Throwable $e){
      $errMsg = $e->getMessage();
      if ($attempt <= $maxRetries) sleep($retryDelay);
    }
  }

  if ($ok){ $sent++; log_line("SENT $email"); }
  else    { $fail++; $errs[] = [$email,$errMsg]; log_line("FAIL $email :: $errMsg"); }

  $lastSend = microtime(true);
}

// summary
$sum = "Done. Sent: $sent | Failed: $fail";
log_line($sum);
echo $sum, PHP_EOL;
if ($fail>0){ foreach($errs as [$em,$er]) fwrite(STDERR,"FAIL $em :: $er\n"); }
