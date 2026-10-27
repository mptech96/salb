import unittest
import native_isolation_checks as m
class SocketPlans(unittest.TestCase):
    def query(self,label,path):
        return {'allow':0xffffffff if path.startswith('/home/mysql/sulb-shipment-') or path in ['/run/mysqld/mysqld.sock','/run/mysqld/mysqld.sock.lock'] else 0,'deny':0}
    def test_exact_production_name_accepted_with_pid_file_permissions(self):
        r=m.exact_socket_plan('mysqld (complain)','/home/mysql',['/run/mysqld/mysqld.sock'],'a'*32,self.query)
        self.assertEqual(r['selected_socket_path'],'/run/mysqld/mysqld.sock')
    def test_missing_pid_file_access_blocks(self):
        def q(l,p):return {'allow':0 if p.endswith('.lock') else 0xffffffff,'deny':0}
        with self.assertRaises(m.CheckBlocked):m.exact_socket_plan('mysqld','/home/mysql',[], 'a'*32,q)
    def test_explicit_deny_blocks_even_complain(self):
        with self.assertRaises(m.CheckBlocked):m.exact_socket_plan('mysqld (complain)','/home/mysql',[], 'a'*32,lambda *a:{'allow':0xffffffff,'deny':2})
    def test_unconfined_rejected(self):
        with self.assertRaises(m.CheckBlocked):m.exact_socket_plan('unconfined','/home/mysql',[],'a'*32,self.query)
    def test_no_allow_remains_blocked(self):
        with self.assertRaises(m.CheckBlocked):m.exact_socket_plan('mysqld','/home/mysql',[],'a'*32,lambda *a:{'allow':0,'deny':0})
    def test_no_advisory_lock_permission_required(self):
        def q(l,p):return {'allow':0xffffffff & ~m.PERMS['lock'] if p.endswith('.lock') else 0xffffffff,'deny':m.PERMS['lock'] if p.endswith('.lock') else 0}
        r=m.exact_socket_plan('mysqld (complain)','/home/mysql',[],'a'*32,q)
        row=r['plans'][-1]['queries'][-1]
        self.assertNotIn('lock',row['required_operations']);self.assertTrue(row['strictly_allowed'])
    def test_pid_file_delete_permission_required(self):
        def q(l,p):return {'allow':0xffffffff & ~m.PERMS['delete'] if p.endswith('.lock') else 0xffffffff,'deny':0}
        with self.assertRaises(m.CheckBlocked):m.exact_socket_plan('mysqld','/home/mysql',[],'a'*32,q)
if __name__=='__main__':unittest.main()
