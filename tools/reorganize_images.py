#!/usr/bin/env python3
"""Prepare /pict/<database-id>/<number>-<content-hash>.jpg from public product photos.

No database writes, no deletion of originals, and no filename inventories in logs.
"""
import argparse
import concurrent.futures
import hashlib
from html.parser import HTMLParser
import io
import json
from pathlib import Path
import re
import urllib.parse
import urllib.request

BASE = 'https://parfumplace.ru/'
MAX_BYTES = 25 * 1024 * 1024


class ProductPage(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
        self.images = []
        self.product_id = None
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'a' and 'data-product-link' in attrs:
            self.links.append(attrs['href'])
        if tag == 'h1' and 'data-product-id' in attrs:
            self.product_id = int(attrs['data-product-id'])
        if tag == 'img':
            self.images.append(attrs['src'])


def public_url(path):
    url = urllib.parse.urljoin(BASE, path)
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or parsed.netloc != 'parfumplace.ru':
        raise ValueError('Unexpected remote origin')
    return url


def read(path):
    with urllib.request.urlopen(public_url(path), timeout=30) as response:
        if response.status != 200:
            raise RuntimeError('Unexpected HTTP status')
        data = response.read(MAX_BYTES + 1)
        if len(data) > MAX_BYTES:
            raise ValueError('Image exceeds size limit')
        return data


def page(path):
    parser = ProductPage()
    text = read(path).decode('utf-8')
    parser.feed(text)
    return parser, text


def jpeg_bytes(data):
    from PIL import Image, ImageOps
    Image.MAX_IMAGE_PIXELS = 40_000_000
    with Image.open(io.BytesIO(data)) as image:
        if image.format not in {'JPEG', 'PNG', 'WEBP', 'AVIF'}:
            raise ValueError('Unsupported image format')
        image.load()
        if image.format == 'JPEG':
            return data  # Preserve existing JPEG quality and orientation metadata.
        image = ImageOps.exif_transpose(image).convert('RGBA')
        background = Image.new('RGB', image.size, 'white')
        background.paste(image, mask=image.getchannel('A'))
        output = io.BytesIO()
        background.save(output, format='JPEG', quality=95, optimize=True)
        return output.getvalue()


def filename(number, data):
    return f'{number}-{hashlib.sha256(data).hexdigest()[:16]}.jpg'


def prepare(destination):
    destination.mkdir(parents=True, exist_ok=False)
    first, text = page('catalog.html')
    match = re.search(r'Страница\s+1\s+из\s+(\d+)', text)
    if not match or not 1 <= int(match[1]) <= 1000:
        raise ValueError('Cannot establish catalog page count')
    links = set(first.links)
    for number in range(2, int(match[1]) + 1):
        links.update(page(f'catalog.html?page={number}')[0].links)
    products = []
    commands = ['-mkdir /home/parfumplace/web/parfumplace.ru/public_html/pict']
    complete = destination / 'complete'
    complete.write_text('complete\n')
    total = 0
    for link in sorted(links):
        parsed, _ = page(link)
        if parsed.product_id is None or parsed.product_id <= 0:
            raise ValueError('Product page lacks a valid database ID')
        if not parsed.images:
            continue
        expected = []
        source_images = [src for src in parsed.images if src.startswith('/products_pictures/')]
        if source_images and len(source_images) != len(parsed.images):
            raise ValueError('Mixed source image locations')
        if not source_images:
            if not all(re.fullmatch(r'/pict/' + str(parsed.product_id) + r'/[1-9]\d*-[a-f0-9]{16}\.jpg', src) for src in parsed.images):
                raise ValueError('Unexpected image location')
            products.append({'page': link, 'images': parsed.images})
            continue  # Repeat runs do not re-encode or upload migrated images.
        local_dir = destination / str(parsed.product_id)
        local_dir.mkdir()
        remote = f'/home/parfumplace/web/parfumplace.ru/public_html/pict/{parsed.product_id}'
        commands.append('-mkdir ' + remote)
        for number, src in enumerate(source_images, 1):
            data = jpeg_bytes(read(src))
            name = filename(number, data)
            local = local_dir / name
            local.write_bytes(data)
            commands.append(f'put "{local}" {remote}/{name}')
            expected.append(f'/pict/{parsed.product_id}/{name}')
            total += 1
        # Switch a product to the new folder only after all its uploads finish.
        commands.append(f'put "{complete}" {remote}/.complete')
        products.append({'page': link, 'images': expected})
    if not products:
        raise RuntimeError('No product images found; nothing changed')
    (destination / 'upload.sftp').write_text('\n'.join(commands) + '\n')
    (destination / 'manifest.json').write_text(json.dumps(products))
    print(f'Prepared {total} JPEG files; products with pictures: {len(products)}; products without pictures: {len(links) - len(products)}')


def verify(destination):
    products = json.loads((destination / 'manifest.json').read_text())
    def check(product):
        parsed, _ = page(product['page'])
        if set(parsed.images) != set(product['images']):
            raise RuntimeError('Published product photo paths do not match migration plan')
        for path in product['images']:
            request = urllib.request.Request(public_url(path), method='HEAD')
            with urllib.request.urlopen(request, timeout=30) as response:
                if response.status != 200 or response.headers.get_content_type() != 'image/jpeg':
                    raise RuntimeError('Migrated JPEG is unavailable')
        return len(product['images'])
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        counts = list(pool.map(check, products))
    print(f'Verified {sum(counts)} JPEG files on {len(products)} product pages')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['prepare', 'verify'])
    parser.add_argument('directory', type=Path)
    args = parser.parse_args()
    (prepare if args.mode == 'prepare' else verify)(args.directory.resolve())
