"""Testes HTTP reais com banco SQLite temporário; não usa dados de produção."""
import base64, http.cookiejar, json, os, re, socket, subprocess, sys, tempfile, time
from pathlib import Path
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
root=Path(__file__).resolve().parents[1]
php=os.environ.get('TEST_PHP_BIN','php')
count=0

def check(ok,label):
    global count
    if not ok: raise AssertionError(label)
    count+=1; print('OK',label)

with tempfile.TemporaryDirectory() as directory:
    env=dict(os.environ,APP_KEY=base64.b64encode(b't'*32).decode(),DB_DSN='sqlite:'+directory+'/http.sqlite',SESSION_SECURE='0')
    fixture=json.loads(subprocess.check_output([php,str(root/'tests/http_fixture.php')],env=env))
    sock=socket.socket(); sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]; sock.close()
    log=open(directory+'/server.log','w+')
    process=subprocess.Popen([php,'-d','session.save_path='+directory,'-S',f'127.0.0.1:{port}','-t',str(root/'public'),str(root/'public/router.php')],env=env,stdout=log,stderr=log)
    opener=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    base=f'http://127.0.0.1:{port}'
    def call(path,method='GET',data=None,role=None,form=False):
        headers={}
        if role: headers['Authorization']='Bearer '+fixture['tokens'][role]
        payload=None
        if data is not None:
            payload=(urlencode(data) if form else json.dumps(data)).encode()
            headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json'
        request=Request(base+path,data=payload,headers=headers,method=method)
        try:
            response=opener.open(request,timeout=5)
        except HTTPError as error: response=error
        body=response.read().decode()
        return response.status,json.loads(body) if 'application/json' in response.headers.get('Content-Type','') else body
    try:
        for _ in range(50):
            try: call('/admin/entrar'); break
            except URLError: time.sleep(.05)
        check(call('/api/v1/questoes')[0]==401,'API exige integração autenticada')
        check(call('/.env')[0]==404,'configuração privada não é servida')
        check(call('/app/Repository.php')[0]==404,'código privado não é servido')
        check(call('/api/v1/disciplinas',role='teacher')[0]==200,'consulta de disciplinas via HTTP')
        status,text=call('/api/v1/textos','POST',{'title':'Texto de teste','content':'Conteúdo'},'teacher'); check(status==201,'cadastro de texto via HTTP')
        status,q=call('/api/v1/questoes','POST',{'discipline_id':fixture['discipline'],'subject_id':fixture['subject'],'text_id':text['id'],'statement':'<script>alert(1)</script> Quanto é 1 + 1?','alternatives':['2','3'],'correct_option':'A'},'teacher'); check(status==201,'cadastro de questão via HTTP')
        path='/api/v1/questoes/'+str(q['id'])
        check(call(path,'GET',role='student')[0]==404,'rascunho fica privado')
        check(call(path+'/situacao','POST',{'status':'publicada'},'teacher')[0]==200,'publicação via HTTP')
        status,view=call(path,role='student'); check(status==200 and 'correct_option' not in view,'gabarito ausente na consulta do aluno')
        check(call(path,'PATCH',{'statement':'Outra'},'other')[0]==403,'edição de outro autor é negada')
        answer={'question_id':q['id'],'selected_option':'A','attempt_id':'http-attempt','content_hash':view['content_hash']}
        check(call('/api/v1/textos/'+str(text['id']),'PATCH',{'content':'Conteúdo atualizado'},'teacher')[0]==200,'texto editável antes da resolução')
        status,error=call('/api/v1/resolucoes','POST',answer,'student'); check(status==409 and error['error']['code']=='content_changed','resposta a conteúdo antigo é recusada via HTTP')
        answer['content_hash']=call(path,role='student')[1]['content_hash']
        status,result=call('/api/v1/resolucoes','POST',answer,'student'); check(status==201 and result['correct']==1,'resposta válida é corrigida')
        check(call('/api/v1/resolucoes','POST',answer,'student')[0]==200,'reenvio não duplica tentativa')
        status,error=call(path,'PATCH',{'statement':'Mudança'},'teacher'); check(status==409 and error['error']['code']=='content_locked','edição bloqueada após resposta via HTTP')
        check(call('/admin/entrar','POST',{'email':'admin@example.test','password':'test-password-123'},form=True)[0]==403,'login exige proteção CSRF')
        login=call('/admin/entrar')[1]; csrf=re.search('name="csrf" value="([a-f0-9]+)"',login).group(1)
        status,dashboard=call('/admin/entrar','POST',{'csrf':csrf,'email':'admin@example.test','password':'test-password-123'},form=True)
        check(status==200 and 'Visão geral' in dashboard,'login administrativo com sessão')
        questions=call('/admin/questoes')[1]; check('&lt;script&gt;alert(1)&lt;/script&gt;' in questions and '<script>' not in questions,'painel escapa conteúdo cadastrado')
        csrf=re.search('name="csrf" value="([a-f0-9]+)"',questions).group(1)
        status,schools=call('/admin/escolas','POST',{'csrf':csrf,'name':'Escola integrada'},form=True); check(status==200 and 'secret:' in schools,'painel gera credencial de escola')
        check('secret:' not in call('/admin/escolas')[1],'segredo exibido uma única vez')
        check(call(path+'/situacao','POST',{'status':'anulada','reason':'Erro no enunciado'},'teacher')[0]==200,'anulação via HTTP')
        status,history=call('/api/v1/resolucoes',role='student'); check(status==200 and history['data'][0]['question_status']=='anulada','histórico informa anulação')
        print(f'{count} verificações HTTP passaram.')
    except Exception:
        log.flush(); log.seek(0); print(log.read(),file=sys.stderr); raise
    finally:
        process.terminate(); process.wait(timeout=5); log.close()
