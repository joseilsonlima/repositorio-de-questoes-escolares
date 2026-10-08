<?php
declare(strict_types=1);
ini_set('display_errors','0');
error_reporting(E_ALL);
require dirname(__DIR__).'/app/bootstrap.php';
use School\{Database,Auth,Repository,ApiError,Admin};
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';
try {
    $db=new Database();
    if (!str_starts_with($path,'/api/')) { (new Admin($db))->handle($path,$_SERVER['REQUEST_METHOD']); exit; }
    header('Content-Type: application/json; charset=utf-8');
    $u=(new Auth($db))->authenticate($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    $repo=new Repository($db); $method=$_SERVER['REQUEST_METHOD']; $body=[];
    if (in_array($method,['POST','PATCH'],true)) {
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) throw new ApiError(415,'json_required','Envie Content-Type: application/json.');
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>200000) throw new ApiError(413,'payload_too_large','Requisição excede o limite.');
        $raw=file_get_contents('php://input',false,null,0,200001);
        if (strlen($raw)>200000) throw new ApiError(413,'payload_too_large','Requisição excede o limite.');
        try { $object=json_decode($raw,false,32,JSON_THROW_ON_ERROR); if (!is_object($object)) throw new \Exception(); $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new ApiError(400,'invalid_json','Informe um objeto JSON válido.'); }
    }
    $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
    if ($page===false) throw new ApiError(422,'invalid_page','Página inválida.');
    $status=200;
    if ($method==='GET' && $path==='/api/v1/disciplinas') $result=['data'=>$db->all('SELECT * FROM disciplines ORDER BY name')];
    elseif ($method==='GET' && $path==='/api/v1/assuntos') {
        $d=filter_var($_GET['discipline_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if (!$d) throw new ApiError(422,'discipline_required','Informe discipline_id.');
        $result=['data'=>$db->all('SELECT * FROM subjects WHERE discipline_id=? ORDER BY name',[$d])];
    }
    elseif ($path==='/api/v1/questoes' && $method==='GET') $result=$repo->list($u,$_GET,$page);
    elseif ($path==='/api/v1/questoes' && $method==='POST') { $result=$repo->create($u,$body); $status=201; }
    elseif (preg_match('#^/api/v1/questoes/([1-9][0-9]*)$#',$path,$m) && $method==='GET') $result=$repo->get($u,(int)$m[1]);
    elseif (preg_match('#^/api/v1/questoes/([1-9][0-9]*)$#',$path,$m) && $method==='PATCH') $result=$repo->update($u,(int)$m[1],$body);
    elseif (preg_match('#^/api/v1/questoes/([1-9][0-9]*)/situacao$#',$path,$m) && $method==='POST') {
        if (!is_string($body['status']??null) || !is_string($body['reason']??'')) throw new ApiError(422,'invalid_status','Informe status e motivo textual.');
        $result=$repo->status($u,(int)$m[1],$body['status'],$body['reason']??'');
    }
    elseif ($path==='/api/v1/textos' && $method==='GET') $result=$repo->texts($u,is_string($_GET['search']??'')?substr($_GET['search']??'',0,180):'',$page);
    elseif ($path==='/api/v1/textos' && $method==='POST') { $result=$repo->textCreate($u,$body); $status=201; }
    elseif (preg_match('#^/api/v1/textos/([1-9][0-9]*)$#',$path,$m) && $method==='PATCH') $result=$repo->textUpdate($u,(int)$m[1],$body);
    elseif ($path==='/api/v1/resolucoes' && $method==='GET') $result=$repo->history($u,$page);
    elseif ($path==='/api/v1/resolucoes' && $method==='POST') { $result=$repo->resolve($u,$body); $status=$result['replayed']?200:201; }
    else throw new ApiError(404,'route_not_found','Rota não encontrada.');
    http_response_code($status); echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch (ApiError $e) {
    http_response_code($e->status);
    if (str_starts_with($path,'/api/')) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],JSON_UNESCAPED_UNICODE); }
    else { header('Content-Type: text/html; charset=utf-8'); echo '<p>'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</p><a href="/admin">Voltar</a>'; }
} catch (\Throwable $e) {
    $incident=bin2hex(random_bytes(6)); error_log("[$incident] ".$e);
    http_response_code(500);
    if (str_starts_with($path,'/api/')) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>['code'=>'internal_error','message'=>'Falha interna.','incident'=>$incident]]); }
    else echo 'Falha interna. Referência: '.htmlspecialchars($incident);
}
