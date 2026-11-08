"""New FastCGI transport contracts against an owned local mock, never the VPS/FPM/application."""
import json,os,pathlib,socket,struct,subprocess,tempfile,threading,unittest,shutil
PHP=os.environ.get('SULB_TEST_PHP')or shutil.which('php')
@unittest.skipUnless(hasattr(socket,'AF_UNIX')and os.name=='posix'and PHP,'Linux PHP/socket contract')
class SiteFcgi(unittest.TestCase):
 def exchange(self,app_status=0,protocol=0,malformed=False):
  with tempfile.TemporaryDirectory(prefix='sulb-fcgi-test-')as d:
   sock=str(pathlib.Path(d)/'mock.sock');server=socket.socket(socket.AF_UNIX);server.bind(sock);server.listen(1);server.settimeout(10);captured={};errors=[]
   def exact(c,n):
    out=b''
    while len(out)<n:
     b=c.recv(n-len(out))
     if not b:raise RuntimeError('TRUNCATED_TEST_REQUEST')
     out+=b
    return out
   def length(b,i):
    n=b[i]
    return (struct.unpack('!I',b[i:i+4])[0]&0x7fffffff,i+4)if n&128 else(n,i+1)
   def run():
    try:
     c,_=server.accept();c.settimeout(10)
     with c:
      params=b''
      while True:
       v,t,r,n,p,_=struct.unpack('!BBHHBB',exact(c,8));body=exact(c,n);exact(c,p)
       if t==4:params+=body
       if t==5:break
      at=0
      while at<len(params):
       a,at=length(params,at);b,at=length(params,at);k=params[at:at+a].decode();at+=a;value=params[at:at+b].decode();at+=b;captured[k]=value
      body=b'Content-Type: application/json\r\n\r\n'+(b'NOT_JSON'if malformed else json.dumps({'contract':'PASS'}).encode())
      for packet in [struct.pack('!BBHHBB',1,6,1,len(body),0,0)+body,struct.pack('!BBHHBB',1,3,1,8,0,0)+struct.pack('!IBxxx',app_status,protocol)]:
       for i in range(0,len(packet),3):c.sendall(packet[i:i+3])
    except Exception as e:errors.append(type(e).__name__)
    finally:server.close()
   thread=threading.Thread(target=run);thread.start()
   helper=str(pathlib.Path(__file__).with_name('release-site-runtime.php').resolve())
   r=subprocess.run([PHP,'-d','display_errors=0','-r',"require $argv[1];echo json_encode(releaseSiteFcgi($argv[2],$argv[3]));",helper,sock,str(pathlib.Path(d)/'SYNTHETIC_ONLY.php')],capture_output=True,timeout=15)
   thread.join(10);self.assertFalse(thread.is_alive());self.assertEqual(errors,[]);return r,captured
 def test_generated_probe_is_valid_php_without_execution(self):
  lines=pathlib.Path(__file__).with_name('release-site-runtime.php').read_text(encoding='utf-8').splitlines();start=next(i for i,v in enumerate(lines)if "<<<'PHP_PROBE'"in v)+1;end=lines.index('PHP_PROBE;');program=chr(10).join(lines[start:end]).replace('NONCE_VALUE',"'SYNTHETIC_ONLY'")
  with tempfile.TemporaryDirectory()as d:
   file=pathlib.Path(d)/'probe.php';file.write_text(program,encoding='utf-8');r=subprocess.run([PHP,'-l',str(file)],capture_output=True);self.assertEqual(r.returncode,0,r.stderr.decode())
 def test_fragmented_response_and_private_probe_parameters(self):
  r,p=self.exchange();self.assertEqual(r.returncode,0,r.stderr.decode());self.assertEqual(json.loads(r.stdout),{'contract':'PASS'});self.assertEqual(p['PHP_ADMIN_VALUE'],'auto_prepend_file=\nauto_append_file=\n');self.assertEqual(p['REQUEST_METHOD'],'GET');self.assertEqual(p['CONTENT_LENGTH'],'0');self.assertNotIn('DB_PASSWORD',p)
 def test_application_failure_is_rejected(self):
  r,_=self.exchange(app_status=1);self.assertNotEqual(r.returncode,0)
 def test_protocol_failure_is_rejected(self):
  r,_=self.exchange(protocol=2);self.assertNotEqual(r.returncode,0)
 def test_malformed_response_is_rejected(self):
  r,_=self.exchange(malformed=True);self.assertNotEqual(r.returncode,0)
if __name__=='__main__':unittest.main()
