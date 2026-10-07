#!/usr/bin/env python3
from pathlib import Path
import subprocess
root=Path(__file__).resolve().parents[1]
base=['docker','run','--rm','--network','none','-v',str(root)+':/app:ro','-w','/app/contract','mailchannels-drupal-tests:php83']
for path in sorted((root/'mailchannels_email_api').rglob('*.php')):
    subprocess.run(base+['php','-l','/app/'+str(path.relative_to(root))],check=True)
subprocess.run(base+['composer','validate','--strict','--no-check-publish','--no-check-all'],check=True)
for script,count,sentinel in [('probe.php',19,'No mail()'),('flowed-probe.php',17,'FLOWED_PROBE_COMPLETE'),('headers-probe.php',20,'DRUPAL_HEADERS_COMPLETE 20 checks'),('envelope-probe.php',16,'DRUPAL_ENVELOPE_COMPLETE 16 checks')]:
    result=subprocess.run(base+['php','-d','disable_functions=mail',script],check=True,text=True,capture_output=True)
    print(result.stdout,end='')
    assert sum(line.startswith('PASS ') for line in result.stdout.splitlines())==count,script
    assert sentinel in result.stdout,script
print('DRUPAL_PUBLIC_CHECKS_COMPLETE 72 checks')
