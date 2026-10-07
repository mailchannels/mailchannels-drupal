#!/usr/bin/env python3
"""Fresh disposable Drupal/MariaDB fixture. Never targets an existing site."""
from pathlib import Path
import shutil
import subprocess
import time
import uuid
root=Path(__file__).resolve().parents[1]
prefix='mcdrupal-'+uuid.uuid4().hex[:10]
work=root/'.native-work'/prefix
site=work/'site'
site.mkdir(parents=True)
network=prefix+'-net'
database=prefix+'-db'
logs=[]
created_network=False
checks=0
workers=[]

def command(args,timeout=120):
    if args[:2]==['docker','run'] and '--name' not in args:
        worker=prefix+'-worker-'+str(len(workers))
        workers.append(worker)
        args=args[:2]+['--name',worker]+args[2:]
    result=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
    text=result.stdout+result.stderr
    logs.append(text)
    if result.returncode:raise RuntimeError(text)
    return text

def php(*args):
    return command(['docker','run','--rm','--network',network,
        '-v',str(site)+':/app','-v',str(root)+':/candidate:ro','-w','/app',
        'mailchannels-drupal-tests:php83','php','-d','disable_functions=mail',
        'vendor/drush/drush/drush.php',*args])
try:
    for name in ['composer.json','composer.lock']:shutil.copy2(root/'native'/name,site/name)
    print('Installing locked Drupal dependencies',flush=True)
    command(['docker','run','--rm','-v',str(site)+':/app','-w','/app',
        '-e','COMPOSER_ALLOW_SUPERUSER=1','mailchannels-drupal-tests:php83',
        'composer','install','--no-interaction','--prefer-dist','--no-progress'],timeout=300)
    # Container-owned scaffold directories also work under rootful CI Docker.
    copy_code="from pathlib import Path; import shutil; d=Path('/app/web/modules/custom'); d.mkdir(parents=True,exist_ok=True); shutil.copytree('/candidate/mailchannels_email_api',d/'mailchannels_email_api'); shutil.copytree('/candidate/native/probe_module',d/'visibility_probe')"
    command(['docker','run','--rm','--network','none','-v',str(site)+':/app',
        '-v',str(root)+':/candidate:ro','python:3.12-slim','python','-c',copy_code])
    command(['docker','network','create','--internal',network]);created_network=True
    command(['docker','run','-d','--name',database,'--network',network,
        '-e','MARIADB_ROOT_PASSWORD=isolated-root-only','-e','MARIADB_DATABASE=drupal',
        '-e','MARIADB_USER=drupal','-e','MARIADB_PASSWORD=isolated-db-only','mariadb:11.8.9'])
    deadline=time.monotonic()+60
    while subprocess.run(['docker','exec',database,'healthcheck.sh','--connect','--innodb_initialized'],capture_output=True).returncode:
        if time.monotonic()>deadline:raise RuntimeError('Database readiness timeout')
        time.sleep(.5)
    print('Installing disposable Drupal site on internal network',flush=True)
    php('site:install','minimal','--db-url=mysql://drupal:isolated-db-only@'+database+'/drupal',
        '--account-name=fixture-admin','--account-pass=isolated-admin-only',
        '--account-mail=admin@example.com','--site-mail=sender@example.com','--site-name=Isolated fixture','-y')
    php('en','visibility_probe','contact','mailchannels_email_api','-y')
    for script,count,sentinel in [
        ('probe.php',16,'NATIVE_PROBE_COMPLETE'),
        ('transport-probe.php',26,'TRANSPORT_PROBE_COMPLETE'),
        ('workflow-transport-probe.php',27,'WORKFLOW_TRANSPORT_COMPLETE'),
        ('config-form-probe.php',20,'CONFIG_FORM_PROBE_COMPLETE'),
        ('lifecycle-probe.php',18,'LIFECYCLE_PROBE_COMPLETE'),
        ('config-import-probe.php',14,'CONFIG_IMPORT_PROBE_COMPLETE 14 checks')]:
        text=php('php:script','/candidate/native/'+script)
        assert sentinel in text,(script,'missing completion',text)
        assert sum(line.startswith('PASS ') for line in text.splitlines())==count,(script,text)
        checks+=count
        print(f'{script}: {count} checks passed',flush=True)
    assert checks==121
    print('DRUPAL_NATIVE_COMPLETE 121 checks',flush=True)
    logs.append('DRUPAL_NATIVE_COMPLETE 121 checks')
finally:
    cleanup_errors=[]
    for container in [*reversed(workers),database]:
        exists=subprocess.run(['docker','container','inspect',container],capture_output=True).returncode==0
        if exists and subprocess.run(['docker','rm','-f',container],capture_output=True).returncode:cleanup_errors.append(container)
    if created_network and subprocess.run(['docker','network','rm',network],capture_output=True).returncode:cleanup_errors.append(network)
    cleanup_code="from pathlib import Path; import shutil; [(shutil.rmtree(p) if p.is_dir() and not p.is_symlink() else p.unlink()) for p in Path('/fixture').iterdir()]"
    if subprocess.run(['docker','run','--rm','--network','none','-v',str(site)+':/fixture','python:3.12-slim','python','-c',cleanup_code],capture_output=True).returncode:cleanup_errors.append(str(site))
    else:site.rmdir()
    logs.append('DRUPAL_NATIVE_CLEANUP_COMPLETE' if not cleanup_errors else 'CLEANUP_FAILED '+repr(cleanup_errors))
    (work/'results.txt').write_text('\n'.join(logs))
    if cleanup_errors:raise RuntimeError('Fixture cleanup failed: '+repr(cleanup_errors))
    print('DRUPAL_NATIVE_CLEANUP_COMPLETE',flush=True)
