#!/usr/bin/env python3
import html
import base64
import tempfile
import os
from pathlib import Path
import re
import socket
import subprocess
import time
import unittest
import urllib.request
import urllib.error
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[2]

class SinglePhpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with socket.socket() as s:
            s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
        cls.base=f'http://127.0.0.1:{port}/'
        cls.pict=tempfile.TemporaryDirectory(prefix='test-pict-',dir=ROOT/'review-site')
        cls.images=tempfile.TemporaryDirectory(prefix='test-images-',dir=ROOT/'review-site')
        folder=Path(cls.images.name)/'louis-vuitton-ombre-nomade'
        folder.mkdir()
        png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=')
        (folder/'1.png').write_bytes(png)
        (folder/'fake.jpg').write_text('not an image')
        (Path(cls.images.name)/'louis-vuitton-ombre-nomade-parfum.png').write_bytes(png)
        (Path(cls.images.name)/'louis-vuitton-imagination.png').write_bytes(png)
        (folder/'link.png').symlink_to('/etc/passwd')
        server_env=dict(os.environ,PARFUM_IMAGE_DIRECTORY=Path(cls.images.name).name,PARFUM_PICT_DIRECTORY=Path(cls.pict.name).name)
        cls.server=subprocess.Popen([os.environ.get('PHP_BIN','php'),'-S',f'127.0.0.1:{port}','-t',str(ROOT/'review-site')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=server_env)
        try:
            for _ in range(40):
                try:
                    with urllib.request.urlopen(cls.base+'index.php',timeout=1) as response:
                        if response.status==200: return
                except OSError: time.sleep(.1)
            raise RuntimeError('PHP server did not start')
        except Exception:
            cls.server.terminate();cls.server.wait();raise
    @classmethod
    def tearDownClass(cls): cls.server.terminate();cls.server.wait();cls.images.cleanup();cls.pict.cleanup()
    def get(self,path='index.php'):
        try: r=urllib.request.urlopen(self.base+path,timeout=5)
        except urllib.error.HTTPError as error:r=error
        with r:return r.status,r.read().decode()
    def test_catalog(self):
        status,body=self.get();self.assertEqual(status,200);self.assertEqual(body.count('data-product-link'),12)
        self.assertNotIn('<style',body);self.assertNotIn('<script',body);self.assertNotIn('stylesheet',body)
        self.assertEqual(self.get('index.php?page=6')[1].count('data-product-link'),3)
    def test_all_product_links(self):
        found=set()
        for page in range(1,7):
            status,body=self.get(f'index.php?page={page}');self.assertEqual(status,200)
            for link in re.findall('data-product-link href="([^"]+)"',body):
                found.add(link);status,product=self.get(html.unescape(link));self.assertEqual(status,200)
                self.assertIn('Описание',product);self.assertIn('Верхние ноты',product)
                self.assertEqual(product.count('data-variant'),1);self.assertNotRegex(product,r'>(5|10) мл<')
        self.assertEqual(len(found),63)
    def test_search(self):
        self.assertEqual(self.get('index.php?q=Ombre+Nomade')[1].count('data-product-link'),1)
        for query in ["' OR 1=1", '<script>alert(1)</script>', '%']:
            status,body=self.get('index.php?'+urlencode({'q':query}));self.assertEqual(status,200)
            self.assertIn('Ароматы не найдены',body);self.assertNotIn('<script>',body)
    def test_brands(self):
        _,body=self.get()
        links=re.findall('href="(index.php\\?brand=[a-zA-Z0-9_-]+)"',body)
        self.assertEqual(len(links),9)
        for link in links:self.assertGreater(self.get(html.unescape(link))[1].count('data-product-link'),0)
    def test_migrated_product_images(self):
        import hashlib,json
        products=json.loads((ROOT/'tests/fixtures/catalog.json').read_text())['products']
        product=next(p for p in products if p['sku']=='louis-vuitton-ombre-nomade')
        folder=Path(self.pict.name)/str(product['id']);folder.mkdir()
        data=(ROOT/'tests/fixtures/pixel.jpg').read_bytes()
        name='1-'+hashlib.sha256(data).hexdigest()[:16]+'.jpg'
        photo=folder/name;photo.write_bytes(data)
        marker=folder/'.complete'
        try:
            _,body=self.get('index.php?sku='+product['sku'])
            self.assertIn('/louis-vuitton-ombre-nomade/1.png',body)
            marker.write_text('complete')
            _,body=self.get('index.php?sku='+product['sku'])
            self.assertIn('/'+Path(self.pict.name).name+'/'+str(product['id'])+'/'+name,body)
            self.assertNotIn('/louis-vuitton-ombre-nomade/1.png',body)
            self.assertEqual(body.count('<img '),1)
        finally:
            marker.unlink(missing_ok=True);photo.unlink();folder.rmdir()

    def test_product_images(self):
        status,body=self.get('index.php?sku=louis-vuitton-ombre-nomade')
        self.assertEqual(status,200)
        sources=re.findall(r'<img src="([^"]+)"',body)
        self.assertEqual(len(sources),1)
        self.assertTrue(sources[0].endswith('/louis-vuitton-ombre-nomade/1.png'))
        with urllib.request.urlopen(self.base+sources[0].lstrip('/'),timeout=5) as response:
            self.assertEqual(response.status,200)
            self.assertEqual(response.headers.get_content_type(),'image/png')
        self.assertNotIn('fake.jpg',body)
        self.assertNotIn('link.png',body)
        self.assertNotIn('ombre-nomade-parfum.png',body)
        status,body=self.get('index.php?sku=louis-vuitton-imagination')
        self.assertIn('/louis-vuitton-imagination.png',body)
        self.assertNotIn('/louis-vuitton-ombre-nomade/1.png',body)

    def test_invalid(self):
        for q in ['q[]=x','page=-1','sku[]=x','q=%FF']:
            self.assertEqual(self.get('index.php?'+q)[0],400)
        self.assertEqual(self.get('index.php?sku=missing')[0],404)

if __name__=='__main__':unittest.main()
