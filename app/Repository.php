<?php
declare(strict_types=1);
namespace School;
final class Repository {
    public function __construct(private Database $db) {}
    private function teacher(array $u): void { if ($u['role'] !== 'professor') throw new ApiError(403,'forbidden','Operação exclusiva de professor.'); }
    private function owner(array $u, array $q): void { $this->teacher($u); if ((int)$q['author_id'] !== (int)$u['id']) throw new ApiError(403,'not_owner','Você pode alterar apenas conteúdo de sua autoria.'); }
    private function string(array $data,string $key,int $max=60000): string {
        if (!is_string($data[$key] ?? null) || strlen($data[$key])>$max) throw new ApiError(422,'invalid_field',"Campo $key inválido.");
        return trim($data[$key]);
    }
    private function optionalId(mixed $value): ?int {
        if ($value === null) return null;
        if (!is_int($value) || $value<1) throw new ApiError(422,'invalid_id','Identificador inválido.');
        return $value;
    }
    private function only(array $data,array $keys): void {
        if (array_diff(array_keys($data),$keys)) throw new ApiError(422,'unknown_field','Há campos não permitidos na requisição.');
    }
    public function textCreate(array $u,array $data): array {
        $this->teacher($u); $this->only($data,['title','content','source','work_author']);
        $fields=['author_id'=>$u['id'],'title'=>$this->string($data,'title',180),'content'=>$this->string($data,'content'),'source'=>$this->string($data+['source'=>''],'source',4000),'work_author'=>$this->string($data+['work_author'=>''],'work_author',180),'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')];
        if (!$fields['title'] || !$fields['content']) throw new ApiError(422,'incomplete_text','Título e conteúdo são obrigatórios.');
        return $this->db->transaction(function() use($fields,$u) { $id=$this->db->insert('associated_texts',$fields); $this->db->audit((int)$u['id'],'text.created','text',$id); return $this->text($id); });
    }
    public function text(int $id): array {
        $t=$this->db->one('SELECT * FROM associated_texts WHERE id = ?',[$id]);
        if (!$t) throw new ApiError(404,'not_found','Texto não encontrado.');
        return $t;
    }
    public function texts(array $u,string $search,int $page): array {
        $this->teacher($u); $offset=($page-1)*20;
        return ['data'=>$this->db->all("SELECT t.*, u.name AS registered_by FROM associated_texts t JOIN users u ON u.id = t.author_id WHERE t.title LIKE ? OR t.content LIKE ? ORDER BY t.id DESC LIMIT 20 OFFSET $offset",['%'.$search.'%','%'.$search.'%']),'page'=>$page,'per_page'=>20];
    }
    public function textUpdate(array $u,int $id,array $data): array {
        $this->teacher($u); $this->only($data,['title','content','source','work_author']);
        return $this->db->transaction(function() use($u,$id,$data) {
            $t=$this->db->lock('associated_texts',$id); if (!$t) throw new ApiError(404,'not_found','Texto não encontrado.');
            $this->owner($u,$t); if ($t['locked_at']) throw new ApiError(409,'content_locked','Texto utilizado em questão respondida; seu conteúdo está bloqueado.');
            $merged=array_replace($t,$data);
            $fields=['title'=>$this->string($merged,'title',180),'content'=>$this->string($merged,'content'),'source'=>$this->string($merged,'source',4000),'work_author'=>$this->string($merged,'work_author',180)];
            if (!$fields['title'] || !$fields['content']) throw new ApiError(422,'incomplete_text','Título e conteúdo são obrigatórios.');
            $this->db->execute('UPDATE associated_texts SET title=?, content=?, source=?, work_author=?, updated_at=? WHERE id=?',[...array_values($fields),gmdate('Y-m-d H:i:s'),$id]);
            $this->db->audit((int)$u['id'],'text.updated','text',$id); return $this->text($id);
        });
    }
    private function normalizeQuestion(array $data): array {
        $fields=['discipline_id'=>$this->optionalId($data['discipline_id']??null),'subject_id'=>$this->optionalId($data['subject_id']??null),'text_id'=>$this->optionalId($data['text_id']??null),'statement'=>$this->string($data+['statement'=>''],'statement'),'correct_option'=>$data['correct_option']??null];
        if ($fields['discipline_id'] && !$this->db->one('SELECT id FROM disciplines WHERE id=?',[$fields['discipline_id']])) throw new ApiError(422,'invalid_discipline','Disciplina inexistente.');
        if ($fields['subject_id'] && !$this->db->one('SELECT id FROM subjects WHERE id=? AND discipline_id=?',[$fields['subject_id'],$fields['discipline_id']])) throw new ApiError(422,'invalid_subject','Assunto não pertence à disciplina.');
        if ($fields['text_id']) $this->text($fields['text_id']);
        $options=array_key_exists('alternatives',$data)?$data['alternatives']:[];
        if (!is_array($options) || !array_is_list($options) || count($options)>5) throw new ApiError(422,'invalid_alternatives','Informe até cinco alternativas em uma lista.');
        $normalized=[];
        foreach ($options as $index=>$o) {
            if (!is_string($o) || strlen($o)>20000) throw new ApiError(422,'invalid_alternatives','Alternativas devem ser textos.');
            $normalized[chr(65+$index)]=trim($o);
        }
        if ($fields['correct_option']!==null && (!is_string($fields['correct_option']) || !array_key_exists($fields['correct_option'],$normalized))) throw new ApiError(422,'invalid_answer','Gabarito deve apontar para uma alternativa cadastrada.');
        return [$fields,$normalized];
    }
    private function validatePublish(array $q,array $options): void {
        if (!$q['discipline_id'] || !$q['subject_id'] || trim($q['statement'])==='' || count($options)<2 || count($options)>5 || in_array('',array_values($options),true) || !$q['correct_option'] || !isset($options[$q['correct_option']])) throw new ApiError(422,'incomplete_question','Para publicar, preencha disciplina, assunto, enunciado, de duas a cinco alternativas e gabarito.');
        if (count(array_unique(array_values($options))) !== count($options)) throw new ApiError(422,'duplicate_alternatives','As alternativas devem ser distintas.');
    }
    private function options(int $id): array {
        $result=[]; foreach($this->db->all('SELECT label,content FROM alternatives WHERE question_id=? ORDER BY label' . ($this->db->sqlite ? '' : ' FOR UPDATE'),[$id]) as $r) $result[$r['label']]=$r['content']; return $result;
    }
    private function saveOptions(int $id,array $options): void {
        $this->db->execute('DELETE FROM alternatives WHERE question_id=?',[$id]);
        foreach($options as $label=>$content) $this->db->insert('alternatives',['question_id'=>$id,'label'=>$label,'content'=>$content]);
    }
    public function create(array $u,array $data): array {
        $this->teacher($u); $this->only($data,['discipline_id','subject_id','text_id','statement','correct_option','alternatives']);
        return $this->db->transaction(function() use($u,$data) {
            [$fields,$options]=$this->normalizeQuestion($data);
            if ($fields['text_id']) $this->db->lock('associated_texts',$fields['text_id']);
            $id=$this->db->insert('questions',$fields+['author_id'=>$u['id'],'school_id'=>$u['school_id'],'status'=>'rascunho','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]);
            $this->saveOptions($id,$options); $this->db->audit((int)$u['id'],'question.created','question',$id); return ['id'=>$id,'status'=>'rascunho'];
        });
    }
    // Todas as operações que dependem do conteúdo travam os textos primeiro e a questão depois.
    private function lockQuestion(int $id,?int $newText=null): array {
        $before=$this->db->one('SELECT * FROM questions WHERE id=?',[$id]); if (!$before) throw new ApiError(404,'not_found','Questão não encontrada.');
        $texts=array_unique(array_filter([(int)$before['text_id'],$newText])); sort($texts);
        foreach($texts as $text) if (!$this->db->lock('associated_texts',(int)$text)) throw new ApiError(422,'invalid_text','Texto não encontrado.');
        $q=$this->db->lock('questions',$id);
        if ((int)$q['text_id'] !== (int)$before['text_id']) throw new ApiError(409,'concurrent_change','Questão alterada durante a operação. Tente novamente.');
        return $q;
    }
    public function update(array $u,int $id,array $data): array {
        $this->teacher($u); $this->only($data,['discipline_id','subject_id','text_id','statement','correct_option','alternatives']);
        $newText=array_key_exists('text_id',$data) ? $this->optionalId($data['text_id']) : null;
        return $this->db->transaction(function() use($u,$id,$data,$newText) {
            $q=$this->lockQuestion($id,$newText); $this->owner($u,$q);
            if ($q['locked_at']) throw new ApiError(409,'content_locked','Questão já respondida por aluno. Cadastre uma nova questão para corrigir o conteúdo.');
            if ($q['status']==='anulada') throw new ApiError(409,'annulled','Questão anulada não permite edição.');
            $merged=array_replace($q,$data); $merged['alternatives']=array_key_exists('alternatives',$data)?$data['alternatives']:array_values($this->options($id));
            [$fields,$options]=$this->normalizeQuestion($merged);
            if ($q['status']==='publicada') $this->validatePublish($fields,$options);
            $this->db->execute('UPDATE questions SET discipline_id=?,subject_id=?,text_id=?,statement=?,correct_option=?,updated_at=? WHERE id=?',[...array_values($fields),gmdate('Y-m-d H:i:s'),$id]);
            $this->saveOptions($id,$options); $this->db->audit((int)$u['id'],'question.updated','question',$id); return ['id'=>$id,'status'=>$q['status']];
        });
    }
    public function status(array $u,int $id,string $status,string $reason=''): array {
        if (!in_array($status,['publicada','arquivada','anulada'],true)) throw new ApiError(422,'invalid_status','Situação inválida.');
        return $this->db->transaction(function() use($u,$id,$status,$reason) {
            $q=$this->lockQuestion($id);
            if (($u['role']??'')==='admin') {
                if (!in_array($status,['arquivada','anulada'],true)) throw new ApiError(403,'forbidden','Administrador pode arquivar ou anular.');
            } else $this->owner($u,$q);
            if ($q['status']==='anulada') throw new ApiError(409,'annulled','A anulação é definitiva.');
            if ($status==='publicada') $this->validatePublish($q,$this->options($id));
            if ($status==='anulada' && (trim($reason)==='' || strlen($reason)>4000)) throw new ApiError(422,'reason_required','Informe o motivo da anulação (até 4000 caracteres).');
            $now=gmdate('Y-m-d H:i:s');
            $this->db->execute('UPDATE questions SET status=?,annulment_reason=?,annulled_at=?,annulled_by=?,updated_at=? WHERE id=?',[$status,$status==='anulada'?trim($reason):null,$status==='anulada'?$now:null,$status==='anulada'&&$u['role']!=='admin'?$u['id']:null,$now,$id]);
            $this->db->audit($u['role']==='admin'?null:(int)$u['id'],'question.'.$status,'question',$id,['reason'=>$reason,'admin_id'=>$u['role']==='admin'?$u['id']:null]);
            return ['id'=>$id,'status'=>$status];
        });
    }
    private function representation(array $q): array {
        $author=$this->db->one('SELECT name FROM users WHERE id=?',[$q['author_id']]);
        $text=$q['text_id']?$this->db->lock('associated_texts',(int)$q['text_id']):null;
        $options=$this->options((int)$q['id']);
        // Hash detecta conteúdo obsoleto; não constitui uma versão e não armazena cópias.
        $hash=hash('sha256',json_encode([$q['discipline_id'],$q['subject_id'],$q['text_id'],$q['statement'],$q['correct_option'],$options,$text?[$text['title'],$text['content'],$text['source'],$text['work_author']]:null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return $q+['author_name'=>$author['name']??'','alternatives'=>$options,'associated_text'=>$text,'content_hash'=>$hash];
    }
    public function get(array $u,int $id): array {
        return $this->db->transaction(function() use($u,$id) {
            $q=$this->lockQuestion($id);
            if (($u['role']==='aluno' && $q['status']!=='publicada') || ($q['status']==='rascunho' && (int)$q['author_id']!==(int)$u['id'])) throw new ApiError(404,'not_found','Questão indisponível para consulta.');
            $result=$this->representation($q);
            if ($u['role']==='aluno') unset($result['correct_option']);
            return $result;
        });
    }
    public function list(array $u,array $filters,int $page): array {
        $where=['q.status = ?']; $params=['publicada'];
        if (($filters['mine']??'')==='1') { $this->teacher($u); $where=['q.author_id = ?']; $params=[$u['id']]; }
        foreach(['discipline_id','subject_id','text_id'] as $f) if (isset($filters[$f])) { if (!ctype_digit((string)$filters[$f]) || (int)$filters[$f]<1) throw new ApiError(422,'invalid_filter','Filtro inválido.'); $where[]="q.$f = ?"; $params[]=(int)$filters[$f]; }
        if (isset($filters['search']) && !is_string($filters['search'])) throw new ApiError(422,'invalid_filter','Busca inválida.');
        if (!empty($filters['search'])) { $where[]='q.statement LIKE ?'; $params[]='%'.substr($filters['search'],0,180).'%'; }
        $clause=implode(' AND ',$where); $offset=($page-1)*20;
        $total=(int)$this->db->one("SELECT COUNT(*) AS n FROM questions q WHERE $clause",$params)['n'];
        $ids=$this->db->all("SELECT q.id FROM questions q WHERE $clause ORDER BY q.id DESC LIMIT 20 OFFSET $offset",$params);
        $data=[]; foreach($ids as $r) { try { $data[]=$this->get($u,(int)$r['id']); } catch(ApiError $e) { if ($e->status!==404) throw $e; } }
        return ['data'=>$data,'total'=>$total,'page'=>$page,'per_page'=>20];
    }
    public function resolve(array $u,array $data): array {
        if ($u['role']!=='aluno') throw new ApiError(403,'forbidden','Resoluções devem identificar um aluno autenticado.');
        $this->only($data,['question_id','selected_option','content_hash','attempt_id','activity_id']);
        $id=$this->optionalId($data['question_id']??null); if (!$id) throw new ApiError(422,'invalid_id','Informe a questão.');
        $attempt=$this->string($data,'attempt_id',128); $hash=$this->string($data,'content_hash',64); $option=$this->string($data,'selected_option',1);
        $activity=isset($data['activity_id'])?$this->string($data,'activity_id',128):null;
        if ($attempt==='' || !preg_match('/^[a-f0-9]{64}$/',$hash) || !preg_match('/^[A-E]$/',$option)) throw new ApiError(422,'invalid_resolution','Tentativa, hash ou alternativa inválidos.');
        return $this->db->transaction(function() use($u,$id,$attempt,$hash,$option,$activity) {
            // Lock do aluno serializa reenvios, inclusive para questões distintas.
            $this->db->lock('users',(int)$u['id']);
            $old=$this->db->one('SELECT * FROM resolutions WHERE school_id=? AND student_id=? AND attempt_id=?',[$u['school_id'],$u['id'],$attempt]);
            if ($old) {
                if ((int)$old['question_id']!==$id || $old['selected_option']!==$option || $old['content_hash']!==$hash || $old['activity_id']!==$activity) throw new ApiError(409,'attempt_conflict','Identificador de tentativa já usado com outros dados.');
                return $this->resolutionResult($old,true);
            }
            $q=$this->lockQuestion($id);
            if ($q['status']!=='publicada') throw new ApiError(409,'unavailable','Questão arquivada, anulada ou ainda não publicada.');
            $r=$this->representation($q);
            if (!hash_equals($r['content_hash'],$hash)) throw new ApiError(409,'content_changed','A questão foi alterada. Recarregue antes de responder.');
            if (!array_key_exists($option,$r['alternatives'])) throw new ApiError(422,'invalid_option','Alternativa não existe nesta questão.');
            $now=gmdate('Y-m-d H:i:s');
            $rid=$this->db->insert('resolutions',['school_id'=>$u['school_id'],'student_id'=>$u['id'],'question_id'=>$id,'attempt_id'=>$attempt,'activity_id'=>$activity,'selected_option'=>$option,'correct'=>$option===$q['correct_option']?1:0,'content_hash'=>$hash,'answered_at'=>$now]);
            $this->db->execute('UPDATE questions SET locked_at=COALESCE(locked_at,?) WHERE id=?',[$now,$id]);
            if ($q['text_id']) $this->db->execute('UPDATE associated_texts SET locked_at=COALESCE(locked_at,?) WHERE id=?',[$now,$q['text_id']]);
            $this->db->audit((int)$u['id'],'question.answered','question',$id,['resolution_id'=>$rid]);
            return $this->resolutionResult($this->db->one('SELECT * FROM resolutions WHERE id=?',[$rid]),false);
        });
    }
    private function resolutionResult(array $row,bool $replayed): array {
        $q=$this->db->one('SELECT status,annulment_reason,annulled_at FROM questions WHERE id=?',[$row['question_id']]);
        return $row+['question_status'=>$q['status'],'annulment_reason'=>$q['annulment_reason'],'annulled_at'=>$q['annulled_at'],'replayed'=>$replayed];
    }
    public function history(array $u,int $page): array {
        if ($u['role']!=='aluno') throw new ApiError(403,'forbidden','Este endpoint retorna apenas o histórico do próprio aluno.');
        $offset=($page-1)*20;
        $rows=$this->db->all("SELECT * FROM resolutions WHERE student_id=? AND school_id=? ORDER BY id DESC LIMIT 20 OFFSET $offset",[$u['id'],$u['school_id']]);
        return ['data'=>array_map(fn($r)=>$this->resolutionResult($r,false),$rows),'page'=>$page,'per_page'=>20];
    }
}
