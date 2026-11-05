"""Cross-language regression using the actual existing packet writer, synthetic data only."""
import argparse, hashlib, importlib.util, json, pathlib, subprocess, sys

root = pathlib.Path(__file__).parent
spec = importlib.util.spec_from_file_location('synthetic_packet_tests', root / 'test-prepare-writer-signoff.py')
fixture_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture_module)
profile = fixture_module.fixture()
service = profile['writer_linkage']['service_schedule_candidates'][0]
service['owner'] = None
service['candidate_principals'].append(profile['database_accounts'][-1]['principal'])
service['nested_synthetic_metadata'] = {'z': '1', 'a': 1, 'flag': False}
source_json = json.dumps(profile, ensure_ascii=False, indent=2) + '\n'
source_sha = hashlib.sha256(source_json.encode('utf-8')).hexdigest()
facts, _, _ = fixture_module.m.packet(profile, source_sha)
facts_json = fixture_module.m.canonical(facts).decode('utf-8')
args = argparse.ArgumentParser()
args.add_argument('--php', default='php')
php = args.parse_args().php
reply = subprocess.run([php, str(root / 'test-writer-source-facts.php')],
    input=json.dumps({'source_json': source_json, 'facts_json': facts_json,
        'sorted_source_json': fixture_module.m.canonical(profile).decode('utf-8')}),
    text=True, capture_output=True)
sys.stdout.write(reply.stdout)
sys.stderr.write(reply.stderr)
sys.exit(reply.returncode)
