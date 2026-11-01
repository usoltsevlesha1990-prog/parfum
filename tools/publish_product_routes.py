#!/usr/bin/env python3
"""Publish brand routes and safe Apache rewrites for fragrance .html URLs."""
import argparse
from html.parser import HTMLParser
import posixpath
import re
import stat
import urllib.request
import secrets

ROOT='/home/parfumplace/web/parfumplace.ru/public_html'

class Links(HTMLParser):
    def __init__(self):super().__init__();self.products=[];self.brands=[]
    def handle_starttag(self,tag,attributes):
        attributes=dict(attributes)
        if tag=='a' and 'data-product-link' in attributes:self.products.append(attributes['href'])
        if tag=='a' and 'data-brand-link' in attributes:self.brands.append(attributes['href'])

def catalog_routes():
    routes=set();pages=None;total=None
    for number in range(1,1001):
        with urllib.request.urlopen('https://parfumplace.ru/catalog.html?page='+str(number),timeout=30) as response:
            text=response.read().decode()
        if pages is None:
            count=re.search(r'Страница\s+1\s+из\s+(\d+)',text)
            products=re.search(r'Найдено:\s*(\d+)',text)
            if not count or not products:raise ValueError('Cannot establish live catalog size')
            pages=int(count[1]);total=int(products[1])
            if not 1<=pages<=1000:raise ValueError('Unexpected catalog size')
        parser=Links();parser.feed(text)
        for path in parser.products:
            if not re.fullmatch(r'/[a-z0-9-]+/[a-z0-9-]+\.html',path):raise ValueError('Unexpected product route')
            if path.split('/')[1] in {'pict','products_pictures','private','assets'}:raise ValueError('Reserved route prefix')
            routes.add(path)
        if number==pages:break
    if not routes or len(routes)!=total:raise ValueError('Duplicate or missing canonical product routes')
    return sorted(routes)

def ensure_directory(sftp,path):
    try:attributes=sftp.lstat(path)
    except FileNotFoundError:
        sftp.mkdir(path,mode=0o755);attributes=sftp.lstat(path)
    if not stat.S_ISDIR(attributes.st_mode) or sftp.normalize(path)!=path:raise ValueError('Unexpected route directory or symlink')

def ensure_index(sftp,directory,target):
    entry=posixpath.join(directory,'index.php')
    try:attributes=sftp.lstat(entry)
    except FileNotFoundError:sftp.symlink(target,entry)
    else:
        if not stat.S_ISLNK(attributes.st_mode) or sftp.readlink(entry)!=target:raise ValueError('Existing route entry is not our link; refusing to overwrite')

def install_rewrites(sftp):
    # .html URLs are internally rewritten to PHP; never expose PHP as static HTML.
    entry=ROOT+'/.htaccess'
    try:
        attributes=sftp.lstat(entry)
        if not stat.S_ISREG(attributes.st_mode):raise ValueError('Refusing to replace non-regular .htaccess')
        with sftp.open(entry,'rb') as handle:previous=handle.read(65537)
        if len(previous)>65536:raise ValueError('Unexpectedly large .htaccess')
    except FileNotFoundError:previous=b''
    block=br"""# BEGIN PARFUM PLACE PRODUCT URLS
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([a-z0-9-]+)/([a-z0-9-]+)\.html$ /index.php [END,QSA]
RewriteRule ^(about|delivery|payment|contacts|catalog)\.html$ /index.php [END,QSA]
</IfModule>
# END PARFUM PLACE PRODUCT URLS
"""
    remaining=re.sub(rb'# BEGIN PARFUM PLACE PRODUCT URLS.*?# END PARFUM PLACE PRODUCT URLS\r?\n?',b'',previous,flags=re.S)
    updated=block+remaining
    if updated!=previous:
        if previous:
            backup='/home/parfumplace/web/parfumplace.ru/private/htaccess-before-html-'+secrets.token_hex(8)
            with sftp.open(backup,'wb') as handle:handle.write(previous)
            sftp.chmod(backup,0o600)
        temporary=entry+'.'+secrets.token_hex(8)+'.tmp'
        with sftp.open(temporary,'wb') as handle:handle.write(updated)
        sftp.chmod(temporary,0o644)
        sftp.posix_rename(temporary,entry)

def publish(key,known_hosts):
    import paramiko
    client=paramiko.SSHClient();client.load_host_keys(known_hosts);client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect('212.113.99.234',port=22,username='parfumplace',key_filename=key,look_for_keys=False,allow_agent=False,timeout=20)
    try:
        with client.open_sftp() as sftp:
            if sftp.normalize(ROOT)!=ROOT:raise ValueError('Unexpected public root')
            # Install catalog.html before trying to read the newly separated catalog.
            install_rewrites(sftp)
            routes=catalog_routes()
            with urllib.request.urlopen('https://parfumplace.ru/catalog.html',timeout=30) as response:
                parser=Links();parser.feed(response.read().decode())
            brand_routes=set(parser.brands)
            if not brand_routes or any(not re.fullmatch(r'/[a-z0-9-]+/',path) or path.strip('/') in {'pict','products_pictures','private','assets'} for path in brand_routes):raise ValueError('Unexpected brand routes')
            for route in sorted(brand_routes):
                directory=posixpath.join(ROOT,route.strip('/'))
                ensure_directory(sftp,directory);ensure_index(sftp,directory,'../index.php')
    finally:client.close()
    print(f'Canonical routes published: {len(routes)} product and {len(brand_routes)} brand pages; all share the root index.php')

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('key');p.add_argument('known_hosts');a=p.parse_args();publish(a.key,a.known_hosts)
