"""Read-only Linux kernel socket ownership. AF_NETLINK diagnostics only; no application connections."""
import json,os,pathlib,re,socket,struct,sys,time
class Unproven(Exception):pass
FD_BUDGET=32768
DEADLINE=None
def read(path,limit=4194304):
 if DEADLINE is not None and time.monotonic()>DEADLINE:raise Unproven('PASSIVE_SAMPLE_TIME_LIMIT')
 with open(path,'rb')as f:data=f.read(limit+1)
 if len(data)>limit:raise Unproven('PROC_READ_LIMIT')
 return data
def proc(pid):
 try:
  base=pathlib.Path('/proc')/str(pid);text=read(base/'stat',8192).decode();rest=text[text.rfind(')')+2:].split();status=read(base/'status',32768).decode();uid=int(re.search(r'^Uid:\s+\d+\s+(\d+)',status,re.M)[1]);return {'pid':pid,'uid':uid,'start_ticks':rest[19],'exe':os.readlink(base/'exe'),'cwd':os.readlink(base/'cwd'),'argv':read(base/'cmdline',32768).replace(b'\0',b' ').decode(errors='replace'),'net':os.readlink(base/'ns/net')}
 except (FileNotFoundError,ProcessLookupError):return None
def fds(pid):
 global FD_BUDGET
 out={};paths=list((pathlib.Path('/proc')/str(pid)/'fd').iterdir())
 FD_BUDGET-=len(paths)
 if len(paths)>8192 or FD_BUDGET<0:raise Unproven('FD_SCAN_LIMIT')
 for path in paths:
  try:target=os.readlink(path)
  except FileNotFoundError:continue
  m=re.fullmatch(r'socket:\[(\d+)\]',target)
  if m:out[int(m[1])]=int(path.name)
 return out
def address(value):
 h,port=value.split(':');raw=bytes.fromhex(h)
 if len(raw)==4:raw=raw[::-1];kind='4'
 elif len(raw)==16:
  raw=b''.join(raw[i:i+4][::-1]for i in range(0,16,4));kind='6'
  if raw[:12]==b'\0'*10+b'\xff\xff':raw=raw[12:];kind='4'
 else:raise Unproven('TCP_ADDRESS_FORM')
 return kind+':'+raw.hex()+':'+str(int(port,16))
def tcp(pid):
 out={}
 for name in ['tcp','tcp6']:
  for line in read('/proc/'+str(pid)+'/net/'+name).decode().splitlines()[1:]:
   cols=line.split()
   if len(cols)<10 or cols[3]!='01':continue
   out[int(cols[9])]={'local':address(cols[1]),'remote':address(cols[2]),'local_port':int(cols[1].split(':')[-1],16),'remote_port':int(cols[2].split(':')[-1],16)}
 if len(out)>16384:raise Unproven('TCP_SCAN_LIMIT')
 return out
def unix_peers():
 seq=7319;body=struct.pack('=BBHIIIII',socket.AF_UNIX,0,0,0xffffffff,0,4,0xffffffff,0xffffffff);request=struct.pack('=IHHII',16+len(body),20,0x301,seq,0)+body;out={};total=0
 with socket.socket(socket.AF_NETLINK,socket.SOCK_RAW,4)as s:
  s.settimeout(2);s.bind((0,0));s.sendto(request,(0,0))
  while True:
   data,anc,flags,origin=s.recvmsg(1048576);total+=len(data)
   if origin[0]!=0 or flags&socket.MSG_TRUNC or total>4194304:raise Unproven('UNIX_DIAG_BOUNDED_REPLY_REQUIRED')
   offset=0
   while offset+16<=len(data):
    length,kind,flag,number,pid=struct.unpack_from('=IHHII',data,offset)
    if number!=seq or length<16 or offset+length>len(data):raise Unproven('UNIX_DIAG_REPLY_INVALID')
    payload=data[offset+16:offset+length]
    if kind==3:return out
    if kind==2:raise Unproven('UNIX_DIAG_DENIED')
    if len(payload)>=16:
     family,style,state,pad,inode,c1,c2=struct.unpack_from('=BBBBIII',payload);at=16
     while at+4<=len(payload):
      size,attr=struct.unpack_from('=HH',payload,at)
      if size<4 or at+size>len(payload):raise Unproven('UNIX_DIAG_ATTRIBUTE_INVALID')
      if attr==2 and size==8:out[inode]=struct.unpack_from('=I',payload,at+4)[0]
      at+=(size+3)&~3
    offset+=(length+3)&~3

def inspect(request):
 global DEADLINE,FD_BUDGET
 DEADLINE=time.monotonic()+8;FD_BUDGET=32768
 server=proc(int(request['server_pid']))
 if not server or pathlib.Path(server['exe']).name not in ['mysqld','mysqld-debug']:raise Unproven('SOURCE_MYSQL_PROCESS_REQUIRED')
 if server['net']!=os.readlink('/proc/self/ns/net'):raise Unproven('SOURCE_HOST_NETWORK_NAMESPACE_REQUIRED')
 server_fds=fds(server['pid']);server_tcp=tcp(server['pid']);accepted={i:t for i,t in server_tcp.items()if i in server_fds and t['local_port']==int(request['server_port'])};peers={};unknown=[]
 try:peers=unix_peers()
 except (OSError,Unproven):unknown.append('UNIX_DIAGNOSTICS_UNAVAILABLE')
 actors=[];paths=list(pathlib.Path('/proc').glob('[0-9]*'))
 if len(paths)>8192:raise Unproven('PROCESS_SCAN_LIMIT')
 for path in paths:
  try:p=proc(int(path.name))
  except PermissionError:unknown.append('PROCESS_VISIBILITY_INCOMPLETE');continue
  if not p or not re.fullmatch(r'php(?:[0-9.]*)?|php-fpm[0-9.]*',pathlib.Path(p['exe'].replace(' (deleted)','')).name):continue
  related=p['uid']==int(request['site_uid'])or request['backend']+'/artisan'in p['argv']or(p['cwd']==request['backend']and re.search(r'(?:^|\s)(?:\./)?artisan(?:\s|$)',p['argv']))
  if not related:continue
  if len(actors)>=256:raise Unproven('SITE_PROCESS_SCAN_LIMIT')
  try:owned=fds(p['pid'])
  except (PermissionError,FileNotFoundError):unknown.append('SITE_FD_VISIBILITY_UNPROVEN');continue
  role='SITE_FPM'if'fpm'in pathlib.Path(p['exe']).name else('QUEUE_WORKER'if re.search(r'\bqueue:|\bhorizon\b',p['argv'])else'SITE_PHP_CLI');actors.append((p,owned,role))
 matches=[];unmapped=[]
 for session in request['sessions']:
  candidates=[];port=re.search(r':([0-9]+)$',session.get('host',''));transport='TCP'if port else'UNIX';server_inode=None
  if port:
   candidates=[i for i,t in accepted.items()if t['remote_port']==int(port[1])]
   if len(candidates)==1:server_inode=candidates[0]
  elif session.get('server_fd')is not None:
   candidates=[i for i,fd in server_fds.items()if fd==int(session['server_fd'])]
   if len(candidates)==1:server_inode=candidates[0]
  if server_inode is None:unmapped.append({'connection_id':session['id'],'reason':'NO_UNIQUE_SERVER_SOCKET'});continue
  found=[]
  for p,owned,role in actors:
   if p['net']!=server['net']:continue
   if transport=='TCP':
    for i in owned:
     t=server_tcp.get(i);srv=accepted[server_inode]
     if t and t['local']==srv['remote']and t['remote']==srv['local']:found.append((p,i,owned[i],role))
   else:
    client_inode=peers.get(server_inode)
    if client_inode in owned and peers.get(client_inode)==server_inode:found.append((p,client_inode,owned[client_inode],role))
  if len(found)!=1:unmapped.append({'connection_id':session['id'],'reason':'NO_UNIQUE_SITE_PROCESS_PEER'});continue
  p,inode,fd,role=found[0];now=proc(p['pid'])
  if not now or now['start_ticks']!=p['start_ticks']or now['uid']!=p['uid']or os.readlink('/proc/'+str(p['pid'])+'/fd/'+str(fd))!='socket:['+str(inode)+']'or os.readlink('/proc/'+str(server['pid'])+'/fd/'+str(server_fds[server_inode]))!='socket:['+str(server_inode)+']':unmapped.append({'connection_id':session['id'],'reason':'PROCESS_OR_SOCKET_CHANGED'});continue
  matches.append({'connection_id':session['id'],'pid':p['pid'],'uid':p['uid'],'start_ticks':p['start_ticks'],'role':role,'transport':transport,'client_inode':inode,'client_fd':fd,'server_inode':server_inode,'server_fd':server_fds[server_inode],'kernel_peer_binding_proven':True})
 final_server=proc(server['pid'])
 if not final_server or final_server['start_ticks']!=server['start_ticks']:raise Unproven('MYSQL_PROCESS_CHANGED')
 return {'matches':matches,'unmapped':unmapped,'limitations':sorted(set(unknown)),'site_processes_observed':len(actors),'new_application_connections_created':False}
if __name__=='__main__':
 try:
  if sys.platform!='linux'or os.geteuid()!=0:raise Unproven('LINUX_ROOT_INSPECTION_REQUIRED')
  payload=sys.stdin.buffer.read(1048577)
  if len(payload)>1048576:raise Unproven('INPUT_LIMIT')
  print(json.dumps(inspect(json.loads(payload))))
 except Exception as e:print(json.dumps({'blocked':str(e)if isinstance(e,Unproven)else'PRIVATE_KERNEL_INSPECTION_FAILURE'}));raise SystemExit(78)
