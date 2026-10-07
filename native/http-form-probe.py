"""HTTP form checks against disposable loopback Drupal; never prints tokens/cookies."""
import os
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError
from urllib.parse import urlencode

ORIGIN = os.environ.get('DRUPAL_FIXTURE_ORIGIN', 'http://127.0.0.1:18378')
PATH = '/admin/config/system/mailchannels-email-api'
class Forms(HTMLParser):
    def __init__(self, text):
        super().__init__(); self.forms=[]; self.current=None; self.select=None; self.feed(text)
    def handle_starttag(self, tag, attrs):
        a=dict(attrs)
        if tag=='form': self.current={}; self.forms.append(self.current)
        if self.current is None:return
        if tag=='input' and a.get('name'): self.current[a['name']]=a.get('value','')
        if tag=='select': self.select=a.get('name')
        if tag=='option' and self.select and ('selected' in a or self.select not in self.current): self.current[self.select]=a.get('value','')
    def handle_endtag(self, tag):
        if tag=='select':self.select=None
        if tag=='form':self.current=None
    def named(self, form_id):
        return next(f for f in self.forms if f.get('form_id')==form_id)
class Session:
    def __init__(self):self.http=build_opener(HTTPCookieProcessor(CookieJar()))
    def request(self,path,data=None):
        try:
            with self.http.open(Request(ORIGIN+path, data=urlencode(data).encode() if data is not None else None),timeout=15) as response:
                self.last_url=response.geturl(); return response.status,response.read().decode()
        except HTTPError as error:return error.code,error.read().decode()
    def login(self,name):
        code,html=self.request('/user/login');assert code==200
        fields=Forms(html).named('user_login_form');fields['name']=name;fields['pass']='Local-Http-Fixture-12345!'
        code,html=self.request('/user/login',fields);assert code==200 and self.last_url.startswith(ORIGIN+'/user/') and not self.last_url.startswith(ORIGIN+'/user/login'),'Login failed'
    def form(self):
        code,html=self.request(PATH);assert code==200
        assert 'http-fixture-dummy-key' not in html
        return Forms(html).named('mailchannels_email_api_settings')
checks=0
def check(condition,label):
    global checks
    assert condition,label
    checks+=1;print('PASS '+label)
anonymous=Session();check(anonymous.request(PATH)[0]==403,'anonymous GET denied')
ordinary=Session();ordinary.login('http-ordinary');check(ordinary.request(PATH)[0]==403,'ordinary authenticated GET denied')
admin=Session();admin.login('http-authorized');fields=admin.form();check(fields['backend']=='php_mail','authorized GET shows original default')
check(bool(fields.get('form_token')) and bool(fields.get('mapping_snapshot')),'HTTP form includes CSRF and signed snapshot')
for session,name in [(anonymous,'anonymous'),(ordinary,'ordinary')]:
    attempted=dict(fields,backend='mailchannels_email_api')
    check(session.request(PATH,attempted)[0]==403 and admin.form()['backend']=='php_mail',name+' POST denied without routing change')
for mode in ['missing','invalid']:
    fields=admin.form();fields['backend']='mailchannels_email_api'
    if mode=='missing':fields.pop('form_token')
    else:fields['form_token']='invalid-fixture-token'
    code,html=admin.request(PATH,fields)
    check(admin.form()['backend']=='php_mail',mode+' CSRF does not mutate routing')
    check('outdated' in html.lower() or code in [400,403],mode+' CSRF produces explicit rejection')
fields=admin.form();fields['mapping_snapshot']='tampered';fields['backend']='mailchannels_email_api'
code,html=admin.request(PATH,fields)
check('changed since' in html and admin.form()['backend']=='php_mail','tampered signed snapshot rejected')
fields=admin.form();fields['backend']='mailchannels_email_api';code,html=admin.request(PATH,fields)
check(code==200 and admin.form()['backend']=='mailchannels_email_api','valid authorized POST selects candidate')
stale=admin.form()
other=Session();other.login('http-authorized');fresh=other.form();fresh['backend']='php_mail';other.request(PATH,fresh)
check(other.form()['backend']=='php_mail','second session changes default explicitly')
stale['backend']='mailchannels_email_api';code,html=admin.request(PATH,stale)
check('changed since' in html and admin.form()['backend']=='php_mail','stale first-session POST does not overwrite second session')
check('http-fixture-dummy-key' not in html,'POST response does not expose key')
saved_before_logout=admin.form();saved_before_logout['backend']='mailchannels_email_api'
code,html=admin.request('/user/logout/confirm')
check(code==200,'native logout confirmation accessible')
logout=Forms(html).named('user_logout_confirm')
admin.request('/user/logout/confirm',logout)
check(admin.request(PATH)[0]==403,'logged-out session cannot load settings')
check(admin.request(PATH,saved_before_logout)[0]==403,'previously loaded form rejected after logout')
check(other.form()['backend']=='php_mail','independent authenticated session confirms logout POST preserved routing')
if os.environ.get('DRUPAL_SESSION_SYNC'):
    from pathlib import Path
    import time
    sync=Path(os.environ['DRUPAL_SESSION_SYNC'])
    def control(mode):
        (sync/(mode+'.ready')).touch()
        deadline=time.monotonic()+30
        while not (sync/(mode+'.done')).exists():
            assert time.monotonic()<deadline,'Session-control fixture timeout'
            time.sleep(.05)
    held=other.form();held['backend']='mailchannels_email_api'
    control('revoke')
    check(other.request(PATH)[0]==403,'permission revocation denies existing-session GET')
    check(other.request(PATH,held)[0]==403,'permission revocation denies preloaded form POST')
    control('restore')
    check(other.form()['backend']=='php_mail','restored permission reveals unchanged routing')
    held=other.form();held['backend']='mailchannels_email_api'
    control('delete')
    check(other.request(PATH)[0]==403,'server-side session deletion denies old-cookie GET')
    check(other.request(PATH,held)[0]==403,'server-side session deletion denies preloaded form POST')
    replacement=Session();replacement.login('http-authorized')
    check(replacement.form()['backend']=='php_mail','fresh login confirms unchanged routing after session deletion')
    control('verify')
print(f'HTTP_FORM_PROBE_COMPLETE {checks} checks')
