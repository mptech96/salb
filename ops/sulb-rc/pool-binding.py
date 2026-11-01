"""Bounded read-only configured Nginx/FPM binding and existing listener ownership. No requests."""
import pathlib,re,json,sys,os,glob,hashlib,configparser,socket
class Blocked(Exception):pass
BACKEND='/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech'
DOMAIN='api.sulb.mgye.tech'
POOL='/etc/php/8.5/fpm/pool.d/'+DOMAIN+'.conf'
MAIN='/etc/php/8.5/fpm/php-fpm.conf'
def parse(text):
 tokens=re.findall(r'"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|#[^\n]*|[{};]|[^\s{};]+',text)
 tokens=[t for t in tokens if not t.startswith('#')];at=0
 def block(nested=False):
  nonlocal at
  result=[];head=[]
  while at<len(tokens):
   t=tokens[at];at+=1
   if t==';':
    if not head:raise Blocked('NGINX_EMPTY_DIRECTIVE')
    result.append((head,[]));head=[]
   elif t=='{':
    if not head:raise Blocked('NGINX_BLOCK_REQUIRED')
    result.append((head,block(True)));head=[]
   elif t=='}':
    if head or not nested:raise Blocked('NGINX_PARSE_UNPROVEN')
    return result
   else:head.append(t[1:-1] if len(t)>1 and t[0] in ['"',"'"] else t)
  if nested or head:raise Blocked('NGINX_PARSE_UNPROVEN')
  return result
 return block()
def walk(nodes):
 for h,c in nodes:
  yield h,c
  yield from walk(c)
def binding(nodes,endpoint):
 servers=[c for h,c in walk(nodes) if h==['server'] and any(k and k[0]=='server_name' and DOMAIN in k[1:] for k,v in c)]
 matches=[]
 for c in servers:
  roots=[h[1:] for h,v in walk(c) if h and h[0]=='root']
  passes=[h[1:] for h,v in walk(c) if h and h[0]=='fastcgi_pass']
  if not passes:continue
  if not roots or any(r!=[BACKEND+'/public'] for r in roots):raise Blocked('NGINX_BACKEND_ROOT_UNPROVEN')
  if any(v!=[endpoint] for v in passes):raise Blocked('NGINX_POOL_ENDPOINT_MISMATCH')
  matches.append(c)
 if not matches:raise Blocked('NGINX_BACKEND_FASTCGI_BINDING_NOT_FOUND')
 return len(matches)
def pool_values(text):
 c=configparser.ConfigParser(interpolation=None,strict=True,delimiters=('=',),inline_comment_prefixes=(';',),empty_lines_in_values=False)
 c.read_string(text)
 if len(c.sections())!=1:raise Blocked('POOL_SINGLE_SECTION_REQUIRED')
 v=dict(c[c.sections()[0]])
 if v.get('user')!='mgye-api-sulb' or v.get('group')!='mgye-api-sulb':raise Blocked('POOL_USER_GROUP_MISMATCH')
 if v.get('pm') not in ['ondemand','dynamic','static']:raise Blocked('POOL_MODE_UNPROVEN')
 endpoint=v.get('listen','').strip()
 if not endpoint or any(x in endpoint for x in ['$',' ','\n']):raise Blocked('POOL_STATIC_LISTEN_REQUIRED')
 if v.get('chroot'):raise Blocked('POOL_CHROOT_REVIEW_REQUIRED')
 return {'mode':v['pm'],'listen':endpoint}
def main(master):
 import pwd,grp
 if sys.platform!='linux' or os.geteuid()!=0:raise Blocked('LINUX_ROOT_READ_ONLY_REQUIRED')
 hashes={};total=0
 def read(file,base=None):
  nonlocal total
  p=pathlib.Path(file).resolve(strict=True)
  if base and not p.is_relative_to(pathlib.Path(base)):raise Blocked('CONFIG_INCLUDE_OUTSIDE_ROOT')
  if not p.is_file() or p.stat().st_size>1048576 or len(hashes)>=256:raise Blocked('CONFIG_REVIEW_LIMIT')
  b=p.read_bytes();total+=len(b)
  if total>4194304:raise Blocked('CONFIG_TOTAL_LIMIT')
  hashes[str(p)]=hashlib.sha256(b).hexdigest();return b.decode('utf-8')
 def expand(file,chain=()):
  p=str(pathlib.Path(file).resolve(strict=True))
  if p in chain or len(chain)>12:raise Blocked('NGINX_INCLUDE_CYCLE_OR_DEPTH')
  def nodes(items):
   out=[]
   for h,c in items:
    if h[0]=='include':
     if len(h)!=2 or '$' in h[1]:raise Blocked('NGINX_DYNAMIC_INCLUDE_UNPROVEN')
     pattern=h[1] if h[1].startswith('/') else '/etc/nginx/'+h[1]
     for f in sorted(glob.glob(pattern)):out.extend(expand(f,chain+(p,)))
    else:out.append((h,nodes(c)))
   return out
  return nodes(parse(read(p,'/etc/nginx')))
 pool=pool_values(read(POOL,'/etc/php/8.5/fpm'))
 conf=read(MAIN,'/etc/php/8.5/fpm');includes=re.findall(r'^\s*include\s*=\s*([^;\n]+)',conf,re.M)
 if not any(str(pathlib.Path(POOL).resolve()) in [str(pathlib.Path(f).resolve()) for f in glob.glob(x.strip())] for x in includes):raise Blocked('POOL_NOT_INCLUDED_BY_MAIN_CONFIG')
 proc=pathlib.Path('/proc')/str(master)
 exe=os.readlink(proc/'exe');argv=(proc/'cmdline').read_bytes().replace(b'\0',b' ').decode(errors='replace')
 if pathlib.Path(exe).name!='php-fpm8.5' or MAIN not in argv:raise Blocked('FPM_MASTER_CONFIG_UNPROVEN')
 before=(proc/'stat').read_text();fds=set()
 for f in (proc/'fd').iterdir():
  try:t=os.readlink(f)
  except FileNotFoundError:continue
  m=re.fullmatch(r'socket:\[(\d+)\]',t)
  if m:fds.add(int(m[1]))
 listen=pool['listen'];endpoint='unix:'+listen if listen.startswith('/') else listen
 count=binding(expand('/etc/nginx/nginx.conf'),endpoint)
 owned=False
 if listen.startswith('/'):
  for line in (proc/'net/unix').read_text().splitlines()[1:]:
   cols=line.split()
   if len(cols)>=8 and cols[7]==listen and int(cols[6]) in fds and int(cols[3],16)&0x10000:owned=True
 else:
  m=re.fullmatch(r'(127\.0\.0\.1):([0-9]+)',listen)
  if not m:raise Blocked('ONLY_EXPLICIT_LOOPBACK_TCP_SUPPORTED')
  ip=socket.inet_aton(m[1])[::-1].hex().upper();port=int(m[2])
  for line in (proc/'net/tcp').read_text().splitlines()[1:]:
   c=line.split()
   if c[1]==ip+':'+format(port,'04X') and c[3]=='0A' and int(c[9]) in fds:owned=True
 if not owned:raise Blocked('POOL_LISTENER_NOT_OWNED_BY_FPM_MASTER')
 if before.split(') ',1)[1].split()[19]!=(proc/'stat').read_text().split(') ',1)[1].split()[19]:raise Blocked('FPM_MASTER_CHANGED')
 uid=pwd.getpwnam('mgye-api-sulb').pw_uid;gid=grp.getgrnam('mgye-api-sulb').gr_gid
 queues=[]
 for pattern in ['/etc/systemd/system/*.service','/etc/systemd/system/*.service.d/*.conf','/etc/supervisor/conf.d/*','/etc/supervisord.d/*','/etc/crontab','/etc/cron.d/*','/var/spool/cron/crontabs/*']:
  for f in sorted(glob.glob(pattern)):
   if not pathlib.Path(f).is_file():continue
   text=read(f)
   if BACKEND in text and re.search(r'queue:|horizon|schedule:',text):queues.append({'path':f,'sha256':hashes[str(pathlib.Path(f).resolve())],'explicit_user_directive_present':bool(re.search(r'(?m)^\s*(?:User|user)\s*=',text)),'explicit_working_directory_present':bool(re.search(r'(?m)^\s*(?:WorkingDirectory|directory)\s*=',text)),'child_or_environment_references_present':bool(re.search(r'EnvironmentFile|include|source |\$|`',text)),'owner_and_child_commands_review_required':True})
 return {'review_source_rows':[{'path':p,'sha256':sha} for p,sha in hashes.items()],'configured_pool_binding':'PASS','mode':pool['mode'],'master_pid':master,'site_uid':uid,'site_gid':gid,'listener_owned_by_master':True,'matching_server_count':count,'config_hashes':hashes,'queue_schedule_candidates':queues,'queue_absence_proven':False,'nginx_loaded_configuration_proven':False,'requires_existing_technical_signoff':True,'database_connections':0,'production_changes':False}
if __name__=='__main__':
 try:print(json.dumps(main(int(sys.argv[1]))))
 except Exception as e:print(json.dumps({'blocked':str(e) if isinstance(e,Blocked) else 'PRIVATE_POOL_BINDING_FAILURE'}));raise SystemExit(78)
