<?php
declare(strict_types=1);
namespace School;
final class Auth {
    public function __construct(private Database $db) {}
    private static function key(): string {
        $key = base64_decode(getenv('APP_KEY') ?: '', true);
        if ($key === false || strlen($key) !== 32) throw new \RuntimeException('APP_KEY precisa conter 32 bytes em base64.');
        return $key;
    }
    public static function encrypt(string $secret): string {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new \RuntimeException('Falha ao proteger credencial.');
        return base64_encode($iv . $tag . $cipher);
    }
    private static function decrypt(string $encoded): string {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 29) throw new \RuntimeException('Credencial inválida.');
        $value = openssl_decrypt(substr($data,28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($data,0,12), substr($data,12,16));
        if ($value === false) throw new \RuntimeException('Credencial inválida.');
        return $value;
    }
    public static function encode(array $claims, string $secret): string {
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64('{"alg":"HS256","typ":"JWT"}') . '.' . $b64(json_encode($claims, JSON_THROW_ON_ERROR));
        return $unsigned . '.' . $b64(hash_hmac('sha256',$unsigned,$secret,true));
    }
    public function authenticate(string $authorization): array {
        try {
            if (!preg_match('/^Bearer ([A-Za-z0-9_.-]+)$/', $authorization, $m)) throw new \Exception();
            $parts = explode('.', $m[1]); if (count($parts) !== 3) throw new \Exception();
            $decode = function(string $s): array { $raw = base64_decode(strtr($s,'-_','+/'),true); if ($raw === false) throw new \Exception(); $v=json_decode($raw,true,16,JSON_THROW_ON_ERROR); if (!is_array($v)) throw new \Exception(); return $v; };
            $header = $decode($parts[0]); $c = $decode($parts[1]);
            if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') throw new \Exception();
            if (!is_int($c['school_id'] ?? null) || !is_string($c['sub'] ?? null) || !preg_match('/^[A-Za-z0-9@._:-]{1,128}$/', $c['sub'])) throw new \Exception();
            $school = $this->db->one('SELECT * FROM schools WHERE id = ? AND active = 1', [$c['school_id']]);
            if (!$school) throw new \Exception();
            $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], self::decrypt($school['secret_encrypted']), true)), '+/', '-_'),'=');
            if (!hash_equals($sig,$parts[2])) throw new \Exception();
            $now = time();
            if (($c['iss'] ?? '') !== 'sigap' || ($c['aud'] ?? '') !== 'repositorio-escolar' || !in_array($c['role'] ?? '', ['professor','aluno'],true) || !is_int($c['iat'] ?? null) || !is_int($c['exp'] ?? null) || $c['iat'] > $now+30 || $c['exp'] <= $now || $c['exp']-$c['iat'] > 300 || $c['exp'] <= $c['iat']) throw new \Exception();
            $name = is_string($c['name'] ?? null) ? trim($c['name']) : $c['sub'];
            if ($name === '' || strlen($name)>180) throw new \Exception();
        } catch (\Throwable $e) { throw new ApiError(401,'unauthenticated','Credencial da integração inválida ou expirada.'); }
        return $this->db->transaction(function() use($c,$name,$school) {
            // Serializa o primeiro cadastro de uma identidade por escola.
            $current=$this->db->lock('schools',(int)$school['id']);
            if (!$current || !(int)$current['active'] || $current['secret_encrypted']!==$school['secret_encrypted']) throw new ApiError(401,'unauthenticated','Integração desativada ou credencial alterada.');
            $u = $this->db->one('SELECT * FROM users WHERE school_id = ? AND external_id = ?', [$c['school_id'],$c['sub']]);
            if ($u && $u['role'] !== $c['role']) throw new ApiError(403,'identity_role_conflict','Identidade já vinculada a outro perfil.');
            if (!$u) { $id=$this->db->insert('users',['school_id'=>$c['school_id'],'external_id'=>$c['sub'],'role'=>$c['role'],'name'=>$name]); $u=$this->db->one('SELECT * FROM users WHERE id = ?',[$id]); }
            return $u;
        });
    }
}
