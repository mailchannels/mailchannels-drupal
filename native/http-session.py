#!/usr/bin/env python3
"""HTTP session lifecycle checks using only the existing disposable fixture."""
from pathlib import Path
import os
import sys
import subprocess
import tempfile
import time
root=Path(__file__).resolve().parents[1]
fixture=root
site=Path(sys.argv[1]).resolve()
assert site.is_relative_to(root/'.native-work') and site.name=='site'
network=sys.argv[2]
name=sys.argv[3]+'-http'
assert network.startswith('mcdrupal-') and name.startswith('mcdrupal-')
base=['docker','run','--rm','--network',network,'-v',str(site)+':/app','-v',str(fixture)+':/candidate:ro','-w','/app']
def drush(script,env=None,sentinel=None):
    cmd=base[:3]+['--name',name+'-control']+base[3:]+sum((['-e',k+'='+v] for k,v in (env or {}).items()),[])+['mailchannels-drupal-tests:php83','php','-d','disable_functions=mail','vendor/drush/drush/drush.php','php:script','/candidate/native/'+script]
    r=subprocess.run(cmd,capture_output=True,text=True,timeout=60)
    text=r.stdout+r.stderr
    assert r.returncode==0 and sentinel in text,text
    print(text,end='',flush=True)
def fixture_settings(mode):
    r=subprocess.run(['docker','run','--rm','--name',name+'-settings','--network','none','-v',str(site)+':/app',
        '-v',str(root)+':/candidate:ro','python:3.12-slim','python',
        '/candidate/native/settings-fixture.py',mode],capture_output=True,text=True,timeout=30)
    assert r.returncode==0 and 'SETTINGS_FIXTURE_COMPLETE '+mode in r.stdout,r.stdout+r.stderr
client=None
setup=False
try:
    drush('http-form-setup.php',sentinel='HTTP_FIXTURE_READY');setup=True
    fixture_settings('apply')
    subprocess.run(base[:3]+['-d','--name',name]+base[3:-2]+['-w','/app/web','mailchannels-drupal-tests:php83','php','-d','disable_functions=mail','-S','0.0.0.0:18378','.ht.router.php'],check=True,capture_output=True)
    with tempfile.TemporaryDirectory(prefix=name) as syncdir:
        os.chmod(syncdir,0o777)
        client=subprocess.Popen(['docker','run','--rm','--name',name+'-client','--network',network,'-v',str(fixture)+':/candidate:ro','-v',syncdir+':/sync','-e','DRUPAL_SESSION_SYNC=/sync','-e','DRUPAL_FIXTURE_ORIGIN=http://'+name+':18378','python:3.12-slim','python','/candidate/native/http-form-probe.py'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        for mode in ['revoke','restore','delete','verify']:
            deadline=time.monotonic()+45
            while not (Path(syncdir)/(mode+'.ready')).exists():
                if client.poll() is not None:
                    out,err=client.communicate();raise RuntimeError(out+err)
                if time.monotonic()>deadline:raise RuntimeError('Client synchronization timeout')
                time.sleep(.05)
            drush('session-control.php',{'DRUPAL_SESSION_CONTROL':mode},'SESSION_CONTROL_COMPLETE '+mode)
            (Path(syncdir)/(mode+'.done')).touch()
        out,err=client.communicate(timeout=30)
        print(out+err,end='',flush=True)
        assert client.returncode==0 and 'HTTP_FORM_PROBE_COMPLETE 25 checks' in out
        assert sum(line.startswith('PASS ') for line in out.splitlines())==25
finally:
    for container in [name+'-client',name+'-control',name+'-settings',name]:subprocess.run(['docker','rm','-f',container],capture_output=True)
    if client is not None and client.poll() is None:client.communicate(timeout=10)
    fixture_settings('restore')
    if setup:drush('http-form-setup.php',{'DRUPAL_FIXTURE_CLEANUP':'1'},'HTTP_FIXTURE_CLEANED')
print('HTTP_SESSION_RUN_COMPLETE 25 checks; settings restored')
