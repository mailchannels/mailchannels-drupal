"""Synthetic settings only; called inside this run's disposable site container."""
from pathlib import Path
import sys
settings=Path('/app/web/sites/default/settings.php')
backup=Path('/app/.http-settings-backup')
metadata=Path('/app/.http-settings-mode')
mode=sys.argv[1]
if mode=='apply':
    assert not backup.exists(),'Existing settings backup'
    backup.write_bytes(settings.read_bytes())
    metadata.write_text(str(settings.stat().st_mode & 0o777))
    settings.chmod(0o600)
    settings.write_bytes(backup.read_bytes()+b"\n$settings['mailchannels_api_key']='http-fixture-dummy-key';\n$settings['mailchannels_allowed_senders']=['sender@example.com'];\n")
    settings.chmod(int(metadata.read_text()))
elif mode=='restore':
    if backup.exists():
        settings.chmod(0o600)
        settings.write_bytes(backup.read_bytes())
        settings.chmod(int(metadata.read_text()))
        assert settings.read_bytes()==backup.read_bytes()
        assert settings.stat().st_mode & 0o777 == int(metadata.read_text())
        backup.unlink();metadata.unlink()
else:raise ValueError('Unsupported fixture operation')
print('SETTINGS_FIXTURE_COMPLETE '+mode)
