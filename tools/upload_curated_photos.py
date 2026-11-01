#!/usr/bin/env python3
"""Upload reviewed photo assets without removing or overwriting old originals."""
import argparse
import hashlib
from pathlib import Path
import re
import secrets
import stat

ROOT='/home/parfumplace/web/parfumplace.ru/public_html/pict'


def reviewed_assets(root):
    from PIL import Image
    assets=[]
    for file in sorted(root.glob('*/*.jpg')):
        if not re.fullmatch(r'[1-9]\d*',file.parent.name) or not re.fullmatch(r'[1-9]\d*-[a-f0-9]{16}\.jpg',file.name):
            raise ValueError('Invalid reviewed asset path')
        data=file.read_bytes()
        if len(data)>25*1024*1024 or hashlib.sha256(data).hexdigest()[:16]!=file.stem.split('-',1)[1]:
            raise ValueError('Reviewed asset checksum mismatch')
        with Image.open(file) as image:
            image.verify()
            if image.format!='JPEG':raise ValueError('Reviewed asset is not JPEG')
        assets.append(file)
    return assets


def upload(key,known_hosts):
    import paramiko
    assets=reviewed_assets(Path(__file__).resolve().parents[1]/'resources/catalog/photos')
    if not assets:
        print('No new reviewed photo assets.');return
    client=paramiko.SSHClient();client.load_host_keys(known_hosts);client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect('212.113.99.234',port=22,username='parfumplace',key_filename=key,look_for_keys=False,allow_agent=False,timeout=20)
    uploaded=0
    try:
        with client.open_sftp() as sftp:
            for file in assets:
                folder=ROOT+'/'+file.parent.name
                if not stat.S_ISDIR(sftp.lstat(folder).st_mode) or sftp.normalize(folder)!=folder:
                    raise ValueError('Unexpected existing image folder')
                target=folder+'/'+file.name
                try:attributes=sftp.lstat(target)
                except FileNotFoundError:
                    temporary=folder+'/.photo-upload-'+secrets.token_hex(8)
                    try:
                        sftp.put(str(file),temporary);sftp.chmod(temporary,0o644)
                        sftp.posix_rename(temporary,target)
                    except Exception:
                        try:sftp.remove(temporary)
                        except OSError:pass
                        raise
                    uploaded+=1
                else:
                    if not stat.S_ISREG(attributes.st_mode):raise ValueError('Unexpected photo entry')
                with sftp.open(target,'rb') as handle:
                    actual=handle.read(25*1024*1024+1)
                if hashlib.sha256(actual).digest()!=hashlib.sha256(file.read_bytes()).digest():
                    raise ValueError('Uploaded photo checksum mismatch; refusing publication')
    finally:client.close()
    print(f'Reviewed photos verified: {len(assets)}; new uploads: {uploaded}; original files unchanged')


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('key');parser.add_argument('known_hosts');args=parser.parse_args();upload(args.key,args.known_hosts)
