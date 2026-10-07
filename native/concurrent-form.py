#!/usr/bin/env python3
"""Run only against the existing disposable visibility Drupal fixture."""
from pathlib import Path
import os
import subprocess
import tempfile
import sys

root=Path(__file__).resolve().parents[1]
fixture=root
site=Path(sys.argv[1]).resolve()
assert site.is_relative_to(root/'.native-work') and site.name=='site'
network=sys.argv[2]
run_id=sys.argv[3]+'-concurrent'
assert network.startswith('mcdrupal-') and run_id.startswith('mcdrupal-')

with tempfile.TemporaryDirectory(prefix=run_id) as sync:
    os.chmod(sync,0o777)  # Synthetic mapping/signals only, container UID independent.
    def command(mode):
        return ['docker','run','--rm','--name',run_id+'-'+mode,'--network',network,
                '-v',str(site)+':/app','-v',str(fixture)+':/candidate:ro',
                '-v',sync+':/sync','-w','/app','-e','DRUPAL_CONCURRENT_MODE='+mode,
                os.environ.get('DRUPAL_TEST_IMAGE', 'mailchannels-drupal-tests:php83'),'php','-d','disable_functions=mail',
                'vendor/drush/drush/drush.php','php:script','/candidate/native/concurrent-form-probe.php']
    def verify(mode,result,count):
        text=result.stdout+result.stderr
        print(text,end='',flush=True)
        assert result.returncode==0,(mode,result.returncode)
        assert f'CONCURRENT_FORM_COMPLETE {mode}' in text,mode
        assert sum(line.startswith('PASS ') for line in text.splitlines())==count,mode
    first=None
    try:
        verify('setup',subprocess.run(command('setup'),capture_output=True,text=True,timeout=45),1)
        first=subprocess.Popen(command('first'),stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        second=subprocess.run(command('second'),capture_output=True,text=True,timeout=45)
        out,err=first.communicate(timeout=45)
        verify('first',subprocess.CompletedProcess([],first.returncode,out,err),3)
        verify('second',second,9)
    finally:
        # Terminate only containers unique to this test before restoring mapping.
        for mode in ('setup','first','second'):
            subprocess.run(['docker','rm','-f',run_id+'-'+mode],capture_output=True)
        if first is not None and first.poll() is None:
            first.communicate(timeout=10)
        if (Path(sync)/'original.json').exists():
            verify('cleanup',subprocess.run(command('cleanup'),capture_output=True,text=True,timeout=45),2)
print('CONCURRENT_RUN_COMPLETE 15 checks')
