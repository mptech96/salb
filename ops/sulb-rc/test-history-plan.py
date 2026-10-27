import contextlib, importlib.util, io, json, pathlib, unittest
from unittest import mock
from history_pressure import SourcePressureGuard,pressure_between
from native_isolation_checks import CheckBlocked
spec=importlib.util.spec_from_file_location('plan',pathlib.Path(__file__).with_name('execute-history-plan.py'))
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
class PlanSafety(unittest.TestCase):
    def test_unapproved_plan_never_locates_data_or_prompts(self):
        policy={'history_capture':{'approved_readiness_sha256':'a'*64,'production_extraction_authorized':False},'history_plan':{'execution_authorized':False}}
        with mock.patch.object(p.pathlib.Path,'read_text',return_value=json.dumps(policy)),mock.patch.object(p,'approved_receipt')as receipt,mock.patch.object(p.s,'main')as stage,contextlib.redirect_stdout(io.StringIO())as out:
            self.assertEqual(p.execute('a'*64),78)
        receipt.assert_not_called();stage.assert_not_called();self.assertEqual(json.loads(out.getvalue())['reason'],'HISTORY_PLAN_AWAITS_EXPLICIT_EXECUTION_APPROVAL')
    def test_unreviewed_sha_never_reads_receipt(self):
        with mock.patch.object(p.pathlib.Path,'read_text',return_value='{}'),mock.patch.object(p,'approved_receipt')as receipt,contextlib.redirect_stdout(io.StringIO()):self.assertEqual(p.execute('b'*64),78)
        receipt.assert_not_called()
    def test_cpu_pressure_aborts_after_five_samples(self):
        g=SourcePressureGuard();g.observe({'total':100,'idle':50,'memory_available':4000000000})
        for i in range(1,5):g.observe({'total':100+i*100,'idle':50,'memory_available':4000000000})
        with self.assertRaises(CheckBlocked)as e:g.observe({'total':600,'idle':50,'memory_available':4000000000})
        self.assertEqual(e.exception.code,'HISTORY_SOURCE_CPU_PRESSURE')
    def test_memory_pressure_aborts_immediately(self):
        with self.assertRaises(CheckBlocked):SourcePressureGuard().observe({'total':100,'idle':50,'memory_available':100})
    def test_idle_workload_proof_does_not_claim_hard_mysql_limits(self):
        g=SourcePressureGuard();g.observe({'total':100,'idle':50,'memory_available':4000000000});g.observe({'total':200,'idle':140,'memory_available':4000000000})
        self.assertAlmostEqual(g.peak,10);self.assertIn('no hard cap',g.proof()['scope'])
if __name__=='__main__':unittest.main()
