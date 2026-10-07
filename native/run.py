#!/usr/bin/env python3
"""Fresh disposable Drupal/database fixture. Never targets an existing site."""
import os
from pathlib import Path
import shutil
import subprocess
import time
import uuid
import sys
root=Path(__file__).resolve().parents[1]
prefix='mcdrupal-'+uuid.uuid4().hex[:10]
work=root/'.native-work'/prefix
site=work/'site'
site.mkdir(parents=True)
network=prefix+'-net'
database=prefix+'-db'
backend=os.environ.get('DRUPAL_TEST_DATABASE','mariadb')
if backend not in ('mariadb','postgres'):raise ValueError('Unsupported fixture database')
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
        os.environ.get('DRUPAL_TEST_IMAGE', 'mailchannels-drupal-tests:php83'),'php','-d','disable_functions=mail',
        'vendor/drush/drush/drush.php',*args])
try:
    runtime=command(['docker','run','--rm','--network','none',os.environ.get('DRUPAL_TEST_IMAGE', 'mailchannels-drupal-tests:php83'),'php','--version'])
    print(runtime,flush=True)
    for name in ['composer.json','composer.lock']:shutil.copy2(root/'native'/name,site/name)
    print('Installing locked Drupal dependencies',flush=True)
    command(['docker','run','--rm','-v',str(site)+':/app','-w','/app',
        '-e','COMPOSER_ALLOW_SUPERUSER=1',os.environ.get('DRUPAL_TEST_IMAGE', 'mailchannels-drupal-tests:php83'),
        'composer','install','--no-interaction','--prefer-dist','--no-progress'],timeout=300)
    # Container-owned scaffold directories also work under rootful CI Docker.
    copy_code="from pathlib import Path; import shutil; d=Path('/app/web/modules/custom'); d.mkdir(parents=True,exist_ok=True); shutil.copytree('/candidate/mailchannels_email_api',d/'mailchannels_email_api'); shutil.copytree('/candidate/native/probe_module',d/'visibility_probe')"
    command(['docker','run','--rm','--network','none','-v',str(site)+':/app',
        '-v',str(root)+':/candidate:ro','python:3.12-slim','python','-c',copy_code])
    command(['docker','network','create','--internal',network]);created_network=True
    if backend=='postgres':
        command(['docker','run','-d','--name',database,'--network',network,
            '-e','POSTGRES_DB=drupal','-e','POSTGRES_USER=drupal',
            '-e','POSTGRES_PASSWORD=isolated-db-only','postgres:17.11-bookworm'])
        readiness=['pg_isready','-h','127.0.0.1','-U','drupal','-d','drupal']
        driver='pgsql'
    else:
        command(['docker','run','-d','--name',database,'--network',network,
            '-e','MARIADB_ROOT_PASSWORD=isolated-root-only','-e','MARIADB_DATABASE=drupal',
            '-e','MARIADB_USER=drupal','-e','MARIADB_PASSWORD=isolated-db-only','mariadb:11.8.9'])
        readiness=['healthcheck.sh','--connect','--innodb_initialized']
        driver='mysql'
    deadline=time.monotonic()+60
    while subprocess.run(['docker','exec',database,*readiness],capture_output=True).returncode:
        if time.monotonic()>deadline:raise RuntimeError('Database readiness timeout')
        time.sleep(.5)
    if backend=='postgres':
        command(['docker','exec',database,'psql','-U','drupal','-d','drupal','-v','ON_ERROR_STOP=1','-c','CREATE EXTENSION pg_trgm;'])
    print('Installing disposable Drupal site on internal network: '+backend,flush=True)
    php('site:install','minimal','--db-url='+driver+'://drupal:isolated-db-only@'+database+'/drupal',
        '--account-name=fixture-admin','--account-pass=isolated-admin-only',
        '--account-mail=admin@example.com','--site-mail=sender@example.com','--site-name=Isolated fixture','-y')
    actual=php('php:eval','echo json_encode(["driver"=>\\Drupal::database()->driver(),"server"=>\\Drupal::database()->query("SELECT VERSION()")->fetchField()]);')
    assert '"driver":"'+driver+'"' in actual,actual
    print('DATABASE_RUNTIME '+actual.strip(),flush=True)
    private_setup="from pathlib import Path; p=Path('/app/web/sites/default/settings.php'); m=p.stat().st_mode & 0o777; p.chmod(0o600); p.write_bytes(p.read_bytes()+b'\\n$settings[\"file_private_path\"]=\"/app/private\";\\n'); p.chmod(m); Path('/app/private').mkdir()"
    command(['docker','run','--rm','--network','none','-v',str(site)+':/app',
        'python:3.12-slim','python','-c',private_setup])
    php('en','visibility_probe','contact','mailchannels_email_api','-y')
    for script,count,sentinel in [
        ('probe.php',16,'NATIVE_PROBE_COMPLETE'),
        ('transport-probe.php',31,'TRANSPORT_PROBE_COMPLETE'),
        ('workflow-transport-probe.php',27,'WORKFLOW_TRANSPORT_COMPLETE'),
        ('config-form-probe.php',20,'CONFIG_FORM_PROBE_COMPLETE'),
        ('lifecycle-probe.php',18,'LIFECYCLE_PROBE_COMPLETE'),
        ('config-import-probe.php',14,'CONFIG_IMPORT_PROBE_COMPLETE 14 checks'),
        ('attachment-mapper-probe.php',30,'ATTACHMENT_MAPPER_COMPLETE 30 checks')]:
        text=php('php:script','/candidate/native/'+script)
        assert sentinel in text,(script,'missing completion',text)
        assert sum(line.startswith('PASS ') for line in text.splitlines())==count,(script,text)
        checks+=count
        print(f'{script}: {count} checks passed',flush=True)
    assert checks==156
    # Register child servers in outer cleanup as well, including timeout paths.
    workers.extend([prefix+'-http',prefix+'-http-client',prefix+'-http-control',prefix+'-http-settings'])
    text=command([sys.executable,str(root/'native/http-session.py'),str(site),network,prefix],timeout=180)
    assert 'HTTP_SESSION_RUN_COMPLETE 28 checks; settings restored' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==28,text
    print('HTTP session fixture: 28 checks passed',flush=True)
    checks+=28
    workers.extend(prefix+'-concurrent-'+mode for mode in ['setup','first','second','cleanup'])
    text=command([sys.executable,str(root/'native/concurrent-form.py'),str(site),network,prefix],timeout=180)
    assert 'CONCURRENT_RUN_COMPLETE 15 checks' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==15,text
    print('Concurrent form fixture: 15 checks passed',flush=True)
    checks+=15
    assert checks==199
    workers.extend([prefix+'-tls',prefix+'-tls-client'])
    text=command([sys.executable,str(root/'tls/run.py'),str(site),network,prefix],timeout=180)
    assert 'TLS_PROBE_COMPLETE' in text and 'TLS_FIXTURE_CLEANUP_COMPLETE' in text,text
    assert sum(line.startswith('PASS ') for line in text.splitlines())==6,text
    print('TLS fixture: 6 scenarios passed',flush=True)
    print('DRUPAL_NATIVE_COMPLETE 199 checks + 6 TLS scenarios',flush=True)
    logs.append('DRUPAL_NATIVE_COMPLETE 199 checks + 6 TLS scenarios')
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
