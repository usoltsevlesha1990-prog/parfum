#!/usr/bin/env python3
"""Publish same-owner index.php symlinks for brand/fragrance URLs (no server rewrite needed)."""
import argparse
from html.parser import HTMLParser
import posixpath
import re
import stat
import urllib.request

ROOT='/home/parfumplace/web/parfumplace.ru/public_html'

class Links(HTMLParser):
    def __init__(self):super().__init__();self.products=[]
    def handle_starttag(self,tag,attributes):
        attributes=dict(attributes)
        if tag=='a' and 'data-product-link' in attributes:self.products.append(attributes['href'])

def catalog_routes():
    routes=set();pages=None;total=None
    for number in range(1,1001):
        with urllib.request.urlopen('https://parfumplace.ru/index.php?page='+str(number),timeout=30) as response:
            text=response.read().decode()
        if pages is None:
            count=re.search(r'Страница\s+1\s+из\s+(\d+)',text)
            products=re.search(r'Найдено:\s*(\d+)',text)
            if not count or not products:raise ValueError('Cannot establish live catalog size')
            pages=int(count[1]);total=int(products[1])
            if not 1<=pages<=1000:raise ValueError('Unexpected catalog size')
        parser=Links();parser.feed(text)
        for path in parser.products:
            if not re.fullmatch(r'/[a-z0-9-]+/[a-z0-9-]+/',path):raise ValueError('Unexpected product route')
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

def publish(key,known_hosts):
    import paramiko
    routes=catalog_routes()
    client=paramiko.SSHClient();client.load_host_keys(known_hosts);client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect('212.113.99.234',port=22,username='parfumplace',key_filename=key,look_for_keys=False,allow_agent=False,timeout=20)
    try:
        with client.open_sftp() as sftp:
            if sftp.normalize(ROOT)!=ROOT:raise ValueError('Unexpected public root')
            for route in routes:
                brand,name=route.strip('/').split('/')
                parent=posixpath.join(ROOT,brand)
                directory=posixpath.join(parent,name)
                ensure_directory(sftp,parent);ensure_directory(sftp,directory)
                entry=posixpath.join(directory,'index.php')
                try:attributes=sftp.lstat(entry)
                except FileNotFoundError:sftp.symlink('../../index.php',entry)
                else:
                    if not stat.S_ISLNK(attributes.st_mode) or sftp.readlink(entry)!='../../index.php':raise ValueError('Existing route entry is not our link; refusing to overwrite')
    finally:client.close()
    print(f'Canonical routes published: {len(routes)}; all share the root index.php')

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('key');p.add_argument('known_hosts');a=p.parse_args();publish(a.key,a.known_hosts)
