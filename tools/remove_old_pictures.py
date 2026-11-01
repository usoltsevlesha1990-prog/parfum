#!/usr/bin/env python3
"""Remove only the explicitly retired products_pictures tree after verified migration."""
import argparse
import json
from pathlib import Path
import posixpath
import re
import stat

ROOT = '/home/parfumplace/web/parfumplace.ru/public_html/products_pictures'


def validate_manifest(path):
    products = json.loads(Path(path).read_text())
    images = []
    for product in products:
        for image in product['images']:
            if not re.fullmatch(r'/pict/[1-9]\d*/[1-9]\d*-[a-f0-9]{16}\.jpg', image):
                raise ValueError('Migration contains a reference to old image storage')
            images.append(image)
    if len(products) < 60 or len(images) < 181:
        raise ValueError('Migration manifest does not cover previously verified photos')


def collect(sftp, directory):
    files, directories = [], []
    pending = [directory]
    while pending:
        current = pending.pop()
        for entry in sftp.listdir_attr(current):
            if entry.filename in {'.', '..'} or '/' in entry.filename or '\x00' in entry.filename:
                raise ValueError('Unexpected remote entry')
            path = posixpath.join(current, entry.filename)
            if not path.startswith(ROOT + '/'):
                raise ValueError('Path outside the retired image directory')
            if stat.S_ISDIR(entry.st_mode):
                directories.append(path); pending.append(path)
            else:
                files.append(path)  # Symlinks are removed, never traversed.
            if len(files) + len(directories) > 20000:
                raise ValueError('Unexpectedly large retired directory; deletion refused')
    return files, sorted(directories, key=lambda p: p.count('/'), reverse=True)


def remove(key, known_hosts, manifest):
    import paramiko
    validate_manifest(manifest)
    client = paramiko.SSHClient()
    client.load_host_keys(known_hosts)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect('212.113.99.234', port=22, username='parfumplace', key_filename=key,
                   look_for_keys=False, allow_agent=False, timeout=20)
    try:
        with client.open_sftp() as sftp:
            try:
                attributes = sftp.lstat(ROOT)
            except FileNotFoundError:
                print('Old image directory is already absent.')
                return
            if not stat.S_ISDIR(attributes.st_mode) or sftp.normalize(ROOT) != ROOT:
                raise ValueError('Refusing deletion of symlink or unexpected directory')
            files, directories = collect(sftp, ROOT)
            for path in files:
                sftp.remove(path)
            for path in directories:
                sftp.rmdir(path)
            sftp.rmdir(ROOT)
            try:
                sftp.lstat(ROOT)
            except FileNotFoundError:
                print(f'Removed retired image directory: {len(files)} files and {len(directories)} nested directories. New pict storage untouched.')
            else:
                raise RuntimeError('Old directory still exists')
    finally:
        client.close()


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('key'); p.add_argument('known_hosts'); p.add_argument('manifest')
    a = p.parse_args()
    remove(a.key, a.known_hosts, a.manifest)
