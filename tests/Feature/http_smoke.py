#!/usr/bin/env python3
"""Functional checks against a running development server and imported catalog."""
import os
import re
import unittest
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('PARFUM_TEST_URL', 'http://127.0.0.1:8080')


def request(path, method='GET'):
    try:
        response = urllib.request.urlopen(urllib.request.Request(BASE + path, method=method), timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode('utf-8'), response.headers


class CatalogHttpTests(unittest.TestCase):
    def test_catalog_and_pagination(self):
        status, body, headers = request('/')
        self.assertEqual(status, 200)
        self.assertIn('PARFUM', body)
        self.assertEqual(body.count('data-product-link'), 12)
        self.assertIn('Content-Security-Policy', headers)
        _, last, _ = request('/?page=6')
        self.assertEqual(last.count('data-product-link'), 3)
        self.assertNotEqual(body, last)

    def test_product_from_catalog(self):
        _, body, _ = request('/')
        path = re.search(r'data-product-link href="([^"]+)"', body)[1]
        status, product, _ = request(path)
        self.assertEqual(status, 200)
        self.assertIn('Верхние ноты', product)
        self.assertEqual(product.count('data-variant'), 1)
        self.assertNotRegex(product, r'>[ ]*(5|10) мл<')
        self.assertIn('Только целые заводские флаконы', product)
        self.assertIn('₽', product)

    def test_search_and_brand(self):
        status, body, _ = request('/?q=Ombre+Nomade')
        self.assertEqual(status, 200)
        self.assertIn('Ombre Nomade', body)
        self.assertEqual(body.count('data-product-link'), 1)
        _, home, _ = request('/')
        slug = re.search(r'brand=([a-zA-Z0-9_-]+)', home)[1]
        status, filtered, _ = request('/?brand=' + slug)
        self.assertEqual(status, 200)
        self.assertIn('aria-current="page"', filtered)
        self.assertGreater(filtered.count('data-product-link'), 0)

    def test_search_is_literal_and_escaped(self):
        for query in ["' OR 1=1 --", '%', '_', '<script>alert(1)</script>']:
            status, body, _ = request('/?' + urllib.parse.urlencode({'q': query}))
            self.assertEqual(status, 200)
            self.assertIn('Ароматы не найдены', body)
            self.assertNotIn('<script>alert(1)</script>', body)

    def test_invalid_requests(self):
        for path in ['/?q[]=x', '/?brand[]=x', '/?page=-1', '/?page=1000000', '/?q=%FF', '/?brand=%27', '/?q=' + 'x' * 121]:
            self.assertEqual(request(path)[0], 400, path)
        for path in ['/missing', '/product/not-a-product', '/.env', '/storage/imports/catalog.json', '/bootstrap/app.php']:
            self.assertEqual(request(path)[0], 404, path)
        self.assertEqual(request('/', 'POST')[0], 405)
        status, body, _ = request('/', 'HEAD')
        self.assertEqual(status, 200)
        self.assertEqual(body, '')

    def test_no_design_or_checkout(self):
        _, body, _ = request('/')
        self.assertNotIn('<style', body)
        self.assertNotIn('stylesheet', body)
        self.assertNotIn('<script', body)
        self.assertNotIn('<img', body)
        self.assertNotIn('<button type="submit">Купить', body)


if __name__ == '__main__':
    unittest.main()
