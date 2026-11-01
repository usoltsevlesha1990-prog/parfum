#!/usr/bin/env python3
"""Build one live-MySQL PHP file; no catalog data or credentials embedded."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
backend = (root/'bootstrap/live_single.php').read_text()
images = (root/'bootstrap/product_images.php').read_text().split('declare(strict_types=1);', 1)[1]
output = backend.rsplit('?>', 1)[0] + images + '\n?>\n' + (root/'resources/views/live_single.php').read_text()
import json
selection=json.loads((root/'resources/catalog/image-selection.json').read_text())
output=output.replace('__PHOTO_SELECTION__',json.dumps(selection,ensure_ascii=False,separators=(',',':')))
output=output.replace('__STYLE__',(root/'resources/styles/store.css').read_text())
assert 'CATALOG_SNAPSHOT' not in output
(root/'review-site/index.php').write_text(output)
print('Built single live-MySQL index.php (no snapshot, no credentials)')
