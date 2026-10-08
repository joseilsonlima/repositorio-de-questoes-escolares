<?php
declare(strict_types=1);
namespace School;
final class Admin {
    public function __construct(private Database $db) {}
    private function e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private function redirect(string $url): never { header('Location: '.$url,true,303); exit; }
    private function csrf(): void { if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) throw new ApiError(403,'csrf','Sessão inválida. Recarregue a página.'); }
    private function input(string $key,int $max=180): string {
        $v=$_POST[$key]??''; if (!is_string($v) || strlen($v)>$max) throw new ApiError(422,'invalid_field','Campo inválido.'); return trim($v);
    }
    private function formStart(string $action): string { return '<form method="post" action="'.$action.'"><input type="hidden" name="csrf" value="'.$this->e($_SESSION['csrf']).'">'; }
    private function layout(string $title,string $content): void {
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · Questões Escolares</title><link rel="stylesheet" href="/assets/admin.css"></head><body><header><a class="brand" href="/admin">Questões Escolares</a><span>Administração central</span></header><main>';
        if (isset($_SESSION['admin'])) echo '<nav><a href="/admin">Visão geral</a><a href="/admin/escolas">Escolas</a><a href="/admin/catalogo">Disciplinas e assuntos</a><a href="/admin/questoes">Questões</a><a href="/admin/auditoria">Auditoria</a>'.$this->formStart('/admin/sair').'<button class="secondary">Sair</button></form></nav>';
        echo '<h1>'.$this->e($title).'</h1>';
        if (isset($_SESSION['flash'])) { echo '<div class="notice">'.$this->e($_SESSION['flash']).'</div>'; unset($_SESSION['flash']); }
        echo $content.'</main><footer>Repositório central · Integração com SIGAP</footer></body></html>';
    }
    public function handle(string $path,string $method): void {
        session_name('school_repository_admin');
        ini_set('session.use_strict_mode','1');
        session_set_cookie_params(['httponly'=>true,'secure'=>getenv('SESSION_SECURE')!=='0','samesite'=>'Strict','path'=>'/']);
        if (!session_start()) throw new \RuntimeException('Armazenamento de sessões indisponível.');
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        if (isset($_SESSION['admin']) && time()-($_SESSION['last_active']??0)>1800) { unset($_SESSION['admin']); session_regenerate_id(true); }
        if (isset($_SESSION['admin'])) $_SESSION['last_active']=time();
        if ($path==='/') $this->redirect('/admin');
        if (!str_starts_with($path,'/admin')) throw new ApiError(404,'not_found','Página não encontrada.');
        if ($method==='POST') $this->csrf();
        if ($path==='/admin/entrar') {
            if ($method==='POST') $this->login();
            else $this->layout('Entrar no painel',$this->formStart('/admin/entrar').'<section class="login"><p>Acesso exclusivo para administradores do repositório.</p><label>E-mail<input type="email" name="email" autocomplete="username" required maxlength="180"></label><label>Senha<input type="password" name="password" autocomplete="current-password" required maxlength="1024"></label><button>Entrar</button></section></form>');
            return;
        }
        if (!isset($_SESSION['admin'])) $this->redirect('/admin/entrar');
        if ($method==='POST') { $this->mutate($path); return; }
        if ($method!=='GET') throw new ApiError(405,'method_not_allowed','Método não permitido.');
        if ($path==='/admin') {
            $cards=''; foreach(['schools'=>'Escolas integradas','questions'=>'Questões cadastradas','resolutions'=>'Resoluções registradas'] as $table=>$label) $cards.='<section class="metric"><span>'.$label.'</span><strong>'.$this->db->one("SELECT COUNT(*) AS n FROM $table")['n'].'</strong></section>';
            $this->layout('Visão geral','<div class="metrics">'.$cards.'</div><section><h2>Um banco compartilhado entre escolas</h2><p>Professores e alunos acessam pelo SIGAP. A primeira resolução bloqueia o conteúdo da questão e do texto associado.</p><p>O painel permite cadastrar escolas, organizar disciplinas e acompanhar questões e anulações.</p></section>');
        } elseif ($path==='/admin/escolas') {
            $html=$this->formStart('/admin/escolas').'<section><h2>Integrar uma escola</h2><label>Nome da escola<input name="name" maxlength="180" required></label><button>Cadastrar e gerar credencial</button></section></form>';
            if (isset($_SESSION['new_secret'])) { $html.='<section class="notice"><h2>Credencial gerada</h2><p>Copie para a configuração do servidor SIGAP. Este segredo será exibido uma única vez.</p><pre>'.$this->e($_SESSION['new_secret']).'</pre></section>'; unset($_SESSION['new_secret']); }
            $html.='<section><h2>Escolas cadastradas</h2><div class="table"><table><thead><tr><th>ID</th><th>Escola</th><th>Situação</th><th>Ação</th></tr></thead><tbody>';
            foreach($this->db->all('SELECT id,name,active FROM schools ORDER BY id DESC') as $s) $html.='<tr><td>'.$s['id'].'</td><td>'.$this->e($s['name']).'</td><td>'.($s['active']?'Ativa':'Desativada').'</td><td>'.$this->formStart('/admin/escolas/situacao').'<input type="hidden" name="id" value="'.$s['id'].'"><button class="secondary">'.($s['active']?'Desativar':'Ativar').'</button></form></td></tr>';
            $this->layout('Escolas',$html.'</tbody></table></div></section>');
        } elseif ($path==='/admin/catalogo') {
            $disciplines=$this->db->all('SELECT * FROM disciplines ORDER BY name'); $select=''; foreach($disciplines as $d) $select.='<option value="'.$d['id'].'">'.$this->e($d['name']).'</option>';
            $html='<div class="columns">'.$this->formStart('/admin/disciplinas').'<section><h2>Nova disciplina</h2><label>Nome<input name="name" required maxlength="140"></label><button>Cadastrar disciplina</button></section></form>'.$this->formStart('/admin/assuntos').'<section><h2>Novo assunto</h2><label>Disciplina<select name="discipline_id" required><option value="">Selecione</option>'.$select.'</select></label><label>Nome<input name="name" required maxlength="180"></label><button>Cadastrar assunto</button></section></form></div><section><h2>Catálogo compartilhado</h2>';
            foreach($disciplines as $d) { $html.='<h3>'.$this->e($d['name']).' <small>#'.$d['id'].'</small></h3><ul>'; foreach($this->db->all('SELECT * FROM subjects WHERE discipline_id=? ORDER BY name',[$d['id']]) as $s) $html.='<li>'.$this->e($s['name']).' <small>#'.$s['id'].'</small></li>'; $html.='</ul>'; }
            $this->layout('Disciplinas e assuntos',$html.'</section>');
        } elseif ($path==='/admin/questoes') {
            $page=max(1,min(100000,(int)($_GET['page']??1))); $offset=($page-1)*20;
            $rows=$this->db->all("SELECT q.*,u.name AS author_name FROM questions q JOIN users u ON u.id=q.author_id ORDER BY q.id DESC LIMIT 20 OFFSET $offset");
            $html=$rows?'':'<section>Nenhuma questão encontrada.</section>'; foreach($rows as $q) {
                $html.='<section><div class="question-top"><span>#'.$q['id'].' · '.$this->e($q['status']).'</span><span>'.($q['locked_at']?'Conteúdo bloqueado':'Sem resolução').'</span></div><p class="content">'.$this->e($q['statement']).'</p><p>Autor: '.$this->e($q['author_name']).' · Escola #'.$q['school_id'].'</p>';
                if ($q['text_id']) { $t=$this->db->one('SELECT title,content FROM associated_texts WHERE id=?',[$q['text_id']]); $html.='<details><summary>Texto associado: '.$this->e($t['title']).'</summary><p class="content">'.$this->e($t['content']).'</p></details>'; }
                foreach($this->db->all('SELECT * FROM alternatives WHERE question_id=? ORDER BY label',[$q['id']]) as $o) $html.='<p class="content">'.$this->e($o['label']).') '.$this->e($o['content']).'</p>';
                $html.='<p>Gabarito: '.$this->e($q['correct_option']??'Não definido').'</p>';
                if ($q['status']==='anulada') $html.='<p class="notice">Motivo: '.$this->e($q['annulment_reason']).'</p>';
                else $html.=$this->formStart('/admin/questoes/situacao').'<input type="hidden" name="id" value="'.$q['id'].'"><label>Ação<select name="status"><option value="anulada">Anular questão</option><option value="arquivada">Arquivar questão</option></select></label><label>Motivo da anulação<textarea name="reason" maxlength="4000"></textarea></label><button class="danger">Aplicar situação</button></form>';
                $html.='</section>';
            }
            $html.='<div class="pagination">'.($page>1?'<a href="?page='.($page-1).'">Anterior</a>':'').'<a href="?page='.($page+1).'">Próxima</a></div>';
            $this->layout('Questões',$html?:'<section>Nenhuma questão cadastrada.</section>');
        } elseif ($path==='/admin/auditoria') {
            $html='<section><div class="table"><table><thead><tr><th>Data (UTC)</th><th>Ação</th><th>Registro</th><th>Responsável</th><th>Detalhes</th></tr></thead><tbody>';
            foreach($this->db->all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 100') as $r) $html.='<tr><td>'.$this->e($r['created_at']).'</td><td>'.$this->e($r['action']).'</td><td>'.$this->e($r['entity']).' #'.$r['entity_id'].'</td><td>'.$this->e($r['actor_id']??'Administrador').'</td><td>'.$this->e($r['details']).'</td></tr>';
            $this->layout('Últimos 100 registros de auditoria',$html.'</tbody></table></div></section>');
        } else throw new ApiError(404,'not_found','Página não encontrada.');
    }
    private function login(): void {
        $email=strtolower($this->input('email')); $password=$_POST['password']??'';
        if (!is_string($password) || strlen($password)>1024) throw new ApiError(422,'invalid_field','Senha inválida.');
        $bucket=hash('sha256',($_SERVER['REMOTE_ADDR']??'').':'.$email);
        $allowed=$this->db->transaction(function() use($bucket) {
            $row=$this->db->one('SELECT * FROM login_limits WHERE bucket=?',[$bucket]);
            if ($row && time()-(int)$row['started_at']>=900) { $this->db->execute('DELETE FROM login_limits WHERE bucket=?',[$bucket]); $row=null; }
            if ($row && (int)$row['failures']>=10) return false;
            if (!$row) $this->db->insert('login_limits',['bucket'=>$bucket,'failures'=>1,'started_at'=>time()]); else $this->db->execute('UPDATE login_limits SET failures=failures+1 WHERE bucket=?',[$bucket]);
            return true;
        });
        if (!$allowed) throw new ApiError(429,'too_many_attempts','Muitas tentativas. Aguarde 15 minutos.');
        $admin=$this->db->one('SELECT * FROM admins WHERE email=?',[$email]);
        if (!$admin || !password_verify($password,$admin['password_hash'])) { $_SESSION['flash']='E-mail ou senha inválidos.'; $this->redirect('/admin/entrar'); }
        $this->db->execute('DELETE FROM login_limits WHERE bucket=?',[$bucket]);
        session_regenerate_id(true); $_SESSION['admin']=(int)$admin['id']; $_SESSION['last_active']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32)); $this->redirect('/admin');
    }
    private function mutate(string $path): void {
        if ($path==='/admin/sair') { $_SESSION=[]; session_destroy(); $this->redirect('/admin/entrar'); }
        if ($path==='/admin/escolas') {
            $name=$this->input('name'); if (!$name) throw new ApiError(422,'name_required','Informe o nome da escola.');
            $secret=bin2hex(random_bytes(32));
            $id=$this->db->transaction(function() use($name,$secret) { $id=$this->db->insert('schools',['name'=>$name,'secret_encrypted'=>Auth::encrypt($secret),'created_at'=>gmdate('Y-m-d H:i:s')]); $this->db->audit(null,'school.created','school',$id,['admin_id'=>$_SESSION['admin']]); return $id; });
            $_SESSION['new_secret']="school_id: $id\nsecret: $secret"; $this->redirect('/admin/escolas');
        }
        if ($path==='/admin/escolas/situacao') {
            $id=(int)$this->input('id'); $this->db->transaction(function() use($id) { $s=$this->db->lock('schools',$id); if (!$s) throw new ApiError(404,'not_found','Escola não encontrada.'); $this->db->execute('UPDATE schools SET active=? WHERE id=?',[$s['active']?0:1,$id]); $this->db->audit(null,'school.status','school',$id,['admin_id'=>$_SESSION['admin'],'active'=>!$s['active']]); }); $this->redirect('/admin/escolas');
        }
        if ($path==='/admin/disciplinas' || $path==='/admin/assuntos') {
            $name=$this->input('name',$path==='/admin/disciplinas'?140:180); if (!$name) throw new ApiError(422,'name_required','Informe o nome.');
            try { $this->db->transaction(function() use($name,$path) {
                $fields=['name'=>$name]; $table='disciplines';
                if ($path==='/admin/assuntos') { $table='subjects'; $id=(int)$this->input('discipline_id'); if (!$this->db->one('SELECT id FROM disciplines WHERE id=?',[$id])) throw new ApiError(422,'invalid_discipline','Disciplina inválida.'); $fields['discipline_id']=$id; }
                $id=$this->db->insert($table,$fields); $this->db->audit(null,'catalog.created',$table,$id,['admin_id'=>$_SESSION['admin']]);
            }); } catch(\PDOException $e) { if (str_starts_with((string)$e->getCode(),'23')) throw new ApiError(422,'duplicate_name','Esse nome já existe no catálogo.'); throw $e; }
            $this->redirect('/admin/catalogo');
        }
        if ($path==='/admin/questoes/situacao') {
            (new Repository($this->db))->status(['role'=>'admin','id'=>$_SESSION['admin']],(int)$this->input('id'),$this->input('status'),$this->input('reason',4000)); $this->redirect('/admin/questoes');
        }
        throw new ApiError(404,'not_found','Operação não encontrada.');
    }
}
