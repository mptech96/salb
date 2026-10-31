import importlib.util,os,pathlib,socket,sys,unittest
spec=importlib.util.spec_from_file_location('kernel',pathlib.Path(__file__).with_name('writer-kernel-links.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Pure(unittest.TestCase):
 def test_ipv4(self):self.assertEqual(m.address('0100007F:0D0B'),'4:7f000001:3339')
 def test_ipv6_mapped_ipv4(self):self.assertEqual(m.address('0000000000000000FFFF00000100007F:0D0B'),m.address('0100007F:0D0B'))
 def test_invalid_address_rejected(self):
  with self.assertRaises((m.Unproven,ValueError)):m.address('0011:0123')
@unittest.skipUnless(sys.platform=='linux','actual kernel ownership requires Linux')
class Kernel(unittest.TestCase):
 def test_actual_unix_peer_graph(self):
  a,b=socket.socketpair()
  try:
   own=m.fds(os.getpid());ia=next(i for i,fd in own.items()if fd==a.fileno());ib=next(i for i,fd in own.items()if fd==b.fileno());peers=m.unix_peers();self.assertEqual(peers[ia],ib);self.assertEqual(peers[ib],ia)
  finally:a.close();b.close()
 def test_actual_tcp_reverse_endpoints(self):
  server=socket.socket();server.bind(('127.0.0.1',0));server.listen();client=socket.create_connection(server.getsockname());accepted,_=server.accept()
  try:
   own=m.fds(os.getpid());ia=next(i for i,fd in own.items()if fd==accepted.fileno());ib=next(i for i,fd in own.items()if fd==client.fileno());table=m.tcp(os.getpid());self.assertEqual(table[ia]['local'],table[ib]['remote']);self.assertEqual(table[ia]['remote'],table[ib]['local'])
  finally:accepted.close();client.close();server.close()
if __name__=='__main__':unittest.main()
