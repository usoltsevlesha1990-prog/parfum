#!/usr/bin/env python3
"""Build one live-MySQL PHP file; no catalog data or credentials embedded."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
output = (root/'bootstrap/live_single.php').read_text() + (root/'resources/views/live_single.php').read_text()
assert 'CATALOG_SNAPSHOT' not in output
(root/'review-site/index.php').write_text(output)
print('Built single live-MySQL index.php (no snapshot, no credentials)')
