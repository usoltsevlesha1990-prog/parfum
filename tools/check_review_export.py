#!/usr/bin/env python3
"""Verify links, product coverage and absence of design/scripts in review export."""
from html.parser import HTMLParser
from pathlib import Path
import sys
import zipfile

root = Path(sys.argv[1] if len(sys.argv) > 1 else 'storage/exports/catalog-review')

class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag in {'script', 'style', 'img', 'form'} or 'style' in attributes:
            raise AssertionError('Unexpected design, script or form')
        if tag == 'link' and attributes.get('rel') == 'stylesheet':
            raise AssertionError('Unexpected stylesheet')
        if tag == 'a':
            self.links.append(attributes['href'])

pages = sorted(root.glob('*.html'))
assert len(pages) == 80, len(pages)
assert len(list(root.glob('product-*.html'))) == 63
checked = 0
for file in pages:
    parser = Page()
    text = file.read_text()
    parser.feed(text)
    assert 'noindex, nofollow' in text
    for link in parser.links:
        assert '/' not in link and (root / link).is_file(), (file.name, link)
        checked += 1
archive = root.parent / 'parfumplace-catalog-review.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
    for file in pages:
        bundle.write(file, 'catalog-review/' + file.name)
with zipfile.ZipFile(archive) as bundle:
    assert len(bundle.namelist()) == 80
    assert bundle.testzip() is None
print(f'PASS: {len(pages)} HTML pages, 63 products, {checked} valid links, no CSS/JS/forms, ZIP integrity OK')
print(archive)
