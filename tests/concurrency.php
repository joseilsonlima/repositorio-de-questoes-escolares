<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use School\{Database,Schema,Auth,Repository,ApiError};
putenv('APP_KEY='.base64_encode(str_repeat('t',32)));
function connection(): Database { return new Database(new PDO(getenv('TEST_DB_DSN'),getenv('TEST_DB_USER')?:'root',getenv('TEST_DB_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false])); }
if (!getenv('TEST_DB_DSN')) { echo "Teste de concorrência exige TEST_DB_DSN MySQL em banco descartável.\n"; exit(1); }
if (($argv[1]??'')==='worker') {
    $task=json_decode(base64_decode($argv[2]),true,32,JSON_THROW_ON_ERROR);
    $db=connection(); $r=new Repository($db);
    file_put_contents($task['ready'],'ready');
    try { $result=$r->resolve($task['user'],$task['data']); echo json_encode(['result'=>$result]); }
    catch(ApiError $e) { echo json_encode(['error'=>$e->errorCode]); }
    exit;
}
$db=connection(); Schema::migrate($db);
$school=$db->insert('schools',['name'=>'Concorrência','secret_encrypted'=>Auth::encrypt('test'),'created_at'=>gmdate('Y-m-d H:i:s')]);
$teacherId=$db->insert('users',['school_id'=>$school,'external_id'=>'teacher','role'=>'professor','name'=>'Professor']);
$studentId=$db->insert('users',['school_id'=>$school,'external_id'=>'student','role'=>'aluno','name'=>'Aluno']);
$teacher=$db->one('SELECT * FROM users WHERE id=?',[$teacherId]); $student=$db->one('SELECT * FROM users WHERE id=?',[$studentId]);
$d=$db->insert('disciplines',['name'=>'Concorrência '.bin2hex(random_bytes(6))]); $subject=$db->insert('subjects',['discipline_id'=>$d,'name'=>'Teste']);
$r=new Repository($db);
$text=$r->textCreate($teacher,['title'=>'Texto','content'=>'Conteúdo original']);
$id=$r->create($teacher,['discipline_id'=>$d,'subject_id'=>$subject,'text_id'=>(int)$text['id'],'statement'=>'Pergunta','alternatives'=>['Um','Dois'],'correct_option'=>'A'])['id'];
$r->status($teacher,$id,'publicada');
$data=['question_id'=>$id,'selected_option'=>'A','content_hash'=>$r->get($student,$id)['content_hash'],'attempt_id'=>'concurrent'];
function start(array $user,array $data): array {
    $file=tempnam(sys_get_temp_dir(),'ready'); unlink($file);
    $task=base64_encode(json_encode(['user'=>$user,'data'=>$data,'ready'=>$file],JSON_THROW_ON_ERROR));
    $process=proc_open([getenv('TEST_PHP_BIN')?:PHP_BINARY,__FILE__,'worker',$task],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Não foi possível iniciar worker.'); fclose($pipes[0]);
    return [$process,$pipes,$file];
}
function ready(array $worker): void {
    $deadline=microtime(true)+5; while (!is_file($worker[2]) && microtime(true)<$deadline) usleep(10000);
    if (!is_file($worker[2])) throw new RuntimeException('Worker não iniciou.');
}
function finish(array $worker): array {
    [$process,$pipes,$file]=$worker; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process); @unlink($file);
    if ($code!==0) throw new RuntimeException('Worker falhou: '.$err.$out);
    return json_decode($out,true,32,JSON_THROW_ON_ERROR);
}
// Força snapshot antigo: resolução inicia enquanto a edição mantém o lock do texto.
$worker=null;
$db->transaction(function() use($db,$text,$student,$data,&$worker) {
    $db->lock('associated_texts',(int)$text['id']);
    $worker=start($student,$data); ready($worker); usleep(200000);
    $db->execute('UPDATE associated_texts SET content=? WHERE id=?',['Conteúdo atualizado',(int)$text['id']]);
});
$result=finish($worker);
if (($result['error']??null)!=='content_changed') throw new RuntimeException('Resposta obsoleta foi aceita durante edição concorrente.');
if ($r->get($teacher,$id)['locked_at']!==null) throw new RuntimeException('Resposta rejeitada bloqueou a questão.');
echo "OK edição concorrente do texto rejeita hash antigo sem bloquear\n";
// Dois reenvios simultâneos da mesma tentativa devem criar exatamente uma resolução.
$data['content_hash']=$r->get($student,$id)['content_hash'];
$first=start($student,$data); $second=start($student,$data); $a=finish($first); $b=finish($second);
if (!isset($a['result'],$b['result']) || $a['result']['id']!==$b['result']['id'] || $a['result']['replayed']===$b['result']['replayed']) throw new RuntimeException('Reenvio concorrente não foi idempotente.');
if ((int)$db->one('SELECT COUNT(*) AS n FROM resolutions WHERE question_id=?',[$id])['n']!==1) throw new RuntimeException('Tentativa duplicada.');
echo "OK reenvios concorrentes gravam uma única resolução\n";
try { $r->update($teacher,$id,['statement'=>'Mudança proibida']); throw new RuntimeException('Edição aceita após resolução.'); } catch(ApiError $e) { if ($e->errorCode!=='content_locked') throw $e; }
echo "OK conteúdo permanece bloqueado após resolução concorrente\n";
