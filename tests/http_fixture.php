<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use School\{Database,Schema,Auth};
$db=new Database(); Schema::migrate($db);
$school=$db->insert('schools',['name'=>'Escola HTTP','secret_encrypted'=>Auth::encrypt('test-secret'),'created_at'=>gmdate('Y-m-d H:i:s')]);
$d=$db->insert('disciplines',['name'=>'Matemática']); $s=$db->insert('subjects',['discipline_id'=>$d,'name'=>'Operações']);
$db->insert('admins',['email'=>'admin@example.test','password_hash'=>password_hash('test-password-123',PASSWORD_DEFAULT)]);
$tokens=[];
foreach(['teacher'=>'professor','other'=>'professor','student'=>'aluno'] as $sub=>$role) $tokens[$sub]=Auth::encode(['iss'=>'sigap','aud'=>'repositorio-escolar','school_id'=>$school,'sub'=>$sub,'role'=>$role,'name'=>$sub,'iat'=>time(),'exp'=>time()+300],'test-secret');
echo json_encode(['tokens'=>$tokens,'discipline'=>$d,'subject'=>$s]);
