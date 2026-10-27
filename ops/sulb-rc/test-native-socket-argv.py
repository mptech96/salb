"""Exercise the actual Python command -> PHP argv boundary, without a DB."""
import importlib.util, json, os, pathlib, shutil, subprocess, tempfile, unittest
spec=importlib.util.spec_from_file_location('runner',pathlib.Path(__file__).with_name('rehearse-native-schema.py'))
n=importlib.util.module_from_spec(spec);spec.loader.exec_module(n)
class ArgvBoundary(unittest.TestCase):
    def test_python_command_reaches_php_native_context_slot(self):
        source=pathlib.Path('/owned/source');work=pathlib.Path('/owned/work');ctx=work/'native-context.json'
        sock='/run/mysqld/mysqld.sock';data='/home/mysql/sulb-shipment-'+'a'*32
        command=n.rehearsal_command(source,work,sock,data,ctx)
        script=str(source/'ops/sulb-rc/run-private-socket-rehearsal.php')
        actual_argv=command[command.index(script):]
        php=os.environ.get('SULB_CONTRACT_PHP') or shutil.which('php')
        self.assertIsNotNone(php,'PHP CLI required for cross-language regression')
        test=pathlib.Path(__file__).with_suffix('.php')
        result=subprocess.run([php,str(test),'--python-argv'],input=json.dumps(actual_argv),text=True,capture_output=True,timeout=15,check=True)
        self.assertEqual(json.loads(result.stdout),{'native_context_received':True,'argv_count':7,'checks':2})
        self.assertEqual(actual_argv[1:3],[sock,data]);self.assertEqual(actual_argv[6],str(ctx))
    def test_guard_failure_summary_exports_only_allowlisted_codes(self):
        fixtures=pathlib.Path(__file__).resolve().parents[2]/'backend/storage/framework/testing/socket-argv-contracts'
        fixtures.mkdir(parents=True,exist_ok=True)
        with tempfile.TemporaryDirectory(dir=fixtures) as directory:
            p=pathlib.Path(directory)/'private.log'
            p.write_text('DDL SECRET STOP_CODE=OWNED_SOCKET_REQUIRED STOP_CODE=PRIVATE_SECRET STOP_CODE=PDO_FAILURE_42000_1064')
            r=n.safe_log_codes(p)
            self.assertEqual(r['stop_codes'],['OWNED_SOCKET_REQUIRED','PDO_FAILURE_42000_1064'])
            self.assertNotIn('SECRET',json.dumps(r));self.assertNotIn('DDL',json.dumps(r))
    def test_previous_off_by_one_is_detected(self):
        fixtures=pathlib.Path(__file__).resolve().parents[2]/'backend/storage/framework/testing/socket-argv-contracts'
        fixtures.mkdir(parents=True,exist_ok=True)
        with tempfile.TemporaryDirectory(dir=fixtures) as directory:
            target=pathlib.Path(directory)
            php_test=pathlib.Path(__file__).with_suffix('.php')
            context=php_test.with_name('native-rehearsal-context.php').read_text(encoding='utf-8-sig')
            old=context.replace('$args[6]??null','$args[7]??null')
            self.assertNotEqual(context,old)
            (target/'native-rehearsal-context.php').write_text(old)
            (target/php_test.name).write_text(php_test.read_text(encoding='utf-8-sig'))
            args=['runner.php','/run/mysqld/mysqld.sock','/home/mysql/sulb-shipment-'+'a'*32,'capture.json','b'*64,'proof.json','native-context.json']
            php=os.environ.get('SULB_CONTRACT_PHP') or shutil.which('php')
            r=subprocess.run([php,str(target/php_test.name),'--python-argv'],input=json.dumps(args),text=True,capture_output=True,timeout=15)
            self.assertNotEqual(r.returncode,0)
            self.assertIn('SOCKET_ARGV_REGRESSION_FAILED',r.stdout+r.stderr)
if __name__=='__main__':unittest.main()
