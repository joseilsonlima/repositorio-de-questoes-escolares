<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use School\{Database,Schema,Auth};
try {
    $command=$argv[1]??'';
    if ($command==='key') { echo base64_encode(random_bytes(32)).PHP_EOL; exit; }
    $db=new Database();
    if ($command==='migrate') { Schema::migrate($db); echo "Estrutura criada.\n"; }
    elseif ($command==='admin:create') {
        $email=strtolower(trim($argv[2]??'')); if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
        // Nunca recebe a senha como argumento ou a grava em logs/histórico do shell.
        $password=getenv('ADMIN_PASSWORD');
        if ($password===false) { if (function_exists('system')) system('stty -echo'); fwrite(STDERR,'Senha (mínimo 12 caracteres): '); try { $password=rtrim(fgets(STDIN)?:'',"\r\n"); } finally { if (function_exists('system')) system('stty echo'); fwrite(STDERR,"\n"); } }
        if (strlen($password)<12) throw new RuntimeException('Use uma senha com pelo menos 12 caracteres.');
        $db->insert('admins',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]); echo "Administrador criado.\n";
    } else { echo "Comandos: key | migrate | admin:create email\n"; exit(1); }
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
