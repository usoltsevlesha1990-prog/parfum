#!/usr/bin/env python3
"""Run the authorized catalog price rounding through a temporary authenticated worker.

Worker is POST-only, token-protected, expires in 10 minutes and is removed in finally.
Credentials and backups remain on the server; logs contain counts only.
"""
import argparse
import hashlib
import io
import json
from pathlib import Path
import secrets
import time
import urllib.request


def run(key, known_hosts):
    import paramiko
    root = Path(__file__).resolve().parents[1]
    token = secrets.token_hex(32)
    name = '_catalog-prices-' + secrets.token_hex(16) + '.php'
    code = '''<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > EXPIRES || !hash_equals('TOKEN_HASH', hash('sha256', $_SERVER['HTTP_X_CATALOG_MAINTENANCE_TOKEN'] ?? ''))) { http_response_code(404); exit; }
$private=dirname(__DIR__).'/private';
$lock=fopen($private.'/LOCK_NAME','x');
if ($lock===false) { http_response_code(409); exit; }
chmod($private.'/LOCK_NAME',0600);fclose($lock);
try {
    $c=require $private.'/database.php';
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$c['name'])) { throw new RuntimeException('Invalid database config'); }
    $db=new PDO('mysql:host='.$c['host'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $result=roundCatalogPricesDown($db,$private);
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch(Throwable $error) { http_response_code(500); error_log('Catalog price rounding failed: '.get_class($error)); echo '{"error":"Catalog price rounding failed; no credentials or text logged"}'; }
'''
    # Function declarations are loaded before the request handler runs.
    functions = (root / 'tools/round_catalog_prices.php').read_text().split('declare(strict_types=1);', 1)[1]
    code = code.replace('EXPIRES', str(int(time.time()) + 600)).replace('TOKEN_HASH', hashlib.sha256(token.encode()).hexdigest()).replace('LOCK_NAME', name + '.lock') + functions
    remote = '/home/parfumplace/web/parfumplace.ru/public_html/' + name
    client = paramiko.SSHClient()
    client.load_host_keys(known_hosts)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect('212.113.99.234', port=22, username='parfumplace', key_filename=key, look_for_keys=False, allow_agent=False, timeout=20)
    try:
        with client.open_sftp() as sftp:
            uploaded = False
            try:
                sftp.putfo(io.BytesIO(code.encode()), remote)
                uploaded = True
                sftp.chmod(remote, 0o600)
                request = urllib.request.Request('https://parfumplace.ru/' + name, data=b'', method='POST', headers={'X-Catalog-Maintenance-Token': token})
                with urllib.request.urlopen(request, timeout=90) as response:
                    result = json.load(response)
                if not result.get('verified') or not result.get('backup_saved'):
                    raise RuntimeError('Missing cleanup or backup verification')
                variants = int(result['variants']); regular = int(result['regular_prices_changed']); promo = int(result['promo_prices_changed'])
                print(f'::notice title=Catalog price rounding::Rounding: down to 10 rubles; variants: {variants}; regular prices changed: {regular}; promo prices changed: {promo}; backup saved; exact-decimal verification passed; already applied: {bool(result.get("already_applied",False))}.')
            finally:
                if uploaded:
                    sftp.remove(remote)
    finally:
        client.close()


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('key');parser.add_argument('known_hosts')
    args=parser.parse_args();run(args.key,args.known_hosts)
