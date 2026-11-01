import importlib.util,pathlib,unittest
spec=importlib.util.spec_from_file_location('pool',pathlib.Path(__file__).with_name('pool-binding.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Binding(unittest.TestCase):
 def server(self,endpoint,root=None):return 'http {server {server_name api.sulb.mgye.tech; root "'+(root or m.BACKEND+'/public')+'"; location ~ \\.php$ {fastcgi_pass '+endpoint+';}}}'
 def test_matching_unix(self):self.assertEqual(m.binding(m.parse(self.server('unix:/run/php/site.sock')),'unix:/run/php/site.sock'),1)
 def test_matching_tcp(self):self.assertEqual(m.binding(m.parse(self.server('127.0.0.1:18501')),'127.0.0.1:18501'),1)
 def test_wrong_endpoint(self):
  with self.assertRaises(m.Blocked):m.binding(m.parse(self.server('unix:/run/php/other.sock')),'unix:/run/php/site.sock')
 def test_wrong_root(self):
  with self.assertRaises(m.Blocked):m.binding(m.parse(self.server('unix:/run/php/site.sock','/another/public')),'unix:/run/php/site.sock')
 def test_wrong_domain(self):
  with self.assertRaises(m.Blocked):m.binding(m.parse(self.server('127.0.0.1:18501').replace(m.DOMAIN,'another.example')),'127.0.0.1:18501')
 def test_unclosed_config(self):
  with self.assertRaises(m.Blocked):m.parse('http { server { root /tmp;')
 def pool(self,mode='ondemand'):return '[site]\nuser = mgye-api-sulb\ngroup = mgye-api-sulb\npm = '+mode+'\nlisten = /run/php/site.sock\n'
 def test_ondemand_config(self):self.assertEqual(m.pool_values(self.pool())['mode'],'ondemand')
 def test_wrong_pool_user(self):
  with self.assertRaises(m.Blocked):m.pool_values(self.pool().replace('user = mgye-api-sulb','user = another'))
 def test_dynamic_endpoint_rejected(self):
  with self.assertRaises(m.Blocked):m.pool_values(self.pool().replace('/run/php/site.sock','$pool.sock'))
if __name__=='__main__':unittest.main()
