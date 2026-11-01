import importlib.util,pathlib,unittest,stat
from unittest.mock import patch
from types import SimpleNamespace
spec=importlib.util.spec_from_file_location('pool',pathlib.Path(__file__).with_name('pool-binding.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Includes(unittest.TestCase):
 def test_system_ownership_protected(self):
  def info(p):return SimpleNamespace(st_uid=0,st_mode=(stat.S_IFREG|0o644) if str(p).endswith('.conf') else (stat.S_IFDIR|0o755))
  with patch.object(pathlib.Path,'stat',info):self.assertTrue(m.protected_system_file('/synthetic/module.conf'))
 def test_nonroot_system_config_rejected(self):
  with patch.object(pathlib.Path,'stat',lambda p:SimpleNamespace(st_uid=1001,st_mode=stat.S_IFREG|0o644)):self.assertFalse(m.protected_system_file('/synthetic/module.conf'))
 def test_writable_system_config_rejected(self):
  with patch.object(pathlib.Path,'stat',lambda p:SimpleNamespace(st_uid=0,st_mode=stat.S_IFREG|0o666)):self.assertFalse(m.protected_system_file('/synthetic/module.conf'))
 def test_normal_nginx(self):self.assertEqual(m.path_policy('/etc/nginx/fastcgi_params','/etc/nginx/fastcgi_params','/etc/nginx'),'WITHIN_COMPONENT_ROOT')
 def test_enabled_site(self):self.assertIsNotNone(m.path_policy('/etc/nginx/sites-enabled/site.conf','/etc/nginx/sites-available/site.conf','/etc/nginx'))
 def test_system_module_symlink(self):self.assertEqual(m.path_policy('/etc/nginx/modules-enabled/50-mod-http-geoip.conf','/usr/share/nginx/modules-available/mod-http-geoip.conf','/etc/nginx'),'ROOT_OWNED_MODULE_DECLARATION_ONLY')
 def test_arbitrary_destination_rejected(self):self.assertIsNone(m.path_policy('/etc/nginx/modules-enabled/example.conf','/home/private/example.conf','/etc/nginx'))
 def test_arbitrary_source_rejected(self):self.assertIsNone(m.path_policy('/etc/nginx/sites-enabled/example.conf','/usr/share/nginx/modules-available/mod.conf','/etc/nginx'))
 def test_traversal_rejected(self):self.assertIsNone(m.path_policy('/etc/nginx/../shadow','/etc/shadow','/etc/nginx'))
 def test_fpm_root_separate(self):self.assertIsNone(m.path_policy('/etc/php/8.5/fpm/pool.d/site.conf','/etc/nginx/site.conf','/etc/php/8.5/fpm'))
 def test_fpm_normal(self):self.assertEqual(m.path_policy(m.POOL,m.POOL,'/etc/php/8.5/fpm'),'WITHIN_COMPONENT_ROOT')
 def test_module_contents_positive(self):self.assertTrue(m.module_declarations_only('# system module\nload_module modules/ngx_http_geoip_module.so;'))
 def test_module_include_rejected(self):self.assertFalse(m.module_declarations_only('include /etc/shadow;'))
 def test_module_traversal_rejected(self):self.assertFalse(m.module_declarations_only('load_module modules/../../home/private.so;'))
 def test_module_http_block_rejected(self):self.assertFalse(m.module_declarations_only('http {server {listen 1234;}}'))
 def test_private_path_masked(self):self.assertNotIn('PRIVATE_TENANT',m.safe_path('/home/PRIVATE_TENANT/.env'))
 def test_module_path_visible(self):self.assertEqual(m.safe_path('/etc/nginx/modules-enabled/50-mod.conf'),'/etc/nginx/modules-enabled/50-mod.conf')
if __name__=='__main__':unittest.main()
